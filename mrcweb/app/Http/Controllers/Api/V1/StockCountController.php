<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Support\Audit;
use App\Support\ReportCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stok sayım oturumları .
 *
 * Akış: `POST /stock-counts` (depo + satırlar; beklenen stok snapshot'lanır,
 * `draft`) → rafları say → `POST .../approve` (farklar `adjust` hareketi olur).
 * Onay YALNIZ Admin (yetkili rol kuralı). İptal yalnız `draft` iken
 * (açan ya da Admin).
 */
class StockCountController extends Controller
{
    use Concerns\ResolvesTenant;
    use Concerns\PresentsPaginator;

    /**
     * Sayım oturumlarını listele.
     */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $query = StockCount::query()
            ->where('stock_counts.company_id', $companyId)
            ->join('warehouses as w', 'w.id', '=', 'stock_counts.warehouse_id')
            ->selectRaw('stock_counts.*, w.name AS warehouse_name');

        if ($status = $request->query('status')) {
            $query->where('stock_counts.status', in_array($status, ['draft', 'approved', 'cancelled'], true) ? $status : 'draft');
        }

        $paginator = $query->orderByDesc('stock_counts.id')
            ->paginate(min((int) ($request->query('per_page', 20) ?: 20), 100))
            ->withQueryString();

        $paginator->getCollection()->transform(fn (StockCount $c) => $this->presentRow($c));

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Sayım detayı: satırlar + farklar.
     *
     * @response array{count: array, lines: array}
     */
    public function show(Request $request, StockCount $count)
    {
        $this->authorizeCount($request, $count);
        $count->load(['lines.product:id,name,sku', 'warehouse:id,name']);

        return response()->json([
            'count' => $this->presentRow($count),
            'lines' => $count->lines->map(fn ($l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'product_name' => $l->product?->name,
                'expected_qty' => (float) $l->expected_qty,
                'counted_qty' => (float) $l->counted_qty,
                'diff' => round((float) $l->counted_qty - (float) $l->expected_qty, 4),
            ])->values(),
        ]);
    }

    /**
     * Sayım taslağı aç (beklenen stok snapshot'lanır).
     *
     * @response array{count: array, lines: array, message: string}
     */
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'lines.*.counted_qty' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $count = DB::transaction(function () use ($request, $companyId, $data) {
            $count = StockCount::create([
                'company_id' => $companyId,
                'warehouse_id' => $data['warehouse_id'],
                'status' => StockCount::STATUS_DRAFT,
                'note' => $data['note'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            $seen = [];

            foreach ($data['lines'] as $row) {
                $pid = (int) $row['product_id'];

                if (isset($seen[$pid])) {
                    throw ValidationException::withMessages([
                        'lines' => [__('Aynı ürün sayımda iki kez olamaz.')],
                    ]);
                }
                $seen[$pid] = true;

                $count->lines()->create([
                    'product_id' => $pid,
                    'expected_qty' => StockMovement::stockFor($companyId, $pid, (int) $data['warehouse_id']),
                    'counted_qty' => round((float) $row['counted_qty'], 4),
                ]);
            }

            return $count;
        });

        Audit::fromRequest($request, 'stock_count.created', $companyId, $count, [
            'warehouse_id' => $count->warehouse_id,
        ]);

        $count->load(['lines.product:id,name,sku', 'warehouse:id,name']);

        return response()->json([
            'count' => $this->presentRow($count),
            'lines' => $count->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'product_name' => $l->product?->name,
                'expected_qty' => (float) $l->expected_qty,
                'counted_qty' => (float) $l->counted_qty,
                'diff' => round((float) $l->counted_qty - (float) $l->expected_qty, 4),
            ])->values(),
            'message' => __('Sayım taslağı oluşturuldu.'),
        ], 201);
    }

    /**
     * Farkları düzeltme hareketine çevir (YALNIZ Admin).
     *
     * @response array{message: string, adjustments: int}
     */
    public function approve(Request $request, StockCount $count)
    {
        $this->authorizeCount($request, $count);

        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        if ($count->status !== StockCount::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => [__('Yalnız taslak sayım onaylanabilir.')],
            ]);
        }

        $companyId = $this->tenantId($request);
        $count->load('lines');

        $adjustments = DB::transaction(function () use ($request, $companyId, $count) {
            StockCount::where('id', $count->id)->lockForUpdate()->first();
            $count->refresh();

            if ($count->status !== StockCount::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [__('Yalnız taslak sayım onaylanabilir.')],
                ]);
            }

            $n = 0;

            foreach ($count->diffs() as $entry) {
                /** @var \App\Models\StockCountLine $line */
                $line = $entry['line'];
                $product = Product::where('company_id', $companyId)->find($line->product_id);

                if ($product === null) {
                    continue;
                }

                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $line->product_id,
                    'warehouse_id' => $count->warehouse_id,
                    'type' => StockMovement::TYPE_ADJUST,
                    'quantity' => $entry['diff'],
                    'unit_cost' => round((float) $product->cost_price, 4),
                    'note' => __('Sayım').' #'.$count->id,
                    'reference' => 'count:'.$count->id,
                    'user_id' => $request->user()->id,
                ]);

                $n++;
            }

            $count->update(['status' => StockCount::STATUS_APPROVED]);

            return $n;
        });

        ReportCache::bump($companyId);
        Audit::fromRequest($request, 'stock_count.approved', $companyId, $count, [
            'adjustments' => $adjustments,
        ]);

        return response()->json([
            'message' => __('Sayım onaylandı, farklar işlendi.'),
            'adjustments' => $adjustments,
        ]);
    }

    /**
     * Sayımı iptal et (yalnız taslak; açan ya da Admin).
     *
     * @response array{message: string}
     */
    public function cancel(Request $request, StockCount $count)
    {
        $this->authorizeCount($request, $count);

        if ($count->status !== StockCount::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => [__('Yalnız taslak sayım iptal edilebilir.')],
            ]);
        }

        $user = $request->user();

        if ((int) $count->user_id !== (int) $user->id && ! $user->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $count->update(['status' => StockCount::STATUS_CANCELLED]);

        Audit::fromRequest($request, 'stock_count.cancelled', $this->tenantId($request), $count, []);

        return response()->json(['message' => __('Sayım iptal edildi.')]);
    }

    protected function authorizeCount(Request $request, StockCount $count): void
    {
        abort_if(
            $count->company_id !== $this->tenantId($request),
            404,
            __('Kayıt bulunamadı.')
        );
    }

    protected function presentRow(StockCount $count): array
    {
        return [
            'id' => $count->id,
            'warehouse_id' => $count->warehouse_id,
            'warehouse_name' => $count->getAttribute('warehouse_name') ?? $count->warehouse?->name,
            'status' => $count->status,
            'note' => $count->note,
            'lines_count' => $count->relationLoaded('lines')
                ? $count->lines->count()
                : $count->lines()->count(),
            'created_at' => $count->created_at->toIso8601String(),
        ];
    }
}
