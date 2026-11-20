<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integration\Health\HealthService;
use Illuminate\Http\Request;

/**
 * Entegrasyon sağlık özeti (YALNIZ Admin).
 *
 * Rotalar bu katmanın dışında bağlanır; controller yalnızca {data: ...} sözleşmesini uygular.
 */
class IntegrationHealthController extends Controller
{
    /**
     * @response array{data: array<string, array<string,mixed>>}
     */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json(['data' => HealthService::snapshot()]);
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()?->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
