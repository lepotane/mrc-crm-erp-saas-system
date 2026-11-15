<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Gizli SüperAdmin Güvenlik Katmanı.
 *
 * - Yalnızca veritabanında `is_superadmin = true` olan ve geçerli oturum jetonuna sahip
 *   SüperAdmin kullanıcıların geçişine izin verir.
 * - Yetkisiz veya sıradan bir kullanıcı bu uçları tararsa 403 yerine doğrudan
 *   standart 404 (Uç nokta bulunamadı) döndürerek panelin varlığını tamamen gizler.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            abort(404);
        }

        // Veritabanından taze doğrulama (bellek/oturum manipülasyonuna karşı koruma)
        $isSuper = User::query()->whereKey($user->id)->value('is_superadmin');

        if (! $isSuper) {
            abort(404);
        }

        $token = $user->currentAccessToken();
        if ($token && is_array($token->abilities)) {
            if (! in_array('*', $token->abilities, true) && ! in_array('superadmin:access', $token->abilities, true)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
