<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Support\Audit;
use App\Support\ReportCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Depo transferi : tek işlemde kaynak depodan çıkış + hedef depoya giriş.
 *
 * İki hareket `reference = transfer:<id>` ile bağlıdır; toplam stok değişmez
 * (kabul testi kilitler). Kaynakta yeterli stok YOKSA transfer reddedilir —
 * iç taşıma fiziksel eksiyi üretemez (satıştaki negatif-serbest kural buraya
 * taşınmaz: belge değil, malın kendisidir).
 */
class StockTransferController extends Controller
{
    use Concerns\ResolvesTenant;
    use Concerns\PresentsPaginator;

    /**
     * Depolar arası transferleri listele.
     *
     * @response array{data: array<int, array{id: int, product_id: int, product_name: string, from_warehouse_id: int, from_warehouse_name: string, to_warehouse_id: int, to_warehouse_name: string, quantity: float, note: string|null, created_at: string}>, links: array, meta: array}
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $paginator = WarehouseTransfer::query()
            ->where('warehouse_transfers.company_id', $companyId)
            ->join('products as p', 'p.id', '=', 'warehouse_transfers.product_id')
            ->join('warehouses as fw', 'fw.id', '=', 'warehouse_transfers.from_warehouse_id')
            ->join('warehouses as tw', 'tw.id', '=', 'warehouse_transfers.to_warehouse_id')
            ->selectRaw('warehouse_transfers.*, p.name AS product_name, fw.name AS from_warehouse_name, tw.name AS to_warehouse_name')
            ->orderByDesc('warehouse_transfers.id')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (WarehouseTransfer $t) => [
            'id' => $t->id,
            'product_id' => $t->product_id,
            'product_name' => $t->getAttribute('product_name'),
            'from_warehouse_id' => $t->from_warehouse_id,
            'from_warehouse_name' => $t->getAttribute('from_warehouse_name'),
            'to_warehouse_id' => $t->to_warehouse_id,
            'to_warehouse_name' => $t->getAttribute('to_warehouse_name'),
            'quantity' => (float) $t->quantity,
            'note' => $t->note,
            'created_at' => $t->created_at->toIso8601String(),
        ]);

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Depo transferi yap (tek işlemde out+in; toplam korunur).
     *
     * @response array{transfer: array{id: int, quantity: float}, source_stock: float, dest_stock: float, message: string}
     */
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'from_warehouse_id' => ['required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'to_warehouse_id' => ['required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ((int) $data['from_warehouse_id'] === (int) $data['to_warehouse_id']) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => [__('Kaynak ve hedef depo aynı olamaz.')],
            ]);
        }

        $qty = round((float) $data['quantity'], 4);
        $sourceStock = StockMovement::stockFor($companyId, (int) $data['product_id'], (int) $data['from_warehouse_id']);

        if ($sourceStock < $qty) {
            throw ValidationException::withMessages([
                'quantity' => [__('Kaynak depoda yeterli stok yok.')],
            ]);
        }

        $product = Product::where('company_id', $companyId)->findOrFail($data['product_id']);
        $unitCost = round((float) $product->cost_price, 4);

        [$transfer, $sourceAfter, $destAfter] = \Illuminate\Support\Facades\DB::transaction(
            function () use ($request, $companyId, $data, $qty, $unitCost) {
                $transfer = WarehouseTransfer::create([
                    'company_id' => $companyId,
                    'product_id' => $data['product_id'],
                    'from_warehouse_id' => $data['from_warehouse_id'],
                    'to_warehouse_id' => $data['to_warehouse_id'],
                    'quantity' => $qty,
                    'note' => $data['note'] ?? null,
                    'user_id' => $request->user()->id,
                ]);

                $reference = 'transfer:'.$transfer->id;
                $note = ! empty($data['note'])
                    ? $data['note']." ({$reference})"
                    : __('Transfer')." ({$reference})";

                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $data['product_id'],
                    'warehouse_id' => $data['from_warehouse_id'],
                    'type' => StockMovement::TYPE_OUT,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'note' => $note,
                    'reference' => $reference,
                    'user_id' => $request->user()->id,
                ]);

                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $data['product_id'],
                    'warehouse_id' => $data['to_warehouse_id'],
                    'type' => StockMovement::TYPE_IN,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'note' => $note,
                    'reference' => $reference,
                    'user_id' => $request->user()->id,
                ]);

                return [
                    $transfer,
                    StockMovement::stockFor($companyId, (int) $data['product_id'], (int) $data['from_warehouse_id']),
                    StockMovement::stockFor($companyId, (int) $data['product_id'], (int) $data['to_warehouse_id']),
                ];
            }
        );

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'stock_transfer.created', $companyId, $transfer, [
            'product_id' => $transfer->product_id,
            'quantity' => $qty,
        ]);

        return response()->json([
            'transfer' => ['id' => $transfer->id, 'quantity' => (float) $transfer->quantity],
            'source_stock' => (float) $sourceAfter,
            'dest_stock' => (float) $destAfter,
            'message' => __('Transfer tamamlandı.'),
        ], 201);
    }
}
