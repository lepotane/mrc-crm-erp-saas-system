<?php

namespace App\Integration\Sync;

use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Paralel tek stok motoru.
 *
 * MRC `stock_movements` matematiksel otoritedir; Tsoft hem ayna hem kaynaktır.
 * Not: WithoutOverlapping / Cache::lock bu sınıfta değil, komut veya job
 * seviyesinde uygulanır (tek bir yazımın kilit süresi iş katmanında tutulmaz).
 */
final class ParallelStockEngine
{
    public function __construct(private readonly mixed $adapterFactory = null) {}

    public function readMrc(int $companyId, string $sku, int $warehouseId): ?CanonicalStock
    {
        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        if ($product === null) {
            return null;
        }

        $state = DB::table('integration_stock_state')
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->first();

        return new CanonicalStock(
            $sku,
            $this->warehouseCode($companyId, $warehouseId),
            $this->decimalString(StockMovement::stockFor($companyId, $product->id, $warehouseId)),
            (int) ($state?->version ?? 0),
            (string) ($state?->source ?: 'mrc'),
            $this->externalRefFor($companyId, $product->id),
        );
    }

    /**
     * Dış sistemden gelen işaretli stok değişimini MRC'ye uygular.
     * Idempotency: (company_id, external_type, external_key) daha önce eşlenmişse
     * ikinci bir movement yazılmaz, mevcut durum döner.
     */
    public function applyInbound(
        int $companyId,
        string $sku,
        string $delta,
        string $source,
        string $externalRef,
        ?int $warehouseId = null,
    ): CanonicalStock {
        $externalType = 'stock_movement';

        return DB::transaction(function () use (
            $companyId, $sku, $delta, $source, $externalRef, $warehouseId, $externalType
        ) {
            $mapped = DB::table('integration_id_map')
                ->where('company_id', $companyId)
                ->where('external_type', $externalType)
                ->where('external_key', $externalRef)
                ->lockForUpdate()
                ->first();

            if ($mapped !== null) {
                return $this->currentState($companyId, $sku, $warehouseId, $source, $externalRef);
            }

            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('sku', $sku)
                ->first();

            if ($product === null) {
                throw new RuntimeException('Stok girişi için ürün bulunamadı: '.$sku);
            }

            $normalizedDelta = $this->decimalString($delta);
            $isOut = bccomp($normalizedDelta, '0', 4) < 0;
            $quantity = $isOut ? $this->decimalString(bcmul($normalizedDelta, '-1', 4)) : $normalizedDelta;

            StockMovement::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => $isOut ? StockMovement::TYPE_OUT : StockMovement::TYPE_IN,
                'quantity' => $quantity,
                'note' => 'integration inbound',
                'reference' => $externalRef,
            ]);

            $quantityNow = $this->decimalString(
                StockMovement::stockFor($companyId, $product->id, $warehouseId)
            );

            $stateRow = DB::table('integration_stock_state')
                ->where('company_id', $companyId)
                ->where('sku', $sku)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();
            $version = ((int) ($stateRow?->version ?? 0)) + 1;
            $owner = strtolower(trim($source));

            IntegrationStockState::updateOrCreate(
                ['company_id' => $companyId, 'sku' => $sku, 'warehouse_id' => $warehouseId],
                [
                    'quantity' => $quantityNow,
                    'version' => $version,
                    'source' => $owner,
                    'synced_at' => now(),
                ],
            );

            IntegrationIdMap::create([
                'company_id' => $companyId,
                'mrc_type' => 'product',
                'mrc_id' => $product->id,
                'external_type' => $externalType,
                'external_key' => $externalRef,
            ]);

            return new CanonicalStock(
                $sku,
                $this->warehouseCode($companyId, $warehouseId),
                $quantityNow,
                $version,
                $owner,
                $externalRef,
            );
        }, 3);
    }

    /** Ayna yazımı. dry_run ise dış servise YAZILMAZ, yalnızca loglanır. */
    public function pushOutbound(int $companyId, CanonicalStock $stock): SyncResult
    {
        // Kilit/component sarma (WithoutOverlapping) komut/job seviyesindedir.
        if (config('integrations.dry_run')) {
            Log::info('integration.stock.push.skipped_dry_run', [
                'company_id' => $companyId,
                'sku' => $stock->sku,
                'quantity' => $stock->quantity,
            ]);

            return SyncResult::skipped('dry_run: dış stok yazımı atlandı.');
        }

        try {
            $adapter = $this->adapter($companyId);

            if (! is_object($adapter) || ! method_exists($adapter, 'pushStock')) {
                return SyncResult::failure('Stok adapteri kullanilamiyor.');
            }

            $result = $adapter->pushStock($stock);
        } catch (Throwable) {
            Log::warning('integration.stock.push.failed', [
                'company_id' => $companyId,
                'sku' => $stock->sku,
            ]);

            return SyncResult::failure('Stok yazımı başarısız.');
        }

        if (! $result instanceof SyncResult) {
            return SyncResult::failure('Stok yazımı beklenmeyen sonuç döndürdü.');
        }

        if ($result->ok && ! $result->skipped) {
            DB::table('integration_stock_state')
                ->where('company_id', $companyId)
                ->where('sku', $stock->sku)
                ->update(['synced_at' => now()]);
        }

        return $result;
    }

    private function currentState(
        int $companyId,
        string $sku,
        ?int $warehouseId,
        string $source,
        string $externalRef,
    ): CanonicalStock {
        $state = DB::table('integration_stock_state')
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $product = Product::query()
            ->where('company_id', $companyId)
            ->where('sku', $sku)
            ->first();

        $quantity = $product === null
            ? $this->decimalString($state?->quantity ?? '0')
            : $this->decimalString(StockMovement::stockFor($companyId, $product->id, $warehouseId));

        return new CanonicalStock(
            $sku,
            $this->warehouseCode($companyId, $warehouseId),
            $quantity,
            (int) ($state?->version ?? 0),
            (string) ($state?->source ?: strtolower(trim($source))),
            $externalRef,
        );
    }

    private function adapter(int $companyId): mixed
    {
        $factory = $this->adapterFactory;

        if (is_callable($factory)) {
            return $factory($companyId);
        }

        return app(TsoftAdapter::class, ['company' => $companyId]);
    }

    private function decimalString(mixed $value): string
    {
        $raw = str_replace(',', '.', (string) $value);

        if (! is_numeric($raw)) {
            $raw = '0';
        }

        return bcadd($raw, '0', 4);
    }

    private function warehouseCode(int $companyId, ?int $warehouseId): string
    {
        if ($warehouseId === null) {
            return 'default';
        }

        if (Schema::hasColumn('warehouses', 'code')) {
            $code = DB::table('warehouses')
                ->where('company_id', $companyId)
                ->where('id', $warehouseId)
                ->value('code');

            if ($code !== null && $code !== '') {
                return (string) $code;
            }
        }

        return (string) $warehouseId;
    }

    private function externalRefFor(int $companyId, int $productId): ?string
    {
        $key = DB::table('integration_id_map')
            ->where('company_id', $companyId)
            ->where('mrc_type', 'product')
            ->where('mrc_id', $productId)
            ->where('external_type', 'product')
            ->value('external_key');

        return $key !== null && $key !== '' ? (string) $key : null;
    }
}
