<?php

namespace App\Integration\Sync;

use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\SyncResult;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Fiyatları MRC <-> Tsoft arasında taşır; para her zaman string decimaldir. */
final class PriceSyncService
{
    public function __construct(
        private readonly mixed $adapterFactory = null,
        private readonly ?FieldOwnership $ownership = null,
    ) {}

    public function push(int $companyId, string $sku): SyncResult
    {
        // dry_run ise adapter HIÇ çağrılmaz; dış yazım atlanır ve loglanır.
        if (config('integrations.dry_run')) {
            Log::info('integration.price.push.skipped_dry_run', [
                'company_id' => $companyId,
                'sku' => $sku,
            ]);

            return SyncResult::skipped('dry_run: dış fiyat yazımı atlandı.');
        }

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        if ($product === null) {
            return SyncResult::failure('Ürün bulunamadı: '.$sku);
        }

        $rows = ProductPrice::query()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->orderBy('currency_code')
            ->get();

        if ($rows->isEmpty()) {
            return SyncResult::success(null, '0 price(s) pushed');
        }

        try {
            $adapter = $this->adapter($companyId);
        } catch (Throwable) {
            return SyncResult::failure('Fiyat adapteri olusturulamadi.');
        }

        if (! is_object($adapter) || ! method_exists($adapter, 'pushPrice')) {
            return SyncResult::failure('Fiyat adapteri kullanilamiyor.');
        }

        $pushed = 0;
        $failure = null;

        foreach ($rows as $row) {
            $canonical = new CanonicalPrice(
                $sku,
                strtoupper((string) $row->currency_code),
                $this->money($row->sale_price),
                $row->cost_price === null ? null : $this->money($row->cost_price),
                true,
            );

            try {
                $result = $adapter->pushPrice($canonical);
            } catch (Throwable) {
                $failure ??= 'Fiyat yazımı başarısız.';

                continue;
            }

            if (! $result instanceof SyncResult || ! $result->ok) {
                $failure ??= 'Fiyat yazımı başarısız.';

                continue;
            }

            $pushed++;
        }

        if ($failure !== null) {
            return SyncResult::failure($failure.' ('.$pushed.'/'.$rows->count().' başarılı)');
        }

        return SyncResult::success(null, sprintf('%d price(s) pushed', $pushed));
    }

    /**
     * Tsoft fiyatlarını product_prices tablosuna yazar.
     *
     * Yalnızca MEVCUT bir fiyatı ezmek engellenir; MRC sahip olduğunda dış
     * güncelleme atlanır, Tsoft sahip olduğunda güncellenir (price_source,
     * varsayılan mrc). Yeni para birimi satırı oluşturmak "ezmek" sayılmaz.
     *
     * @param  array<int,mixed>  $externalPrices
     * @return array<string,mixed>
     */
    public function pull(int $companyId, array $externalPrices): array
    {
        $ownership = $this->ownership ?? new FieldOwnership;
        $defaultCurrency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        $upserted = 0;
        $skipped = 0;
        $rows = [];

        foreach ($externalPrices as $raw) {
            $price = $this->toCanonical($raw, $defaultCurrency);

            if ($price === null || $price->sku === '') {
                $skipped++;

                continue;
            }

            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('sku', $price->sku)
                ->first();

            if ($product === null) {
                $skipped++;

                continue;
            }

            $currency = strtoupper($price->currency !== '' ? $price->currency : $defaultCurrency);
            $existing = ProductPrice::query()
                ->where('product_id', $product->id)
                ->where('currency_code', $currency)
                ->first();

            if ($existing !== null && ! $this->externalMayWritePrice($ownership, $companyId, $currency)) {
                $skipped++;

                continue;
            }

            $row = ProductPrice::updateOrCreate(
                ['product_id' => $product->id, 'currency_code' => $currency],
                [
                    'company_id' => $companyId,
                    'sale_price' => $this->money($price->salePrice),
                    'cost_price' => $price->costPrice === null ? null : $this->money($price->costPrice),
                ],
            );

            $upserted++;
            $rows[] = $row->toArray();
        }

        return ['upserted' => $upserted, 'skipped' => $skipped, 'rows' => $rows];
    }

    private function externalMayWritePrice(FieldOwnership $ownership, int $companyId, string $currency): bool
    {
        if ($ownership->allowsWriteFrom($companyId, 'product', 'price', 'tsoft')) {
            return true;
        }

        return $ownership->allowsWriteFrom($companyId, 'price', $currency, 'tsoft');
    }

    private function adapter(int $companyId): mixed
    {
        $factory = $this->adapterFactory;

        if (is_callable($factory)) {
            return $factory($companyId);
        }

        return app(TsoftAdapter::class, ['company' => $companyId]);
    }

    private function toCanonical(mixed $raw, string $defaultCurrency): ?CanonicalPrice
    {
        if ($raw instanceof CanonicalPrice) {
            return $raw;
        }

        if (! is_array($raw)) {
            return null;
        }

        $sku = $this->pick($raw, ['sku', 'ProductCode', 'product_code', 'catalogCode', 'attributes.sku']);
        $currency = $this->pick($raw, [
            'currency', 'currency_code', 'currencyCode', 'Currency', 'attributes.currency',
        ]) ?: $defaultCurrency;
        $sale = $this->pick($raw, [
            'sale_price', 'salePrice', 'SellingPrice', 'price', 'Price', 'attributes.salePrice',
        ]);

        if ($sale === null) {
            return null;
        }

        $cost = $this->pick($raw, [
            'cost_price', 'costPrice', 'CostPrice', 'purchasePrice', 'attributes.costPrice',
        ]);
        $active = $this->pick($raw, ['is_active', 'isActive', 'active', 'IsActive']);

        return new CanonicalPrice(
            (string) $sku,
            strtoupper((string) $currency),
            $this->money($sale),
            ($cost === null || $cost === '') ? null : $this->money($cost),
            $active === null ? true : filter_var($active, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /** @param array<string,mixed> $data @param array<int,string> $keys */
    private function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $data;

            foreach (explode('.', $key) as $segment) {
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

        return null;
    }

    private function money(mixed $value): string
    {
        $raw = str_replace(',', '.', (string) $value);

        return bcadd(is_numeric($raw) ? $raw : '0', '0', 4);
    }
}
