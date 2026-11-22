<?php

namespace App\Integration\Sync;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalProduct;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Enums\FieldSource;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Orders\OrderSyncService;
use App\Integration\Tsoft\TsoftAdapter;
use App\Integration\Tsoft\TsoftMapper;
use App\Models\Product;
use App\Models\SyncDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync center: Tsoft'u YALNIZCA OKUR, farkları kaydeder ve seçilen farkları
 * kullanıcı onayıyla MRC'ye aktarır. Bu sınıftan Tsoft'a ASLA yazılmaz.
 *
 * Para/miktar karşılaştırmaları string decimal + bcmath ile yapılır (float yok).
 * dry_run açıkken tarama okumaya devam eder; import hiçbir yerel yazım yapmaz.
 */
final class SyncDiffService
{
    public function __construct(
        private readonly mixed $adapterFactory = null,
        private readonly ?FieldOwnership $ownership = null,
        private readonly ?PriceSyncService $priceSync = null,
        private readonly ?ParallelStockEngine $stockEngine = null,
        private readonly ?OrderSyncService $orderSync = null,
    ) {}

    /**
     * Tsoft kataloğunu ve son 24 saatin siparişlerini okuyup fark kaydı üretir.
     *
     * @return array{scanned:int,new:int,updated:int,orders:int,error?:string}
     */
    public function scan(int $companyId): array
    {
        $result = ['scanned' => 0, 'new' => 0, 'updated' => 0, 'orders' => 0];

        try {
            $adapter = $this->adapter($companyId);
        } catch (Throwable) {
            $result['error'] = 'Tsoft adapteri oluşturulamadı.';

            return $result;
        }

        if (! is_object($adapter) || ! method_exists($adapter, 'fetchAllProducts')) {
            $result['error'] = 'Tsoft adapteri tam katalog okumayı desteklemiyor (fetchAllProducts yok).';

            return $result;
        }

        try {
            $rows = $adapter->fetchAllProducts();
        } catch (Throwable) {
            $result['error'] = 'Tsoft ürünleri okunamadı.';

            return $result;
        }

        if (! is_array($rows)) {
            $result['error'] = 'Tsoft ürün yanıtı beklenen biçimde değil.';

            return $result;
        }

        $ownership = $this->ownership ?? new FieldOwnership;
        $localProducts = $this->localProducts($companyId);
        $seenSkus = [];

        foreach ($rows as $raw) {
            $result['scanned']++;

            $product = $this->map($raw, 'product');
            $sku = $product instanceof CanonicalProduct
                ? trim($product->sku)
                : trim((string) $this->pick($raw, ['ProductCode', 'sku', 'catalogCode', 'attributes.sku'], ''));

            if ($sku === '') {
                continue;
            }

            $seenSkus[$sku] = true;
            $local = $localProducts[$sku] ?? null;
            $externalSnapshot = $product instanceof CanonicalProduct
                ? $product->toArray()
                : ['sku' => $sku, 'name' => (string) $this->pick($raw, ['ProductName', 'name', 'attributes.name'], '')];

            if ($local === null) {
                // Tsoft'ta var, MRC'de yok: yeni ürün uyarısı. Silme önerilmez.
                $this->upsert(
                    $companyId,
                    SyncDiff::TYPE_PRODUCT,
                    $sku,
                    SyncDiff::DIFF_NEW_REMOTE,
                    null,
                    $externalSnapshot,
                    sprintf("Tsoft'ta yeni ürün var: %s (%s).", (string) ($externalSnapshot['name'] ?? ''), $sku),
                    $result
                );

                continue;
            }

            $this->detectProductChanges($companyId, $ownership, $local, $product, $raw, $sku, $result);
        }

        foreach ($localProducts as $sku => $local) {
            if (isset($seenSkus[$sku])) {
                continue;
            }

            // MRC'de var, Tsoft'ta yok: yalnızca bilgi. Tsoft'a yazılmaz/silmez.
            $this->upsert(
                $companyId,
                SyncDiff::TYPE_PRODUCT,
                (string) $sku,
                SyncDiff::DIFF_NEW_LOCAL,
                ['sku' => (string) $sku, 'name' => (string) $local->name],
                null,
                sprintf('MRC\'de yeni ürün var, Tsoft\'ta bulunamadı: %s (%s).', (string) $local->name, (string) $sku),
                $result
            );
        }

        $this->scanOrders($companyId, $adapter, $result);

        return $result;
    }

    /**
     * Seçili bekleyen farkları MRC'ye aktarır. Tsoft'a yazım yapılmaz.
     *
     * @return array{total:int,imported:int,ignored:int,skipped:int,failed:int,details:array<int,array<string,mixed>>}
     */
    public function import(int $companyId, array $diffIds): array
    {
        $ids = $this->normalizeIds($diffIds);
        $summary = [
            'total' => count($ids),
            'imported' => 0,
            'ignored' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        if ($ids === []) {
            return $summary;
        }

        $diffs = SyncDiff::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $dryRun = (bool) config('integrations.dry_run');

        foreach ($diffs as $diff) {
            $detail = [
                'id' => (int) $diff->id,
                'entity_type' => (string) $diff->entity_type,
                'external_key' => (string) $diff->external_key,
                'status' => 'skipped',
                'message' => '',
            ];

            if ($dryRun) {
                $detail['message'] = 'dry_run: içe aktarma atlandı, fark bekliyor durumunda kaldı.';
                $summary['skipped']++;
                $summary['details'][] = $detail;

                continue;
            }

            if ($diff->status !== SyncDiff::STATUS_PENDING) {
                $detail['status'] = 'ignored';
                $detail['message'] = 'Fark zaten çözülmüş ('.$diff->status.').';
                $summary['ignored']++;
                $summary['details'][] = $detail;

                continue;
            }

            try {
                [$imported, $status, $message] = $this->applyDiff($companyId, $diff);
            } catch (Throwable $e) {
                Log::warning('integration.sync_diff.import_failed', [
                    'company_id' => $companyId,
                    'diff_id' => (int) $diff->id,
                    'error_class' => get_class($e),
                ]);
                $imported = false;
                $status = 'failed';
                $message = 'İçe aktarma başarısız ('.get_class($e).').';
            }

            if ($imported) {
                $diff->markImported($message);
                $summary['imported']++;
            } else {
                $diff->markIgnored($message);
                $summary['ignored']++;
                if ($status === 'failed') {
                    $summary['failed']++;
                }
            }

            $detail['status'] = $imported ? 'imported' : 'ignored';
            $detail['message'] = $message;
            $summary['details'][] = $detail;
        }

        return $summary;
    }

    /** @return array{total:int,ignored:int,skipped:int} */
    public function ignore(int $companyId, array $diffIds): array
    {
        $ids = $this->normalizeIds($diffIds);
        $summary = ['total' => count($ids), 'ignored' => 0, 'skipped' => 0];

        if ($ids === []) {
            return $summary;
        }

        $diffs = SyncDiff::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->get();

        foreach ($diffs as $diff) {
            if ($diff->status !== SyncDiff::STATUS_PENDING) {
                $summary['skipped']++;

                continue;
            }

            $diff->markIgnored('Kullanıcı tarafından yok sayıldı.');
            $summary['ignored']++;
        }

        return $summary;
    }

    /**
     * Pano/UI için sayımlar — snapshot alanları ASLA dahil edilmez.
     *
     * @return array{total:int,pending:int,imported:int,ignored:int,by_type:array<string,int>,by_status:array<string,int>}
     */
    public function summary(int $companyId): array
    {
        $byType = array_fill_keys(SyncDiff::TYPES, 0);
        $byStatus = array_fill_keys(SyncDiff::STATUSES, 0);

        $rows = DB::table('sync_diffs')
            ->where('company_id', $companyId)
            ->selectRaw('entity_type, status, COUNT(*) AS total')
            ->groupBy('entity_type', 'status')
            ->get();

        foreach ($rows as $row) {
            $type = (string) $row->entity_type;
            $status = (string) $row->status;
            $total = (int) $row->total;

            if (array_key_exists($type, $byType)) {
                $byType[$type] += $total;
            }
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] += $total;
            }
        }

        return [
            'total' => array_sum($byStatus),
            'pending' => $byStatus[SyncDiff::STATUS_PENDING],
            'imported' => $byStatus[SyncDiff::STATUS_IMPORTED],
            'ignored' => $byStatus[SyncDiff::STATUS_IGNORED],
            'by_type' => $byType,
            'by_status' => $byStatus,
        ];
    }

    /** @param array<string,mixed> $result */
    private function detectProductChanges(
        int $companyId,
        FieldOwnership $ownership,
        Product $local,
        mixed $product,
        mixed $raw,
        string $sku,
        array &$result,
    ): void {
        $nameIsExternal = $product instanceof CanonicalProduct
            && $product->nameSource->value === FieldSource::Tsoft->value;
        $nameIsExternal = $nameIsExternal
            || $ownership->get($companyId, 'product', 'name', 'mrc') === FieldSource::Tsoft->value;

        if ($nameIsExternal && $product instanceof CanonicalProduct
            && trim($product->name) !== trim((string) $local->name)) {
            $this->upsert(
                $companyId,
                SyncDiff::TYPE_PRODUCT,
                $sku,
                SyncDiff::DIFF_CHANGED_REMOTE,
                ['sku' => $sku, 'name' => (string) $local->name],
                ['sku' => $sku, 'name' => $product->name],
                sprintf("Tsoft'ta ürün adı değişti: %s → %s (%s).", (string) $local->name, $product->name, $sku),
                $result
            );
        }

        // Fiyat: yalnızca dış satırda gerçekten fiyat alanı varsa karşılaştır.
        if ($this->pick($raw, ['SellingPrice', 'salePrice', 'price', 'Price', 'attributes.salePrice', 'attributes.price']) !== null) {
            $price = $this->map($raw, 'price');
            $externalPrice = $price instanceof CanonicalPrice ? $price : null;

            if ($externalPrice !== null && $externalPrice->sku !== '' && $externalPrice->salePrice !== '') {
                $currency = strtoupper($externalPrice->currency !== '' ? $externalPrice->currency : (string) config('integrations.channels.tsoft.currency', 'TRY'));
                $localSale = $this->localSalePrice($companyId, (int) $local->id, $currency);
                $externalSale = $this->dec($externalPrice->salePrice);

                if ($localSale !== null && bccomp($localSale, $externalSale, 4) !== 0) {
                    $mrcSnapshot = [
                        'sku' => $sku,
                        'currency' => $currency,
                        'sale_price' => $localSale,
                    ];
                    $externalSnapshotPrice = [
                        'sku' => $sku,
                        'currency' => $currency,
                        'sale_price' => $externalSale,
                        'cost_price' => $externalPrice->costPrice === null
                            ? null
                            : $this->dec($externalPrice->costPrice),
                    ];

                    $this->upsert(
                        $companyId,
                        SyncDiff::TYPE_PRICE,
                        $sku.'|'.$currency,
                        SyncDiff::DIFF_CHANGED_REMOTE,
                        $mrcSnapshot,
                        $externalSnapshotPrice,
                        sprintf("Tsoft'ta fiyat değişti: %s %s → %s (%s).", $currency, $localSale, $externalSale, $sku),
                        $result
                    );
                }
            }
        }

        // Stok: yalnızca dış satırda stok alanı varsa karşılaştır.
        if ($this->pick($raw, ['Stock', 'stock', 'quantity', 'available', 'attributes.quantity']) !== null) {
            $stock = $this->map($raw, 'stock');
            if ($stock instanceof CanonicalStock && $stock->sku !== '') {
                $localStock = $this->localStock($companyId, (int) $local->id);
                $externalStock = $this->dec($stock->quantity);

                if (bccomp($localStock, $externalStock, 4) !== 0) {
                    $this->upsert(
                        $companyId,
                        SyncDiff::TYPE_STOCK,
                        $sku,
                        SyncDiff::DIFF_CHANGED_REMOTE,
                        ['sku' => $sku, 'quantity' => $localStock],
                        ['sku' => $sku, 'quantity' => $externalStock],
                        sprintf("Tsoft'ta stok değişti: %s → %s (%s).", $localStock, $externalStock, $sku),
                        $result
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $result */
    private function scanOrders(int $companyId, object $adapter, array &$result): void
    {
        if (! method_exists($adapter, 'fetchOrders')) {
            return;
        }

        try {
            $orders = $adapter->fetchOrders(now()->subDay(), 100);
        } catch (Throwable) {
            return;
        }

        if (! is_array($orders)) {
            return;
        }

        foreach ($orders as $raw) {
            $order = $raw instanceof CanonicalOrder
                ? $raw
                : $this->map($raw, 'order');

            if (! $order instanceof CanonicalOrder || $order->externalRef === '') {
                continue;
            }

            $mapped = DB::table('integration_id_map')
                ->where('company_id', $companyId)
                ->where('external_type', IntegrationAudit::TYPE_ORDER)
                ->where('external_key', $order->externalRef)
                ->exists();

            if ($mapped) {
                continue;
            }

            $snapshot = $order->toArray();
            $this->upsert(
                $companyId,
                SyncDiff::TYPE_ORDER,
                $order->externalRef,
                SyncDiff::DIFF_NEW_REMOTE,
                null,
                $snapshot,
                sprintf("Tsoft'ta yeni sipariş var: %s (%s).", (string) ($order->orderNumber ?? $order->externalRef), $order->externalRef),
                $result
            );
            $result['orders']++;
        }
    }

    /**
     * @return array{0:bool,1:string,2:string} imported / status / message
     */
    private function applyDiff(int $companyId, SyncDiff $diff): array
    {
        $entity = (string) $diff->entity_type;
        $diffType = (string) $diff->diff_type;

        if ($diffType === SyncDiff::DIFF_NEW_LOCAL) {
            return [false, 'ignored', 'MRC\'de yeni kayıt; Tsoft salt-okunur olduğu için dışarı yazım yapılmadı.'];
        }

        if ($entity === SyncDiff::TYPE_PRODUCT && $diffType === SyncDiff::DIFF_NEW_REMOTE) {
            return $this->importNewProduct($companyId, $diff);
        }

        if ($entity === SyncDiff::TYPE_PRICE && $diffType === SyncDiff::DIFF_CHANGED_REMOTE) {
            return $this->importPrice($companyId, $diff);
        }

        if ($entity === SyncDiff::TYPE_STOCK && $diffType === SyncDiff::DIFF_CHANGED_REMOTE) {
            return $this->importStock($companyId, $diff);
        }

        if ($entity === SyncDiff::TYPE_ORDER && $diffType === SyncDiff::DIFF_NEW_REMOTE) {
            return $this->importOrder($companyId, $diff);
        }

        return [false, 'ignored', 'Bu fark türü için otomatik içe aktarma tanımlı değil.'];
    }

    /** @return array{0:bool,1:string,2:string} */
    private function importNewProduct(int $companyId, SyncDiff $diff): array
    {
        $snapshot = is_array($diff->external_snapshot) ? $diff->external_snapshot : [];
        $sku = trim((string) ($snapshot['sku'] ?? $diff->external_key));
        if ($sku === '') {
            return [false, 'ignored', 'SKU boş; ürün oluşturulamadı.'];
        }

        $existing = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        if ($existing !== null) {
            return [false, 'ignored', 'Bu SKU zaten MRC\'de mevcut; ürün oluşturulmadı.'];
        }

        // Yerel kayıt yok; ad dış snapshot'tan alınır (kişisel veri taşınmaz).
        $name = (string) ($snapshot['name'] ?? '');

        Product::create([
            'company_id' => $companyId,
            'name' => mb_substr($name !== '' ? $name : $sku, 0, 190),
            'sku' => mb_substr($sku, 0, 64),
            'barcode' => isset($snapshot['barcode']) && (string) $snapshot['barcode'] !== ''
                ? mb_substr((string) $snapshot['barcode'], 0, 64)
                : null,
            'description' => isset($snapshot['description']) && (string) $snapshot['description'] !== ''
                ? mb_substr((string) $snapshot['description'], 0, 1000)
                : null,
            // Fiyatı biz uydurmayız; para birimi yapılandırmadan gelir.
            'currency_code' => mb_substr(strtoupper((string) config('integrations.channels.tsoft.currency', 'TRY')), 0, 3),
        ]);

        return [true, 'imported', 'Tsoft ürünü MRC\'ye alındı: '.$sku];
    }

    /** @return array{0:bool,1:string,2:string} */
    private function importPrice(int $companyId, SyncDiff $diff): array
    {
        $snapshot = is_array($diff->external_snapshot) ? $diff->external_snapshot : [];
        $currency = strtoupper((string) ($snapshot['currency'] ?? config('integrations.channels.tsoft.currency', 'TRY')));
        $ownership = $this->ownership();

        $mayWrite = $ownership->allowsWriteFrom($companyId, 'product', 'price', 'tsoft')
            || $ownership->allowsWriteFrom($companyId, 'price', $currency, 'tsoft');

        if (! $mayWrite) {
            return [false, 'ignored', 'Fiyat alanının sahibi MRC; dış fiyat yazılmadı (force=false).'];
        }

        $input = [[
            'sku' => (string) ($snapshot['sku'] ?? $diff->external_key),
            'currency' => $currency,
            'sale_price' => (string) ($snapshot['sale_price'] ?? '0'),
            'cost_price' => $snapshot['cost_price'] ?? null,
        ]];

        // force=false: PriceSyncService sahiplik kuralını bir kez daha doğrular.
        $result = $this->priceSync()->pull($companyId, $input, false);
        if ((int) ($result['upserted'] ?? 0) < 1) {
            return [false, 'ignored', 'Fiyat MRC\'ye yazılamadı (ürün yok veya sahiplik kuralı engeli).'];
        }

        return [true, 'imported', sprintf('%s fiyatı MRC\'ye alındı: %s.', $currency, (string) ($snapshot['sale_price'] ?? ''))];
    }

    /** @return array{0:bool,1:string,2:string} */
    private function importStock(int $companyId, SyncDiff $diff): array
    {
        $external = is_array($diff->external_snapshot) ? $diff->external_snapshot : [];
        $mrc = is_array($diff->mrc_snapshot) ? $diff->mrc_snapshot : [];
        $sku = trim((string) ($external['sku'] ?? $diff->external_key));
        if ($sku === '') {
            return [false, 'ignored', 'SKU boş; stok hareketi yazılamadı.'];
        }

        $delta = bcsub(
            $this->dec($external['quantity'] ?? '0'),
            $this->dec($mrc['quantity'] ?? '0'),
            4
        );

        if (bccomp($delta, '0', 4) === 0) {
            return [true, 'imported', 'Stok farkı kalmadı; hareket yazılmadı.'];
        }

        $this->stockEngine()->applyInbound(
            $companyId,
            $sku,
            $delta,
            'tsoft',
            'diff:'.(int) $diff->id,
            $this->defaultWarehouseId($companyId),
        );

        return [true, 'imported', 'Stok farkı MRC\'ye işlendi (delta: '.$delta.').'];
    }

    /** @return array{0:bool,1:string,2:string} */
    private function importOrder(int $companyId, SyncDiff $diff): array
    {
        $externalRef = (string) $diff->external_key;
        $adapter = $this->adapter($companyId);

        if (! is_object($adapter) || ! method_exists($adapter, 'fetchOrder')) {
            return [false, 'ignored', 'Adapter fetchOrder desteklemiyor; sipariş alınamadı.'];
        }

        $order = $adapter->fetchOrder($externalRef);
        if (! $order instanceof CanonicalOrder) {
            return [false, 'ignored', 'Tsoft siparişi bulunamadı: '.$externalRef];
        }

        $document = $this->orderSync()->ingest($companyId, $order);

        return [true, 'imported', 'Tsoft siparişi MRC belgesi olarak alındı (#'.(int) $document->id.').'];
    }

    /** @return array<string,Product> */
    private function localProducts(int $companyId): array
    {
        $products = [];

        foreach (Product::query()->where('company_id', $companyId)->get() as $product) {
            $sku = trim((string) $product->sku);
            if ($sku !== '') {
                $products[$sku] = $product;
            }
        }

        return $products;
    }

    private function localSalePrice(int $companyId, int $productId, string $currency): ?string
    {
        $row = DB::table('product_prices')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('currency_code', $currency)
            ->first();

        return $row === null ? null : $this->dec($row->sale_price);
    }

    /** MRC stoku movement toplamından, float'a çevirmeden okunur. */
    private function localStock(int $companyId, int $productId): string
    {
        $value = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity WHEN type = 'out' THEN -quantity ELSE quantity END), 0) AS stock")
            ->value('stock');

        return $this->dec($value);
    }

    private function defaultWarehouseId(int $companyId): ?int
    {
        $id = DB::table('warehouses')
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param array<string,mixed> $result */
    private function upsert(
        int $companyId,
        string $entityType,
        string $externalKey,
        string $diffType,
        ?array $mrcSnapshot,
        ?array $externalSnapshot,
        string $summary,
        array &$result,
    ): void {
        $existing = SyncDiff::query()
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('external_key', $externalKey)
            ->first();

        if ($existing !== null) {
            // İlk tespit anı/snapshots/summary/status korunur (geçmiş silinmez).
            $existing->forceFill(['detected_at' => now()])->save();
            $result['updated']++;

            return;
        }

        SyncDiff::create([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'external_key' => mb_substr($externalKey, 0, 190),
            'diff_type' => $diffType,
            'mrc_snapshot' => $mrcSnapshot,
            'external_snapshot' => $externalSnapshot,
            'summary' => mb_substr($summary, 0, 1000),
            'status' => SyncDiff::STATUS_PENDING,
            'detected_at' => now(),
        ]);
        $result['new']++;
    }

    /** Kanonik DTO üretimi duck-typing ile çözülür; mapper yoksa uydurma yapılmaz. */
    private function map(mixed $raw, string $kind): mixed
    {
        $methods = [
            'product' => ['toCanonicalProduct', 'product'],
            'price' => ['toCanonicalPrice', 'price'],
            'stock' => ['toCanonicalStock', 'stock'],
            'order' => ['toCanonicalOrder', 'order'],
        ][$kind] ?? [];

        foreach ($methods as $method) {
            if (! method_exists(TsoftMapper::class, $method)) {
                continue;
            }

            try {
                $mapped = TsoftMapper::{$method}($raw);
            } catch (Throwable) {
                continue;
            }

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (! is_scalar($id) || (int) $id < 1) {
                continue;
            }
            $normalized[(int) $id] = (int) $id;
        }

        return array_values($normalized);
    }

    private function adapter(int $companyId): mixed
    {
        $factory = $this->adapterFactory;

        if (is_callable($factory)) {
            return $factory($companyId);
        }

        return app(TsoftAdapter::class, ['company' => $companyId]);
    }

    private function ownership(): FieldOwnership
    {
        return $this->ownership ?? new FieldOwnership;
    }

    private function priceSync(): PriceSyncService
    {
        return $this->priceSync ?? new PriceSyncService($this->adapterFactory);
    }

    private function stockEngine(): ParallelStockEngine
    {
        return $this->stockEngine ?? new ParallelStockEngine($this->adapterFactory);
    }

    private function orderSync(): OrderSyncService
    {
        return $this->orderSync ?? new OrderSyncService;
    }

    /** @param array<string,mixed>|mixed $data @param array<int,string> $keys */
    private function pick(mixed $data, array $keys, mixed $default = null): mixed
    {
        if (! is_array($data)) {
            return $default;
        }

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

        return $default;
    }

    /** Para/miktar: her zaman string decimal (4 hane, nokta ayırıcı). */
    private function dec(mixed $value): string
    {
        $raw = trim((string) $value);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
        }
        $raw = str_replace(',', '.', $raw);

        if (! is_numeric($raw)) {
            $raw = '0';
        }

        return bcadd($raw, '0', 4);
    }
}
