<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * İstek bazlı dili çözer: kullanıcı tercihi (users.locale) → X-Locale başlığı
 * → Accept-Language → config varsayılanı. Çözülen dil app()->setLocale ile
 * uygulanır; doğrulama ve `__()` mesajları bu dile göre üretilir.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(static::resolveFor($request));

        return $next($request);
    }

    /**
     * İsteğin dilini çözer (statik: hata işleyicisi de çağırabilir).
     *
     * Neden statik olsun: rota EŞLEŞMEYEN api isteklerinde middleware hiç
     * çalışmaz; hata mesajı o zaman bir önceki isteğin dilinden geliyordu
     * (process başına "sızıntı"). Hata işleyicisi bu çözümleyiciyi doğrudan
     * çağırarak mesajı HER ZAMAN o isteğin diline göre üretir.
     */
    public static function resolveFor(Request $request): string
    {
        $supported = config('i18n.supported', ['tr']);
        $preferred = null;

        // Guard-bağımsız kullanıcı okuması: auth.defaults.guard test/uzun ömürlü
        // süreçlerde sanctum'a mutasyona uğrayabiliyor  — sanctum
        // guard'ı önce denenir, olmazsa varsayılana düşülür.
        $user = $request->user('sanctum') ?? $request->user();
        if ($user !== null && filled($user->locale ?? null)) {
            $preferred = $user->locale;
        }

        if ($preferred === null && ($header = $request->header(config('i18n.header', 'X-Locale')))) {
            $preferred = $header;
        }

        if ($preferred === null && $request->headers->has('Accept-Language')) {
            // getPreferredLanguage() Accept-Language YOKKEN supported listesinin
            // bir üyesini döndürüyor (Symfony davranışı: 'en') → başlık yoksa
            // hiç bakmayız, varsayılan dil (Türkçe) korunur.
            $preferred = str_contains((string) $request->getPreferredLanguage($supported), 'en')
                ? 'en'
                : null;
        }

        foreach ((array) $preferred as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            $base = explode('-', $candidate)[0] ?? $candidate;

            if (in_array($base, $supported, true)) {
                return $base;
            }
        }

        return config('i18n.default', 'tr');
    }
}
