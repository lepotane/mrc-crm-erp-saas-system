<?php

namespace App\Integration\Sync;

use App\Integration\Tsoft\TsoftAdapter;
use App\Integration\Tsoft\TsoftMapper;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tsoft -> MRC tek yönlü katalog çekimi (urun / fiyat / stok / musteri).
 *
 * Guvenlik sozlesmesi:
 *  - Tsoft adapter'inda YALNIZCA okuma yontemleri cagrilir; setProducts gibi
 *    hici bir yazma ucuna gidilmez. Tsoft salt okunur kalir.
 *  - Hicbir tablo silinmez; var kayitlar updateOrCreate ile guncellenir.
 *  - Ham satir, ad/e-posta/telefon/sifre hata mesajina veya gunele kopyalanmaz;
 *    bolum bazli hatalar genel mesajlarla summary['errors'] icinde dondurulur.
 *  - Stok icin ParallelStockEngine::applyInbound() kullanilir. Motor
 *    (company_id, external_type='stock_movement', external_key=reference)
 *    eslesmesi varsa ikinci hareketi YAZMAZ (idempotent). Bu servise ozel
 *    'catalog-pull:<sku>:<miktar>' referansi kullanilir: ayni miktar tekrar
 *    cekildiginde cift hareket olusmaz, Tsoft'taki miktar degisirse yeni
 *    hareket yazilir. (MrcResyncService'in 'resync:' on ekinden ayridir.)
 */
final class CatalogPullService
{
    /** Stok hareketlerinin idempotency anahtari (applyInbound 6. arguman). */
    private const STOCK_REFERENCE_PREFIX = 'catalog-pull:';

    /**
     * @return array{products:int,prices:int,stock:int,customers:int,errors:array<int,string>}
     */
    public function pull(int $companyId): array
    {
        $summary = [
            'products' => 0,
            'prices' => 0,
            'stock' => 0,
            'customers' => 0,
            'errors' => [],
        ];

        $adapter = null;
        $rawProducts = [];

        // 1) Ham urun okuma. Adapter sadece okuma amacli cozulur.
        try {
            $adapter = app(TsoftAdapter::class, ['company' => $companyId]);
            if (! is_object($adapter) || ! method_exists($adapter, 'fetchAllProducts')) {
                $summary['errors'][] = 'products: okuma yontemi kullanilamiyor.';
            } else {
                $rows = $adapter->fetchAllProducts();
                $rawProducts = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
            }
        } catch (Throwable) {
            $summary['errors'][] = 'products: okunamadi.';
        }

        // 2) Urunler: MrcResyncService::importProducts() ile bire bir ayni upsert.
        $currency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        foreach ($rawProducts as $index => $raw) {
            try {
                $canonical = TsoftMapper::product($raw);

                if ($canonical->sku === '') {
                    $summary['errors'][] = 'products['.$index.']: SKU bos oldugu icin atlandi.';

                    continue;
                }

                $sale = $this->pick($raw, [
                    'sale_price', 'salePrice', 'SellingPrice', 'price', 'Price', 'attributes.salePrice',
                ], '0');
                $tax = $this->pick($raw, ['tax_rate', 'taxRate', 'Vat', 'vat', 'attributes.taxRate'], '0');
                $rowCurrency = $this->pick($raw, [
                    'currency', 'currency_code', 'currencyCode', 'Currency', 'attributes.currency',
                ], $currency);

                Product::updateOrCreate(
                    ['company_id' => $companyId, 'sku' => $canonical->sku],
                    [
                        'name' => mb_substr($canonical->name !== '' ? $canonical->name : $canonical->sku, 0, 190),
                        'barcode' => $canonical->barcode === null
                            ? null
                            : mb_substr($canonical->barcode, 0, 64),
                        'currency_code' => strtoupper(mb_substr((string) $rowCurrency, 0, 3)),
                        'sale_price' => $this->decimal($sale),
                        'tax_rate' => $this->decimal($tax, 2),
                        'track_inventory' => true,
                    ],
                );

                $summary['products']++;
            } catch (Throwable) {
                $summary['errors'][] = 'products['.$index.']: urun yazilamadi.';
            }
        }

        // 3) Fiyatlar: ayni ham satirlar PriceSyncService::pull()'a verilir.
        if ($rawProducts !== []) {
            try {
                $priceSync = app(PriceSyncService::class);
                if (! is_object($priceSync) || ! method_exists($priceSync, 'pull')) {
                    $summary['errors'][] = 'prices: servis kullanilamiyor.';
                } else {
                    // Tsoft fiyatı bu yönde KAYNAK. Varsayılan field-ownership
                    // mevcut fiyatı "MRC alanı" sayıp atlıyor; force ile güncelle.
                    $result = $priceSync->pull($companyId, $rawProducts, true);
                    $summary['prices'] = is_array($result) ? (int) ($result['upserted'] ?? 0) : 0;
                }
            } catch (Throwable) {
                $summary['errors'][] = 'prices: ice aktarilamadi.';
            }
        }

        // 4) Stoklar: MrcResyncService::importStock() imzasinin aynisi kullanilir.
        try {
            $engine = app(ParallelStockEngine::class);
            if (! is_object($engine) || ! method_exists($engine, 'applyInbound')) {
                $summary['errors'][] = 'stock: motor kullanilamiyor.';
                $engine = null;
            }
            $warehouseId = $this->defaultWarehouseId($companyId);
        } catch (Throwable) {
            $summary['errors'][] = 'stock: motor olusturulamadi.';
            $engine = null;
            $warehouseId = null;
        }

        if (isset($engine) && is_object($engine)) {
            foreach ($rawProducts as $index => $raw) {
                try {
                    $sku = (string) $this->pick($raw, [
                        'sku', 'ProductCode', 'product_code', 'catalogCode', 'attributes.sku',
                    ], '');

                    if ($sku === '') {
                        continue;
                    }

                    $quantity = $this->decimal($this->pick($raw, [
                        'stock', 'Stock', 'quantity', 'available', 'availableStock', 'attributes.quantity',
                    ], '0'));

                    if (bccomp($quantity, '0', 4) <= 0) {
                        continue;
                    }

                    // 6. arguman referans: motor bu anahtarla idempotent davranir.
                    $engine->applyInbound(
                        $companyId,
                        $sku,
                        $quantity,
                        'tsoft',
                        self::STOCK_REFERENCE_PREFIX.$sku.':'.$quantity,
                        $warehouseId,
                    );

                    $summary['stock']++;
                } catch (Throwable) {
                    $summary['errors'][] = 'stock['.$index.']: acilis girisi yazilamadi.';
                }
            }
        }

        // 5) Musteriler: ham rest1 satirlari alici sekline cevrilip OrderBuyerSync'e verilir.
        try {
            if (! is_object($adapter) || ! method_exists($adapter, 'fetchAllCustomers')) {
                $summary['errors'][] = 'customers: okuma yontemi kullanilamiyor.';
            } else {
                $rows = $adapter->fetchAllCustomers();
                $buyers = [];

                foreach (is_array($rows) ? $rows : [] as $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }

                    $companyName = trim((string) ($raw['CompanyName'] ?? ''));
                    $personName = trim(
                        trim((string) ($raw['Name'] ?? '')).' '.trim((string) ($raw['Surname'] ?? ''))
                    );
                    $name = $companyName !== '' ? $companyName : $personName;
                    if ($name === '') {
                        continue;
                    }

                    $taxNumber = trim((string) ($raw['TaxNo'] ?? ''));
                    if ($taxNumber === '') {
                        $taxNumber = trim((string) ($raw['IdentityNo'] ?? ''));
                    }

                    $phone = trim((string) ($raw['Mobile'] ?? ''));
                    if ($phone === '') {
                        $phone = trim((string) ($raw['Phone'] ?? ''));
                    }

                    $addressParts = [];
                    foreach (['Address', 'District', 'Town', 'City'] as $field) {
                        $value = trim((string) ($raw[$field] ?? ''));
                        if ($value !== '') {
                            $addressParts[] = $value;
                        }
                    }

                    $buyers[] = [
                        'id' => trim((string) ($raw['CustomerId'] ?? '')),
                        'code' => trim((string) ($raw['CustomerCode'] ?? '')),
                        'name' => $name,
                        'email' => trim((string) ($raw['Email'] ?? '')),
                        'phone' => $phone,
                        'tax' => $taxNumber,
                        'city' => trim((string) ($raw['City'] ?? '')),
                        'address' => implode(', ', $addressParts),
                    ];
                }

                $buyerSync = app(OrderBuyerSync::class);
                $summary['customers'] = $buyerSync->sync($companyId, $buyers);
                // OrderBuyerSync hata mesajlari da zaten geneldir; kisisel veri tasimaz.
                foreach ($buyerSync->errors() as $message) {
                    $summary['errors'][] = $message;
                }
            }
        } catch (Throwable) {
            $summary['errors'][] = 'customers: okunamadi.';
        }

        return $summary;
    }

    /** @param array<string,mixed> $data @param array<int,string> $keys */
    private function pick(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = $data;

            foreach (explode('.', (string) $key) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;

                    break;
                }
                $value = $value[$segment];
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /** Para ve miktarlar her zaman string decimaldir (float kullanilmaz). */
    private function decimal(mixed $value, int $scale = 4): string
    {
        $raw = str_replace(',', '.', (string) $value);

        return bcadd(is_numeric($raw) ? $raw : '0', '0', $scale);
    }

    /** MrcResyncService::defaultWarehouseId() ile ayni cozum. */
    private function defaultWarehouseId(int $companyId): ?int
    {
        try {
            $id = DB::table('warehouses')
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->value('id');

            return $id === null ? null : (int) $id;
        } catch (Throwable) {
            return null;
        }
    }
}
