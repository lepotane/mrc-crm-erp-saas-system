<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;

/**
 * Bridge agent kimliği : `Authorization: Bearer <cihaz-anahtarı>`.
 *
 * Sanctum kullanıcısı YOKTUR; anahtar cihaz satırındaki özetle eşleşir.
 * Kiracı cihazdan çözülür (`company_id`). Her istek nabzı tazeler
 * (`last_seen_at` — çevrimiçi göstergesi buradan okunur).
 */
class EnsureBridgeDevice
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        $device = $token !== null
            ? Device::where('token_hash', hash('sha256', $token))->first()
            : null;

        if ($device === null) {
            return response()->json(['message' => __('Kimlik doğrulaması gerekli.')], 401);
        }

        $device->update(['last_seen_at' => now()]);
        $request->attributes->set('bridge_device', $device->refresh());

        return $next($request);
    }
}
