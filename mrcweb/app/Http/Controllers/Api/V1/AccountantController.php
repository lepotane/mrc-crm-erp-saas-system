<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integration\Gib\ResmilesTirService;
use App\Integration\Luca\LucaAdapter;
use App\Integration\Support\CredentialVault;
use App\Models\AccountantTransfer;
use App\Models\Document;
use App\Models\GibDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only accounting panel plus an explicit approve action.
 * Routes are intentionally not registered here.
 */
class AccountantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAccountant($request);
        $companyId = $this->companyId($request);

        $pendingIds = GibDocument::where('company_id', $companyId)
            ->whereIn('status', ['draft', 'official_pending'])
            ->pluck('document_id')
            ->filter()
            ->all();

        $vouchers = Document::where('company_id', $companyId)
            ->where(function ($query) use ($pendingIds) {
                $query->where('status', 'draft');
                if ($pendingIds !== []) {
                    $query->orWhereIn('id', $pendingIds);
                }
            })
            ->latest('issue_date')
            ->limit(200)
            ->get(['id', 'type', 'serial', 'status', 'issue_date', 'grand_total', 'tax_total', 'currency_code', 'notes']);

        return response()->json([
            'vouchers' => $vouchers,
            'account_map' => $this->accountMap($companyId),
            'transfers' => AccountantTransfer::where('company_id', $companyId)
                ->latest()
                ->limit(100)
                ->get(),
            'gib_documents' => GibDocument::where('company_id', $companyId)
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    /** Approve and hand selected vouchers to the configured Luca driver. */
    public function approve(Request $request, ResmilesTirService $vouchers, CredentialVault $vault): JsonResponse
    {
        $this->ensureAccountant($request);
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1'],
            'voucher_ids.*' => ['integer', 'min:1'],
        ]);

        $adapter = new LucaAdapter($companyId, $vault);
        $succeeded = 0;
        $failed = 0;
        $errors = [];

        foreach (array_unique($validated['voucher_ids']) as $voucherId) {
            try {
                $canonical = $vouchers->toCanonicalVoucher($companyId, (int) $voucherId);
                $result = $adapter->pushVoucher($canonical);
                if ($result->ok) {
                    $succeeded++;
                } else {
                    $failed++;
                    $errors[] = ['voucher_id' => $voucherId, 'message' => $result->message];
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['voucher_id' => $voucherId, 'message' => $e->getMessage()];
            }
        }

        $driver = DB::table('tenant_integrations')
            ->where('company_id', $companyId)
            ->where('channel', 'luca')
            ->value('driver') ?: 'file';

        AccountantTransfer::create([
            'company_id' => $companyId,
            'voucher_ids' => array_values(array_unique($validated['voucher_ids'])),
            'driver' => in_array($driver, ['file', 'rest', 'soap'], true) ? $driver : 'file',
            'status' => $failed === 0 ? 'transferred' : ($succeeded > 0 ? 'partial' : 'failed'),
            'approved_by' => $request->user()?->id,
            'transferred_at' => $succeeded > 0 ? now() : null,
            'error' => $errors === [] ? null : json_encode($errors, JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json([
            'total' => count($validated['voucher_ids']),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /** @return array<string,mixed> */
    private function accountMap(int $companyId): array
    {
        $rows = DB::table('tenant_integrations')
            ->where('company_id', $companyId)
            ->whereNotNull('settings')
            ->pluck('settings');

        foreach ($rows as $raw) {
            $settings = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            if (is_array($settings) && is_array($settings['account_map'] ?? null)) {
                return $settings['account_map'];
            }
        }

        return [];
    }

    private function ensureAccountant(Request $request): void
    {
        $user = $request->user();
        $allowed = false;

        if ($user !== null && method_exists($user, 'hasRole')) {
            $allowed = $user->hasRole('Admin') || $user->hasRole('MaliMüşavir');
        }

        if (! $allowed && $user !== null) {
            foreach (($user->roles ?? []) as $role) {
                if (in_array(data_get($role, 'name'), ['Admin', 'MaliMüşavir'], true)) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (! $allowed) {
            abort(403, 'Bu işlem için mali müşavir veya yönetici yetkisi gereklidir.');
        }
    }

    private function companyId(Request $request): int
    {
        $companyId = (int) ($request->user()?->current_company_id ?? 0);
        if ($companyId === 0) {
            abort(403, 'Aktif firma seçilmemiş.');
        }

        return $companyId;
    }
}
