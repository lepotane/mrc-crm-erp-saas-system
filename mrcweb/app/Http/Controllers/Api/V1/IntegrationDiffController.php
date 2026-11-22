<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PresentsPaginator;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Integration\Sync\SyncDiffService;
use App\Models\SyncDiff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Sync center fark listesi (YALNIZCA Admin).
 *
 * Tsoft bu katmanda YALNIZCA OKUNUR; import yalnızca MRC'ye yazar.
 * Rotalar bu katmanın dışında bağlanır.
 */
final class IntegrationDiffController extends Controller
{
    use PresentsPaginator;
    use ResolvesTenant;

    public function __construct(private readonly SyncDiffService $diffs) {}

    /**
     * Tsoft farklarini listele
     * Bekleyen (veya filtrelenmiş) farklar — {data,links,meta} sözleşmesi.
     *
     * @response array{
     *     data: array<int,array<string,mixed>>,
     *     links: array,
     *     meta: array{current_page:int,from:int,last_page:int,per_page:int,to:int,total:int}
     * }
     */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);
        $companyId = $this->tenantId($request);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(SyncDiff::STATUSES)],
            'entity_type' => ['sometimes', 'nullable', Rule::in(SyncDiff::TYPES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SyncDiff::query()
            ->where('company_id', $companyId)
            // Snapshot alanları listeye dahil edilmez (ağır/kişisel veri riski).
            ->select([
                'id', 'company_id', 'entity_type', 'external_key', 'diff_type',
                'summary', 'status', 'detected_at', 'resolved_at', 'created_at', 'updated_at',
            ])
            ->when(
                $filters['status'] ?? SyncDiff::STATUS_PENDING,
                fn ($q, string $status) => $q->where('status', $status)
            )
            ->when(
                $filters['entity_type'] ?? null,
                fn ($q, string $type) => $q->where('entity_type', $type)
            );

        $paginator = $query->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json($this->paginateJson($paginator));
    }

    /**
     * Fark ozetini getir Fark sayımları (snapshot içermez). */
    public function summary(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json(['data' => $this->diffs->summary($this->tenantId($request))]);
    }

    /**
     * Tsoftu tarayip farklari tespit et Tsoft'u okuyup farkları tespit eder (dry_run açıkken de okur). */
    public function scan(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json([
            'data' => $this->diffs->scan($this->tenantId($request)),
        ]);
    }

    /**
     * Secili farklari MRCye aktar Seçili farkları MRC'ye aktarır; Tsoft'a yazmaz. */
    public function import(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->diffs->import($this->tenantId($request), $data['ids']),
        ]);
    }

    /**
     * Secili farklari yok say Seçili farkları kullanıcı onayıyla yok sayar. */
    public function ignore(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->diffs->ignore($this->tenantId($request), $data['ids']),
        ]);
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()?->hasRole('Admin')) {
            abort(HttpResponse::HTTP_FORBIDDEN, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
