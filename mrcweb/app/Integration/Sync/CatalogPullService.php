<?php

namespace App\Integration\Sync;

use App\Integration\Canonical\CanonicalProduct;
use App\Integration\Translate\ProductNameTranslator;
use App\Integration\Tsoft\TsoftAdapter;
use App\Integration\Tsoft\TsoftMapper;
use App\Models\Product;
use App\Models\ProductCategory;
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

        // 1b) Kategoriler TEK kez okunur ve yerel product_categories tablosuna
        // yansitilir (salt okunur Tsoft tarafi; hicbir yazma ucu cagrilmaz).
        $categoryMaps = ['by_name' => [], 'by_code' => []];
        try {
            $categoryMaps = $this->syncCategories($companyId, $this->fetchCategoryRows($companyId, $adapter));
        } catch (Throwable) {
            $summary['errors'][] = 'categories: okunamadi.';
            $categoryMaps = ['by_name' => [], 'by_code' => []];
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

                Product::updateOrCreate(
                    ['company_id' => $companyId, 'sku' => $canonical->sku],
                    $this->productAttributes($companyId, $canonical, $raw, $categoryMaps, $currency),
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

    /**
     * Kategori satırlarını TEK seferde okur (adapter üzerinden salt okunur sürücü).
     * Eski sürücülerde yöntem yoksa veya okuma başarısızsa boş dizi döner.
     * Çağıran adapter'ı zaten çözdüyse tekrar çözümlemeden onu kullanır.
     *
     * @param  object|null  $adapterOverride  pull() içinde zaten çözülmüş adapter
     * @return array<int,array<string,mixed>>
     */
    public function fetchCategoryRows(int $companyId, ?object $adapterOverride = null): array
    {
        try {
            $adapter = $adapterOverride ?? app(TsoftAdapter::class, ['company' => $companyId]);
            if (! is_object($adapter) || ! method_exists($adapter, 'fetchAllCategories')) {
                return [];
            }

            $rows = $adapter->fetchAllCategories();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Tsoft kategori satırlarından yerel kategorileri upsert eder ve
     * "normalize edilmiş ad/kod => yerel id" eşleme haritalarını döndürür.
     *
     * @param  array<int,array<string,mixed>>  $rows  ham /rest1/category/getCategories satırları
     * @return array{by_name:array<string,int>,by_code:array<string,int>}
     */
    public function syncCategories(int $companyId, array $rows): array
    {
        $maps = ['by_name' => [], 'by_code' => []];
        $palette = ['#B9FF66', '#459BBE', '#DC6F31', '#BC5FCF', '#4E956A'];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['CategoryName'] ?? $row['categoryName'] ?? ''));
            if ($name === '') {
                continue;
            }

            try {
                $category = ProductCategory::updateOrCreate(
                    ['company_id' => $companyId, 'name' => mb_substr($name, 0, 120)],
                    // Deterministik renk: aynı kategori her çekimde aynı rengi alır.
                    ['color' => $palette[abs(crc32($name)) % count($palette)]],
                );

                $key = mb_strtolower(trim((string) $category->name), 'UTF-8');
                if ($key !== '') {
                    $maps['by_name'][$key] = (int) $category->id;
                }

                $code = trim((string) ($row['CategoryCode'] ?? $row['categoryCode'] ?? ''));
                if ($code !== '') {
                    $maps['by_code'][mb_strtolower($code, 'UTF-8')] = (int) $category->id;
                }
            } catch (Throwable) {
                // Bozuk satır tüm katalog çekimini bozmasın; genel hata yeterli.
                continue;
            }
        }

        return $maps;
    }

    /**
     * Tek ürün için ortak upsert alanlarını üretir. CatalogPullService ve
     * MrcResyncService aynı veri kalitesi kurallarını kullansın diye ortak nokta.
     *
     * Orijinal İngilizce ad name_en'de saklanır; name alanı best-effort çevrilir.
     * Barkod boşsa üretilir, mevcut dolu barkod asla ezilmez.
     *
     * @param  array{by_name:array<string,int>,by_code:array<string,int>}  $categoryMaps
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public function productAttributes(
        int $companyId,
        CanonicalProduct $canonical,
        array $raw,
        array $categoryMaps,
        string $defaultCurrency,
    ): array {
        $english = (string) $canonical->name;
        $translated = (new ProductNameTranslator)->toTurkish($english);
        $localizedName = trim($translated) !== '' ? $translated : $english;

        $incomingBarcode = trim((string) $this->pick($raw, [
            'Barcode', 'barcode', 'Ean', 'attributes.barcode',
        ], ''));
        if ($incomingBarcode === '' && $canonical->barcode !== null) {
            $incomingBarcode = trim((string) $canonical->barcode);
        }

        if ($incomingBarcode !== '') {
            $barcode = mb_substr($incomingBarcode, 0, 64);
        } else {
            // Tsoft barkodu yoksa yerel, SKU'ya göre deterministik barkod üret.
            // Ürünün zaten dolu bir barkodu varsa onu koru (idempotent veri kalitesi).
            $existing = Product::query()
                ->where('company_id', $companyId)
                ->where('sku', $canonical->sku)
                ->value('barcode');
            $barcode = $existing !== null && trim((string) $existing) !== ''
                ? mb_substr((string) $existing, 0, 64)
                : 'MRC'.strtoupper(substr(md5($canonical->sku), 0, 10));
        }

        $categoryId = null;
        $categoryName = mb_strtolower(trim((string) $this->pick($raw, [
            'DefaultCategoryName', 'defaultCategoryName', 'CategoryName', 'categoryName',
            'attributes.defaultCategoryName',
        ], '')), 'UTF-8');
        $categoryCode = mb_strtolower(trim((string) $this->pick($raw, [
            'DefaultCategoryCode', 'defaultCategoryCode', 'CategoryCode', 'categoryCode',
            'attributes.defaultCategoryCode',
        ], '')), 'UTF-8');

        if ($categoryName !== '' && isset($categoryMaps['by_name'][$categoryName])) {
            $categoryId = $categoryMaps['by_name'][$categoryName];
        } elseif ($categoryCode !== '' && isset($categoryMaps['by_code'][$categoryCode])) {
            $categoryId = $categoryMaps['by_code'][$categoryCode];
        }

        $sale = $this->pick($raw, [
            'sale_price', 'salePrice', 'SellingPrice', 'price', 'Price', 'attributes.salePrice',
        ], '0');
        $tax = $this->pick($raw, ['tax_rate', 'taxRate', 'Vat', 'vat', 'attributes.taxRate'], '0');
        $rowCurrency = $this->pick($raw, [
            'currency', 'currency_code', 'currencyCode', 'Currency', 'attributes.currency',
        ], $defaultCurrency);

        return [
            'name' => mb_substr($localizedName !== '' ? $localizedName : $canonical->sku, 0, 190),
            'name_en' => mb_substr($english, 0, 190),
            'barcode' => $barcode,
            'category_id' => $categoryId,
            'currency_code' => strtoupper(mb_substr((string) $rowCurrency, 0, 3)),
            'sale_price' => $this->decimal($sale),
            'tax_rate' => $this->decimal($tax, 2),
            'track_inventory' => true,
        ];
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
