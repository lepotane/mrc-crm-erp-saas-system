<?php

namespace App\Integration\Sync;

use App\Integration\Enums\SyncDirection;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Support\CredentialVault;
use App\Integration\Support\TsoftWarehouse;
use App\Integration\Tsoft\TsoftWriteDriver;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockMovement;
use Throwable;

/**
 * MRC kaynaklı stok değişikliklerini Tsoft'a ayna olarak yazar (opt-in).
 *
 * Güvenlik sözleşmesi:
 *  - Yazmalar varsayılan olarak kapalıdır (dry_run + kanal flag'i).
 *  - Yalnızca origin='mrc' belgeler ve eşlenmiş depo işlenir.
 *  - Tsoft kökenli siparişler asla geri yazılmaz.
 *  - Idempotency: IntegrationIdMap kaydı varsa ikinci yazma yapılmaz.
 *  - Hata fırlatılmaz; kişisel veri/günlük ham payload yazılmaz.
 */
final class TsoftWritebackService
{
    private const TYPE_STOCK_WRITEBACK = 'stock_writeback';

    private const REFERENCES = ['issue', 'cancel', 'return'];

    public function __construct(
        private readonly CredentialVault $vault,
    ) {}

    /**
     * @return array{processed: int, skipped: int, errors: array<int,string>}
     */
    public function processDocument(int $companyId, int $documentId): array
    {
        $result = ['processed' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $document = Document::query()
                ->where('company_id', $companyId)
                ->where('id', $documentId)
                ->first();

            if (! $document instanceof Document) {
                $result['skipped']++;

                return $result;
            }

            // Tsoft'tan gelen siparişin stoğu MRC'nin kaynağıdır; geri yazılmaz.
            if ((string) $document->getAttribute('origin') !== 'mrc') {
                $result['skipped']++;

                return $result;
            }

            $documentWarehouseId = $document->warehouse_id === null
                ? null
                : (int) $document->warehouse_id;

            // Tek paylaşılan çözümleyici: hedef yoksa (null) da false döner,
            // yani belge atlanır. Diğer depolar Tsoft'dan izole edilir.
            if (! TsoftWarehouse::isTsoftWarehouse($companyId, $documentWarehouseId)) {
                $result['skipped']++;

                return $result;
            }

            $warehouseId = (int) $documentWarehouseId;

            // Sürücü kendi guard'ına sahiptir; fakat salt okuma bile HTTP
            // gerektirdiğinden kapalıyken hiç çağrı yapmamak için burada da
            // aynı kapı kontrolü yapılır (çift yazma/kapalıyken okuma yok).
            if (! $this->stockWritesEnabled()) {
                $result['skipped']++;

                return $result;
            }

            $deltas = $this->stockDeltas($companyId, $documentId, $warehouseId);
            if ($deltas === []) {
                $result['skipped']++;

                return $result;
            }

            $driver = new TsoftWriteDriver($companyId, $this->vault);

            foreach ($deltas as $sku => $entry) {
                $key = 'document:'.$documentId.':stock:'.$sku.':'.$entry['reference'];

                if (IntegrationAudit::findMap($companyId, self::TYPE_STOCK_WRITEBACK, $key) !== null) {
                    $result['skipped']++;

                    continue;
                }

                $product = $driver->readProduct((string) $sku);
                if ($product === null) {
                    $result['skipped']++;
                    $result['errors'][] = 'Tsoft ürünü okunamadı.';

                    continue;
                }

                $current = $this->decimal((string) ($product['Stock'] ?? null));
                $target = $this->trimDecimal(bcadd($current, (string) $entry['delta'], 4));

                $write = $driver->setStock((string) $sku, $target);
                if (! empty($write['skipped'])) {
                    // dry_run / flag kapanmışsa eşleme yazılmaz; işlem daha sonra
                    // yeniden denenebilir.
                    $result['skipped']++;

                    continue;
                }
                if (empty($write['ok'])) {
                    $result['skipped']++;
                    $result['errors'][] = 'Tsoft stok yazılamadı.';

                    continue;
                }

                IntegrationIdMap::query()->create([
                    'company_id' => $companyId,
                    'mrc_type' => self::TYPE_STOCK_WRITEBACK,
                    'mrc_id' => $documentId,
                    'external_type' => self::TYPE_STOCK_WRITEBACK,
                    'external_key' => mb_substr($key, 0, 190),
                ]);

                IntegrationAudit::log(
                    $companyId,
                    SyncDirection::Out,
                    'stock_writeback',
                    null,
                    'ok',
                    ['processed' => 1, 'skipped' => 0, 'errors' => 0],
                );

                $result['processed']++;
            }
        } catch (Throwable) {
            // Hook güvenliği: asla fırlatma, genel hata mesajı ekle.
            $result['errors'][] = 'Stok yazımı başarısız.';
        }

        return $result;
    }

    /**
     * Gelecekteki UI düğmesi için ince sarmalayıcı; otomatik çağrılmaz.
     *
     * @return array<string,mixed>
     */
    public function pushProduct(int $companyId, array $product): array
    {
        try {
            return (new TsoftWriteDriver($companyId, $this->vault))->createProduct($product);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Ürün yazılamadı.', 'data' => []];
        }
    }

    /**
     * Gelecekteki UI düğmesi için ince sarmalayıcı; otomatik çağrılmaz.
     *
     * @return array<string,mixed>
     */
    public function pushPrice(int $companyId, string $sku, string $price): array
    {
        try {
            return (new TsoftWriteDriver($companyId, $this->vault))->updatePrice($sku, $price);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Fiyat yazılamadı.', 'data' => []];
        }
    }

    private function stockWritesEnabled(): bool
    {
        return config('integrations.dry_run') !== true
            && config('integrations.channels.tsoft.stock_write_enabled') === true;
    }

    /**
     * Belgeye ait issue/cancel/return hareketlerini SKU bazında toplar.
     *
     * @return array<string,array{delta: string, reference: string}>
     */
    private function stockDeltas(int $companyId, int $documentId, int $warehouseId): array
    {
        $references = array_map(static fn (string $type): string => $type.':'.$documentId, self::REFERENCES);

        $movements = StockMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('reference', $references)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('id')
            ->get(['product_id', 'type', 'quantity', 'reference']);

        if ($movements->isEmpty()) {
            return [];
        }

        $productIds = $movements->pluck('product_id')->unique()->values()->all();
        $skus = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->pluck('sku', 'id');

        $deltas = [];
        foreach ($movements as $movement) {
            $sku = trim((string) ($skus[$movement->product_id] ?? ''));
            if ($sku === '') {
                continue;
            }

            if (! isset($deltas[$sku])) {
                $deltas[$sku] = ['delta' => '0.0000', 'reference' => (string) $movement->reference];
            }

            $signed = number_format($movement->signedQuantity(), 4, '.', '');
            $deltas[$sku]['delta'] = bcadd($deltas[$sku]['delta'], $signed, 4);
        }

        foreach ($deltas as $sku => $entry) {
            if (bccomp($entry['delta'], '0', 4) === 0) {
                unset($deltas[$sku]);
            }
        }

        return $deltas;
    }

    /** Para/miktarlar her zaman string decimaldir (float kullanılmaz). */
    private function decimal(?string $value): string
    {
        $raw = str_replace(',', '.', (string) $value);

        return bcadd(is_numeric($raw) ? $raw : '0', '0', 4);
    }

    /** Tsoft'a gereksiz sıfırlar gönderilmez: '12.0000' → '12'. */
    private function trimDecimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');

        return ($value === '' || $value === '-') ? '0' : $value;
    }
}
