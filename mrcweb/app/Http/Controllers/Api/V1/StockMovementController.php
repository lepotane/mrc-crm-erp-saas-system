<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Support\ReportCache;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    use ResolvesTenant;
    use \App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;

    /**
     * Stok hareketlerini listele (ürün, depo, tip, tarih süzgeçleri, sayfalı)
     *
     * @response array{
     *     data: array<int,
     *     array{id: int, product_id: int, warehouse_id: int, product_name: string, warehouse_name: string, type: string, quantity: float, unit_cost: float, note: string|null, created_at: string}>,
     *     links: array<int,
     *     array{url: string|null, label: string, page: int|null, active: bool}>,
     *     meta: array{current_page: int, from: int, last_page: int, per_page: int, to: int, total: float},
     * }
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $query = StockMovement::query()
            ->where('stock_movements.company_id', $companyId)
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->selectRaw("stock_movements.*, products.name AS product_name, warehouses.name AS warehouse_name");

        if ($productId = $request->query('product_id')) {
            $query->where('stock_movements.product_id', (int) $productId);
        }
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('stock_movements.warehouse_id', (int) $warehouseId);
        }
        if ($type = $request->query('type')) {
            $query->where('stock_movements.type', in_array($type, ['in', 'out', 'adjust'], true) ? $type : 'in');
        }
        if ($from = $request->query('from')) {
            $query->whereDate('stock_movements.created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('stock_movements.created_at', '<=', $to);
        }

        $paginator = $query->orderByDesc('stock_movements.created_at')
            ->orderByDesc('stock_movements.id')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (StockMovement $m) => $this->present($m));

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Manuel stok hareketi gir: giriş, çıkış veya düzeltme
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{movement: array{id: int, product_id: int, warehouse_id: int, product_name: string|null, warehouse_name: string|null, type: string, quantity: float, unit_cost: float|null, note: string|null, created_at: string}, stock_quantity: float}',
    )]
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'type' => ['required', 'string', 'in:in,out,adjust'],
            'quantity' => ['required', 'numeric'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // in/out pozitif; adjust işaretli (sıfır olmayan) değer olur
        if ($data['type'] !== 'adjust' && (float) $data['quantity'] <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'quantity' => [__('Giriş/çıkış miktarı sıfırdan büyük olmalıdır.')],
            ]);
        }
        if ($data['type'] === 'adjust' && (float) $data['quantity'] == 0.0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'quantity' => [__('Düzeltme miktarı 0 olamaz.')],
            ]);
        }

        $movement = StockMovement::create($data + [
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
        ]);

        ReportCache::bump($companyId);

        return response()->json([
            'movement' => $this->present($movement->refresh()),
            'stock_quantity' => StockMovement::stockFor($companyId, (int) $data['product_id']),
        ], 201);
    }

    protected function present(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'product_id' => $movement->product_id,
            'warehouse_id' => $movement->warehouse_id,
            'product_name' => $movement->getAttribute('product_name')
                ?? $movement->product?->name,
            'warehouse_name' => $movement->getAttribute('warehouse_name')
                ?? $movement->warehouse?->name,
            'type' => $movement->type,
            'quantity' => (float) $movement->quantity,
            'unit_cost' => $movement->unit_cost !== null ? (float) $movement->unit_cost : null,
            'note' => $movement->note,
            'reference' => $movement->reference,
            'created_at' => $movement->created_at->toIso8601String(),
        ];
    }
}
