<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    use ResolvesTenant;

    /**
     * Depoları listele (varsayılan depo işaretli)
     *
     * @response array{warehouses: array<int, array{id: int, name: string, is_default: bool, address: string|null}>}
     */
    public function index(Request $request)
    {
        $warehouses = Warehouse::where('company_id', $this->tenantId($request))
            ->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['warehouses' => $warehouses->map(fn (Warehouse $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'is_default' => $w->is_default,
            'address' => $w->address,
        ])]);
    }

    /**
     * Depodaki ürün stoğu : ürün başına anlık miktar.
     *
     * @response array{warehouse: array{id: int, name: string}, data: array<int, array{product_id: int, name: string, sku: string|null, barcode: string|null, stock: float}>}
     */
    public function products(Request $request, Warehouse $warehouse)
    {
        abort_if(
            $warehouse->company_id !== $this->tenantId($request),
            404,
            __('Depo bulunamadı.')
        );

        $companyId = $warehouse->company_id;

        $rows = \App\Models\Product::where('company_id', $companyId)
            ->where('track_inventory', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode']);

        return response()->json([
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'data' => $rows->map(fn ($p) => [
                'product_id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'stock' => round(\App\Models\StockMovement::stockFor($companyId, $p->id, $warehouse->id), 4),
            ])->values(),
        ]);
    }

    /**
     * Yeni depo aç
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{warehouse: array{id: int, name: string, is_default: bool, address: string|null}}',
    )]
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouse = DB::transaction(function () use ($data, $request) {
            $companyId = $this->tenantId($request);
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                Warehouse::where('company_id', $companyId)->update(['is_default' => false]);
            } elseif (Warehouse::where('company_id', $companyId)->count() === 0) {
                $isDefault = true; // ilk depo varsayılan
            }

            return Warehouse::create($data + ['company_id' => $companyId, 'is_default' => $isDefault]);
        });

        return response()->json(['warehouse' => $warehouse->only(['id', 'name', 'is_default', 'address'])], 201);
    }

    /**
     * Depo bilgisini güncelle
     *
     * @response array{warehouse: array{id: int, name: string, is_default: bool, address: string|null}}
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        abort_if($warehouse->company_id !== $this->tenantId($request), 404, __('Depo bulunamadı.'));

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $request, $warehouse) {
            if ($data['is_default'] ?? false) {
                Warehouse::where('company_id', $this->tenantId($request))
                    ->where('id', '!=', $warehouse->id)
                    ->update(['is_default' => false]);
            }
            $warehouse->update($data);
        });

        return response()->json(['warehouse' => $warehouse->refresh()->only(['id', 'name', 'is_default', 'address'])]);
    }

    /**
     * Depoyu sil (stok hareketi varsa silinemez)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Warehouse $warehouse)
    {
        abort_if($warehouse->company_id !== $this->tenantId($request), 404, __('Depo bulunamadı.'));

        $hasMovements = $warehouse->movements()->exists();
        if ($hasMovements) {
            return response()->json([
                'message' => __('Bu depoda stok hareketi mevcut; silinemez. Depoyu pasif hale getirmek için büyük depolara aktarım yapın.'),
            ], 422);
        }

        $wasDefault = $warehouse->is_default;
        $warehouse->delete();

        if ($wasDefault) {
            Warehouse::where('company_id', $this->tenantId($request))->first()?->update(['is_default' => true]);
        }

        return response()->json(['message' => __('Depo silindi.')]);
    }
}
