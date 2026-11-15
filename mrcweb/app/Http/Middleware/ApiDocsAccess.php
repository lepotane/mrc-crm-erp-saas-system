<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API dokümanına erişim kapısı (/api-docs, /api-docs.json, /api-docs/postman).
 *
 * Sözleşme :
 *  - config('api.docs.enabled') kapalıysa uçlar YOKMU gibi davranır (404),
 *  - MRC_DOCS_KEY verilmişse ?key=<değer> (veya X-Docs-Key başlığı) şarttır,
 *  - anahtar boşsa oturum açmış bir kullanıcı yeterlidir; SPA aynı bearer ile
 *    /api-docs.json'u çekebilir, masaüstü istemci sözleşmeyi buradan alır.
 *
 * Yayında vendor'da Scramble bulunmaz (composer install --no-dev): doküman
 * public/docs/ altındaki statik artifact'lardan okunur, bu kapı yine geçerlidir.
 * Scramble'ın kendi docs/api uçları AppServiceProvider'da kapatılır.
 */
class ApiDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('api.docs.enabled'), 404);

        $key = (string) config('api.docs.key', '');

        if ($key !== '') {
            $given = (string) ($request->query('key') ?: $request->header('X-Docs-Key', ''));

            abort_unless(hash_equals($key, $given), 403, __('Doküman anahtarı geçersiz.'));

            return $next($request);
        }

        // Guard'i korumali oku: sanctum guard'ini tetiklemek token'siz istekte
        // AuthenticationException dogurur .
        if ($request->user('sanctum') === null && $request->user() === null) {
            abort(403, __('Dokümanı görmek için giriş yapın.'));
        }

        return $next($request);
    }
}
