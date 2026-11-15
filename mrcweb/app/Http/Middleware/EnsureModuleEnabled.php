<?php

namespace App\Http\Middleware;

use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;

/**
 * Kapalı modülün API yüzeyini kapatır .
 *
 * `company.context` SONRASINDA koşar (grup sırası routes/api.php'dedir):
 * kiracı çözülmüş olur. Çözüm: istek yolu → modül anahtarı
 * (`ModuleRegistry::moduleForPath`); firma için kapalıysa **403**
 * `{message, code: module_disabled, module}` döner. `code` makine içindir
 * (SPA menü/ızgara bu koda bakar); `message` çevrilmiştir, ekrana basılır.
 *
 * Satır yoksa modül AÇIK varsayılır — eski firmalar etkilenmez.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $path = (string) $request->path();

        if (! str_starts_with($path, 'api/v1/')) {
            return $next($request);
        }

        $key = ModuleRegistry::moduleForPath(substr($path, 7));

        if ($key === null) {
            return $next($request);
        }

        $companyId = $request->user()?->current_company_id;

        if (! ModuleRegistry::isEnabled($companyId === null ? null : (int) $companyId, $key)) {
            return response()->json([
                'message' => __('Bu modül kapalı.'),
                'code' => 'module_disabled',
                'module' => $key,
            ], 403);
        }

        return $next($request);
    }
}
