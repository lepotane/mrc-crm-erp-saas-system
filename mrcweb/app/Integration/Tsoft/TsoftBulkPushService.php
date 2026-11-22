<?php

namespace App\Integration\Tsoft;

use App\Integration\Enums\SyncDirection;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Support\CredentialVault;
use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Sync\TsoftWritebackService;
use App\Models\Product;
use App\Models\StockMovement;
use Throwable;

/**
 * Çoklu ürün Tsoft gönderimi (opt-in).
 *
 * - preview() hiçbir HTTP isteği yapmaz; yalnızca alan doğrulaması ve
 *   gönderilecek payload'ı üretir.
 * - push() her satırı ayrıca ele alır; hata fırlatmaz, kişisel veri veya ham
 *   payload loglara yazılmaz.
 * - Idempotency:IntegrationAudit üzerinden `bulk:<productId>:<kind>` anahtarı
 *   kaydedilir; aynı ürün ikinci kez gönderilmez.
 */
final class TsoftBulkPushService
{
    private const MAP_TYPE = 'product_bulk_push';

    public function __construct(
        private readonly TsoftWritebackService $writeback,
        private readonly CredentialVault $vault,
    ) {}

    /**
     * Yazma yapmadan her ürün için önizleme satırı üretir.
     *
     * @param  array<int,int>  $productIds
     * @return array{rows: array<int,array<string,mixed>>, counts: array<string,int>}
     */
    public function preview(int $companyId, array $productIds): array
    {
        $startedAt = microtime(true);
        $products = $this->products($companyId, $productIds);
        $writesEnabled = $this->catalogWritesEnabled();
        $rows = [];

        foreach ($productIds as $productId) {
            $product = $products[(int) $productId] ?? null;

            if (! $product instanceof Product) {
                $rows[] = [
                    'id' => (int) $productId,
                    'sku' => '',
                    'name' => '',
                    'kind' => 'skip',
                    'reasons' => ['ürün bulunamadı'],
                    'payload' => [],
                    'would_write' => false,
                    'already_pushed' => false,
                ];

                continue;
            }

            $payload = $this->payload($companyId, $product);
            $reasons = $this->missing($product, $payload);
            $kind = $reasons === [] ? $this->kind($companyId, (int) $product->id) : 'skip';
            $alreadyPushed = $kind !== 'skip' && $this->pushedKey($companyId, (int) $product->id) !== null;

            $rows[] = [
                'id' => (int) $product->id,
                'sku' => trim((string) $product->sku),
                'name' => (string) $product->name,
                'kind' => $kind,
                'reasons' => $reasons,
                'payload' => $payload,
                'would_write' => $kind !== 'skip' && ! $alreadyPushed && $writesEnabled,
                'already_pushed' => $alreadyPushed,
            ];
        }

        IntegrationAudit::log(
            $companyId,
            SyncDirection::Out,
            'product_tsoft_bulk_push',
            null,
            'preview',
            $this->counts($rows),
            (int) ((microtime(true) - $startedAt) * 1000),
        );

        return ['rows' => $rows, 'counts' => $this->counts($rows)];
    }

    /**
     * Geçerli satırlar için ürün + fiyat + stok yazımı yapar.
     *
     * @param  array<int,int>  $productIds
     * @return array{rows: array<int,array<string,mixed>>, counts: array<string,int>}
     */
    public function push(
        int $companyId,
        array $productIds,
        bool $includePrice = true,
        bool $includeStock = true,
    ): array {
        $startedAt = microtime(true);
        $preview = $this->preview($companyId, $productIds);
        $rows = [];
        $driver = null;

        foreach ($preview['rows'] as $previewRow) {
            $row = [
                'id' => (int) $previewRow['id'],
                'sku' => (string) $previewRow['sku'],
                'name' => (string) $previewRow['name'],
                'kind' => (string) $previewRow['kind'],
                'reasons' => $previewRow['reasons'],
                'payload' => $previewRow['payload'],
                'would_write' => (bool) $previewRow['would_write'],
                'status' => 'failed',
                'result' => [],
            ];

            if ($row['kind'] === 'skip') {
                $row['status'] = 'invalid';
                $rows[] = $row;

                continue;
            }

            if ($previewRow['already_pushed']) {
                $row['status'] = 'already_pushed';
                $rows[] = $row;

                continue;
            }

            try {
                $driver ??= new TsoftWriteDriver($companyId, $this->vault);
                $payload = $previewRow['payload'];
                $sku = (string) ($payload['ProductCode'] ?? '');
                $result = [
                    'product' => $this->writeback->pushProduct($companyId, $payload),
                ];

                if ($includePrice) {
                    $result['price'] = $driver->updatePrice(
                        $sku,
                        (string) ($payload['SellingPrice'] ?? '0'),
                        (string) ($payload['Currency'] ?? ''),
                    );
                }
                if ($includeStock) {
                    $result['stock'] = $driver->setStock($sku, (string) ($payload['Stock'] ?? '0'));
                }

                $allSkipped = true;
                $allOk = true;
                foreach ($result as $writeResult) {
                    $skipped = ! empty($writeResult['skipped']);
                    $allSkipped = $allSkipped && $skipped;
                    $allOk = $allOk && ($skipped || ! empty($writeResult['ok']));
                }

                $row['status'] = $allOk ? ($allSkipped ? 'skipped' : 'ok') : 'failed';
                $row['result'] = array_map(
                    static fn (array $item): array => [
                        'ok' => ! empty($item['ok']),
                        'skipped' => ! empty($item['skipped']),
                        'message' => (string) ($item['message'] ?? ''),
                    ],
                    $result,
                );

                // dry_run / kapalı flag sonucu "skipped" ise eşleme yazılmaz;
                // işlem daha sonra gerçekten yazılmak üzere yeniden denenebilir.
                if ($allOk && ! $allSkipped) {
                    $this->remember($companyId, (int) $row['id'], (string) $row['kind']);
                }
            } catch (Throwable) {
                // Satır bazlı hata: genel mesaj döner, ham payload/kişisel veri yazılmaz.
                $row['status'] = 'failed';
                $row['result'] = ['message' => 'Toplu Tsoft gönderimi başarısız.'];
            }

            $rows[] = $row;
        }

        $counts = $this->counts($rows);
        IntegrationAudit::log(
            $companyId,
            SyncDirection::Out,
            'product_tsoft_bulk_push',
            null,
            $counts['failed'] > 0 ? 'failed' : 'ok',
            $counts,
            (int) ((microtime(true) - $startedAt) * 1000),
        );

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @param  array<int,int>  $productIds
     * @return array<int,Product>
     */
    private function products(int $companyId, array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->with(['category', 'unit'])
            ->get(['products.*'])
            ->keyBy('id')
            ->all();
    }

    /** @return array<string,mixed> — tekil gönderimle aynı payload şekli */
    private function payload(int $companyId, Product $product): array
    {
        $category = $product->category;
        $unit = $product->unit;

        return [
            'ProductCode' => trim((string) $product->sku),
            'ProductName' => filled($product->name_en)
                ? (string) $product->name_en
                : (string) $product->name,
            'DefaultCategoryCode' => trim((string) ($category?->getAttribute('external_code') ?? '')),
            'CategoryName' => (string) ($category?->name ?? ''),
            'IsLeaf' => (bool) ($category?->getAttribute('is_leaf') ?? false),
            'StockUnit' => trim((string) ($unit?->code ?: ($unit?->name ?? ''))),
            'ImageUrl' => (string) ($product->getAttribute('image_url') ?? ''),
            'SellingPrice' => (string) $product->sale_price,
            'BuyingPrice' => (string) $product->cost_price,
            'Currency' => trim((string) $product->currency_code),
            'Vat' => (string) $product->tax_rate,
            'Stock' => StockMovement::stockFor($companyId, (int) $product->id),
            'IsActive' => true,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function missing(Product $product, array $payload): array
    {
        $missing = [];
        if (($payload['ProductCode'] ?? '') === '') {
            $missing[] = 'ürün kodu (SKU)';
        }

        $category = $product->category;
        if ($category === null) {
            $missing[] = 'kategori';
        } else {
            if (! ($payload['IsLeaf'] ?? false)) {
                $missing[] = 'yaprak kategori (is_leaf)';
            }
            if (($payload['DefaultCategoryCode'] ?? '') === '') {
                $missing[] = 'kategori Tsoft kodu (external_code)';
            }
        }

        if ((float) ($payload['SellingPrice'] ?? 0) <= 0) {
            $missing[] = 'satış fiyatı';
        }
        if (($payload['Currency'] ?? '') === '') {
            $missing[] = 'para birimi';
        }

        return $missing;
    }

    /** Daha önce Tsoft'tan eşlenmiş ürün güncelleme, diğerleri oluşturma sayılır. */
    private function kind(int $companyId, int $productId): string
    {
        $mapped = IntegrationIdMap::query()
            ->where('company_id', $companyId)
            ->where('mrc_type', 'product')
            ->where('mrc_id', $productId)
            ->exists();

        return $mapped ? 'update' : 'create';
    }

    /** İki olası anahtar da aranır; çalışma türü değişse bile tekrar yazılmaz. */
    private function pushedKey(int $companyId, int $productId): ?string
    {
        foreach (['create', 'update'] as $kind) {
            $key = 'bulk:'.$productId.':'.$kind;
            if (IntegrationAudit::findMap($companyId, self::MAP_TYPE, $key) !== null) {
                return $key;
            }
        }

        return null;
    }

    private function remember(int $companyId, int $productId, string $kind): void
    {
        $key = 'bulk:'.$productId.':'.$kind;
        if (IntegrationAudit::findMap($companyId, self::MAP_TYPE, $key) !== null) {
            return;
        }

        IntegrationIdMap::query()->create([
            'company_id' => $companyId,
            'mrc_type' => self::MAP_TYPE,
            'mrc_id' => $productId,
            'external_type' => self::MAP_TYPE,
            'external_key' => mb_substr($key, 0, 190),
        ]);
    }

    private function catalogWritesEnabled(): bool
    {
        return config('integrations.dry_run') !== true
            && config('integrations.channels.tsoft.catalog_write_enabled') === true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function counts(array $rows): array
    {
        $counts = [
            'total' => count($rows),
            'create' => 0, 'update' => 0, 'skip' => 0,
            'ok' => 0, 'failed' => 0, 'skipped' => 0,
            'invalid' => 0, 'already_pushed' => 0,
        ];

        foreach ($rows as $row) {
            foreach (['kind', 'status'] as $field) {
                $value = (string) ($row[$field] ?? '');
                if (array_key_exists($value, $counts)) {
                    $counts[$value]++;
                }
            }
        }

        return $counts;
    }
}
