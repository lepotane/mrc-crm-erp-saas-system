<?php

use App\Support\ApiErrorMessages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // /api/* için hız sınırı (RateLimiter 'api' → AppServiceProvider + config/api.php).
        // Bütçe anahtarı erişim anahtarı özetidir; token yoksa IP.
        $middleware->throttleApi();

        // ÖNEMLİ: Laravel'in SortedMiddleware'ı `auth:sanctum`u öncelik listesi
        // yüzünden throttle:api'den önceye çekiyordu → kimliksiz ya da yanlış
        // token'lı istekler hiç sayılmıyordu (brute-force throttle dışı kalırdı).
        // `prependToPriorityList()` işe yaramıyor: ThrottleRequests listede zaten
        // var olduğu için yer değiştirmiyor. Bu yüzden liste baştan veriliyor ve
        // throttle, kimlik denetiminden (AuthenticatesRequests) ÖNCE sıralanıyor.
        // Laravel yükseltmelerinde bu liste framework varsayılanıyla karşılaştırılmalı.
        $middleware->priority([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        $middleware->alias([
            'company.context' => \App\Http\Middleware\CompanyContext::class,
            'module.enabled' => \App\Http\Middleware\EnsureModuleEnabled::class,
            'locale' => \App\Http\Middleware\SetLocale::class,
            'token.scope' => \App\Http\Middleware\CheckTokenAbilities::class,
            'bridge.device' => \App\Http\Middleware\EnsureBridgeDevice::class,
            'docs.access' => \App\Http\Middleware\ApiDocsAccess::class,
            'report.cache' => \App\Http\Middleware\CacheReports::class,
            'superadmin.stealth' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);

        // /api/* her zaman JSON; misafir yönlendirmesi web tarafında köke (SPA login'a düşer)
        // SetLocale: girişsiz uçlarda X-Locale / Accept-Language ile dil çözülür;
        // kimlikli grupta kullanıcı tercihi (users.locale) ayrıca çalışır.
        $middleware->api(prepend: [\App\Http\Middleware\ForceJson::class, \App\Http\Middleware\SetLocale::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API isteklerinde 401 her zaman JSON; SPA sayfalarında köke yönlendir.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                app()->setLocale(\App\Http\Middleware\SetLocale::resolveFor($request));

                return response()->json(['message' => __('Kimlik doğrulaması gerekli.')], 401);
            }

            return redirect('/');
        });

        // API hata gövdesi HER ZAMAN {"message": "..."} sözdizimindedir .
        // Route-model binding başarısız olduğunda Laravel'in ham mesajı
        // ("No query results for model [App\Models\Product] 999999") ve APP_DEBUG açıksa
        // stack trace de gövdeye doluyordu; eşleşmeyen api yolları ise SPA karşılama
        // rotasına düşüp 200 HTML alıyordu. Kiracı sırları ve istemci çözümlemesi için
        // bundan böyle çerçevenin gürültüsü yerine çevrilmiş, kısa bir mesaj döner.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api', 'api/*') && ! $request->expectsJson()) {
                return null; // SPA sayfalarinda varsayilan davranis (SPA karsilayici rotasi) korunur
            }

            // Rota eşleşmediğinde locale middleware'i çalışmaz; mesajın dili
            // isteğin diline göre çözülür (yoksa bir önceki istekten sızar).
            app()->setLocale(\App\Http\Middleware\SetLocale::resolveFor($request));

            $message = $e->getMessage();

            if (ApiErrorMessages::isFrameworkNoise($message)) {
                // binding hatasıysa (ModelNotFoundException önceki istisnadır) hangi
                // kaynağın arandığı rota parametresinden çözülür; gerçek bir rota
                // eşleşmediyse "uç nokta yok" mesajı verilir.
                $message = $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    ? ApiErrorMessages::notFound($request)
                    : ApiErrorMessages::routeNotFound();
            }

            return response()->json(['message' => $message], 404);
        });

        // Yanlış HTTP metodu da JSON dönmeli (istemci HTML yakalayıp "parse error" yaşamasın).
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api', 'api/*') && ! $request->expectsJson()) {
                return null;
            }

            app()->setLocale(\App\Http\Middleware\SetLocale::resolveFor($request));

            return response()->json([
                'message' => __('Bu uç nokta :method isteği için kullanılamaz.', ['method' => $request->method()]),
            ], 405);
        });
    })->create();
