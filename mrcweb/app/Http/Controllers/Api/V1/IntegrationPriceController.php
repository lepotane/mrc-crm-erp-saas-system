<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Integration\Sync\PriceSyncService;
use Illuminate\Http\Request;

/**
 * Fiyat senkronizasyonu (YALNIZ Admin).
 *
 * Rotalar bu katmanın dışında bağlanır; controller {data: ...} sözleşmesini uygular.
 */
class IntegrationPriceController extends Controller
{
    use ResolvesTenant;

    /**
     * Fiyatlari iki yonlu senkronize et
     *
     * @response array{data: array{total:int,pushed:int,failed:int,skipped:int,details:array<int,array<string,mixed>>}}
     */
    public function sync(Request $request, PriceSyncService $service)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'direction' => ['required', 'string', 'in:in,out'],
            'skus' => ['sometimes', 'array'],
            'skus.*' => ['string'],
            'all' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $skus = [];
        foreach ($data['skus'] ?? [] as $sku) {
            $value = trim((string) $sku);
            if ($value !== '') {
                $skus[] = $value;
            }
        }

        $summary = $service->sync(
            $this->tenantId($request),
            (string) $data['direction'],
            $skus,
            $request->boolean('all'),
            $request->boolean('force'),
        );

        return response()->json(['data' => $summary]);
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()?->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
