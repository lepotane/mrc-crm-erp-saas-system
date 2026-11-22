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
     * @param  bool  $force  Yalnızca açıkça istendiğinde sahiplik kuralını aşar (kullanım loglanır).
     * @return array<string,mixed>
     */
    public function pull(int $companyId, array $externalPrices, bool $force = false): array
    {
        $ownership = $this->ownership ?? new FieldOwnership;
        $defaultCurrency = strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY'));
        $upserted = 0;
        $skipped = 0;
        $rows = [];

        if ($force) {
            Log::warning('integration.price.pull.forced', [
                'company_id' => $companyId,
                'external_rows' => count($externalPrices),
            ]);
        }

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

            if ($existing !== null && ! $force && ! $this->externalMayWritePrice($ownership, $companyId, $currency)) {
                $skipped++;

                continue;
            }

            if ($force && $existing !== null) {
                Log::warning('integration.price.pull.forced_overwrite', [
                    'company_id' => $companyId,
                    'sku' => $price->sku,
                    'currency' => $currency,
                ]);
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

    /**
     * Kapsamlı MRC -> Tsoft fiyat gönderimi (tek / seçili / tüm ürünler).
     *
     * @param  array<int,mixed>  $skus
     * @return array{total:int,pushed:int,failed:int,skipped:int,details:array<int,array<string,mixed>>}
     */
    public function pushScoped(int $companyId, array $skus = [], bool $all = false, bool $force = false): array
    {
        if ($force) {
            Log::info('integration.price.push.force_requested', ['company_id' => $companyId]);
        }

        $targets = $this->resolveSkus($companyId, $skus, $all);
        if ($targets === null) {
            return $this->failureSummary('Fiyat gönderimi için ürün seçilmedi (--sku veya --all gerekli).');
        }

        $summary = $this->emptySummary(count($targets));

        foreach ($targets as $sku) {
            try {
                $result = $this->push($companyId, $sku);

                if ($result->skipped) {
                    $status = 'skipped';
                    $summary['skipped']++;
                } elseif ($result->ok) {
                    $status = 'pushed';
                    $summary['pushed']++;
                } else {
                    $status = 'failed';
                    $summary['failed']++;
                }

                $message = $result->message;
            } catch (Throwable) {
                $status = 'failed';
                $summary['failed']++;
                $message = 'Fiyat gönderimi başarısız.';
            }

            $summary['details'][] = ['sku' => $sku, 'status' => $status, 'message' => $message];
        }

        return $summary;
    }

    /**
     * Kapsamlı Tsoft -> MRC fiyat çekimi (tek / seçili / tüm ürünler).
     *
     * Dış okuma yöntemi adapter üzerinde duck-typing ile çözülür; yoksa uydurma
     * bir uç kullanılmaz, açık başarısızlık döndürülür.
     *
     * @param  array<int,mixed>  $skus
     * @return array{total:int,pushed:int,failed:int,skipped:int,details:array<int,array<string,mixed>>}
     */
    public function pullScoped(int $companyId, array $skus = [], bool $all = false, bool $force = false): array
    {
        $targets = $this->resolveSkus($companyId, $skus, $all);
        if ($targets === null) {
            return $this->failureSummary('Fiyat çekimi için ürün seçilmedi (--sku veya --all gerekli).');
        }

        $summary = $this->emptySummary(count($targets));

        if (config('integrations.dry_run')) {
            Log::info('integration.price.pull.skipped_dry_run', [
                'company_id' => $companyId,
                'skus' => count($targets),
            ]);
            $summary['skipped'] = count($targets);

            foreach ($targets as $sku) {
                $summary['details'][] = [
                    'sku' => $sku,
                    'status' => 'skipped',
                    'message' => 'dry_run: dış fiyat okuma ve yerel yazım atlandı.',
                ];
            }

            return $summary;
        }

        try {
            $adapter = $this->adapter($companyId);
        } catch (Throwable) {
            return $this->failureSummary('Fiyat adapteri olusturulamadi.');
        }

        if (! is_object($adapter)) {
            return $this->failureSummary('Fiyat adapteri kullanilamiyor.');
        }

        if (method_exists($adapter, 'fetchProducts')) {
            $method = 'fetchProducts';
        } elseif (method_exists($adapter, 'fetchProductPrices')) {
            $method = 'fetchProductPrices';
        } else {
            return $this->failureSummary('Fiyat adapteri ürün fiyatı okumayı desteklemiyor (fetchProducts/fetchProductPrices yok).');
        }

        try {
            $external = $adapter->{$method}($targets);
        } catch (Throwable) {
            return $this->failureSummary('Tsoft ürün fiyatları okunamadı.');
        }

        if (! is_array($external)) {
            return $this->failureSummary('Tsoft ürün fiyatı yanıtı beklenen biçimde değil.');
        }

        $result = $this->pull($companyId, $external, $force);
        $summary['pushed'] = (int) ($result['upserted'] ?? 0);
        $summary['skipped'] = (int) ($result['skipped'] ?? 0);

        $productIds = [];
        foreach ($result['rows'] ?? [] as $row) {
            if (is_array($row) && isset($row['product_id'])) {
                $productIds[] = (int) $row['product_id'];
            }
        }

        $skuById = [];
        if ($productIds !== []) {
            $skuById = Product::query()
                ->where('company_id', $companyId)
                ->whereIn('id', array_values(array_unique($productIds)))
                ->pluck('sku', 'id')
                ->all();
        }

        foreach ($result['rows'] ?? [] as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $summary['details'][] = [
                'sku' => (string) ($skuById[$productId] ?? ''),
                'status' => 'pushed',
                'message' => sprintf('%s fiyatı MRC\'ye alındı.', (string) ($row['currency_code'] ?? '')),
            ];
        }

        if ($summary['skipped'] > 0) {
            $summary['details'][] = [
                'sku' => '',
                'status' => 'skipped',
                'message' => sprintf('%d fiyat satırı sahiplik kuralı nedeniyle atlandı.', $summary['skipped']),
            ];
        }

        if ($summary['details'] === []) {
            $summary['details'][] = [
                'sku' => '',
                'status' => 'info',
                'message' => 'Tsoft tarafından bu ürünler için fiyat kaydı bulunamadı.',
            ];
        }

        return $summary;
    }

    /**
     * Yön seçicisi: out = MRC -> Tsoft, in = Tsoft -> MRC.
     *
     * @param  array<int,mixed>  $skus
     * @return array{total:int,pushed:int,failed:int,skipped:int,details:array<int,array<string,mixed>>}
     */
    public function sync(int $companyId, string $direction, array $skus = [], bool $all = false, bool $force = false): array
    {
        return match (strtolower(trim($direction))) {
            'out' => $this->pushScoped($companyId, $skus, $all, $force),
            'in' => $this->pullScoped($companyId, $skus, $all, $force),
            default => $this->failureSummary('Geçersiz senkronizasyon yönü: '.$direction.' (out|in beklenir).'),
        };
    }

    /**
     * @param  array<int,mixed>  $skus
     * @return array<int,string>|null null: boş seçim (çağıran hata özeti döndürür)
     */
    private function resolveSkus(int $companyId, array $skus, bool $all): ?array
    {
        if ($all) {
            return Product::query()
                ->where('company_id', $companyId)
                ->orderBy('sku')
                ->pluck('sku')
                ->map(fn ($sku) => (string) $sku)
                ->filter(fn ($sku) => $sku !== '')
                ->values()
                ->all();
        }

        $normalized = [];
        foreach ($skus as $sku) {
            if (! is_scalar($sku)) {
                continue;
            }

            $value = trim((string) $sku);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return $normalized === [] ? null : array_values($normalized);
    }

    /** @return array{total:int,pushed:int,failed:int,skipped:int,details:array<int,array<string,mixed>>} */
    private function emptySummary(int $total): array
    {
        return ['total' => $total, 'pushed' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
    }

    /** @return array{total:int,pushed:int,failed:int,skipped:int,details:array<int,array<string,mixed>>} */
    private function failureSummary(string $message): array
    {
        $summary = $this->emptySummary(0);
        $summary['failed'] = 1;
        $summary['details'][] = ['sku' => '', 'status' => 'failed', 'message' => $message];

        return $summary;
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
