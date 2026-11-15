<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * PAT (erişim anahtarı) yetenek denetimi — Faz 5 masaüstü istemci sözleşmesi.
 *
 * SPA ve masaüstü istemcisi Sanctum bearer anahtarı kullanır. Anahtarın
 * yetenekleri: '*' tam yetki, 'read' yalnız okuma, 'write' okuma + işlem.
 * Bu uç, oturum/anahtar fark etmeksizin GET dışı istekleri 'write' ya da '*'
 * isteyerek geçirir; böylece yalnız okumak için verilmiş bir anahtar yanlışlıkla
 * kayıt yazamaz (403 + dile göre çevrilmiş mesaj).
 *
 * Yetenekler anahtar üretiminde sabitlenir; sonradan değiştirilmez
 * (revoked → yenisi oluşturulur).
 */
class CheckTokenAbilities
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();

        // Oturum (session) veya yetenek taşımayan geçişler serbest.
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $abilities = $token->abilities ?: ['*'];

        if (in_array('*', $abilities, true)) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('OPTIONS')) {
            return in_array('read', $abilities, true) || in_array('write', $abilities, true)
                ? $next($request)
                : $this->deny();
        }

        return in_array('write', $abilities, true) ? $next($request) : $this->deny();
    }

    private function deny()
    {
        return response()->json([
            'message' => __('Bu erişim anahtarının bu işlem için yetkisi yok.'),
        ], 403);
    }
}
