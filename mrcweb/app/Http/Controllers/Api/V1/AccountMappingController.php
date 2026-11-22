<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integration\Accountant\AccountMappingService;
use App\Integration\Accountant\LucaAccountPlanImporter;
use App\Models\AccountMapping;
use App\Models\LucaAccountPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Hesap planı / hesap eşleme paneli (salt okunur liste + içe aktarma + eşleme).
 * Rotalar bilinçli olarak kayıtlı değildir; bağlama işlemi dışarıdan yapılır.
 */
class AccountMappingController extends Controller
{
    /**
     * Hesap plani ve eslemeleri listele
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAccountant($request);
        $companyId = $this->companyId($request);

        return response()->json([
            'plan' => LucaAccountPlan::where('company_id', $companyId)
                ->orderBy('code')
                ->limit(200)
                ->get(['id', 'code', 'name', 'parent_code', 'is_active', 'source', 'external_ref', 'synced_at']),
            'mappings' => AccountMapping::where('company_id', $companyId)
                ->orderBy('document_type')
                ->orderBy('role')
                ->limit(200)
                ->get(['id', 'document_type', 'role', 'account_code', 'source', 'notes']),
            'synced' => LucaAccountPlan::upToDate($companyId),
        ]);
    }

    /**
     * Luca hesap planini ice aktar Dosya yüklemesi veya ham içerik ile hesap planını içe aktarır. */
    public function import(Request $request): JsonResponse
    {
        $this->ensureAccountant($request);
        $companyId = $this->companyId($request);

        $request->validate([
            'content' => ['required_without:file', 'string', 'max:2097152'], // 2 MB (karakter)
            'file' => ['required_without:content', 'file', 'max:2048'], // 2 MB (KB)
        ]);

        $extension = 'csv';
        $content = (string) ($request->input('content') ?? '');
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $content = (string) $file->get();
            $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'csv';
        }

        $parsed = (new LucaAccountPlanImporter)->parse($content, $extension);
        $summary = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => $parsed['errors']];

        if ($parsed['rows'] !== []) {
            $imported = (new AccountMappingService)->importPlan($companyId, $parsed['rows']);
            $summary = [
                'total' => $imported['total'],
                'imported' => $imported['imported'],
                'skipped' => $imported['skipped'],
                'errors' => array_merge($parsed['errors'], $imported['errors']),
            ];
        }

        return response()->json($summary);
    }

    /**
     * Hesap eslemesi kaydet Belge tipi + rol için hesap eşlemesi kaydeder. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAccountant($request);
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'role' => ['required', 'string', 'in:debit,credit,tax,counter'],
            'account_code' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $mapping = (new AccountMappingService)->setMapping(
                $companyId,
                (string) $validated['document_type'],
                (string) $validated['role'],
                (string) $validated['account_code'],
                isset($validated['notes']) ? (string) $validated['notes'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($mapping, 201);
    }

    /**
     * Hesap kodu onerileri Plan satırı adlarına göre (uygulanmayan) hesap kodu önerileri. */
    public function suggest(Request $request): JsonResponse
    {
        $this->ensureAccountant($request);
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
        ]);

        return response()->json([
            'document_type' => $validated['document_type'],
            'suggestions' => (new AccountMappingService)->suggestFromPlan($companyId, (string) $validated['document_type']),
        ]);
    }

    /** AccountantController ile aynı yetki modeli. */
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
