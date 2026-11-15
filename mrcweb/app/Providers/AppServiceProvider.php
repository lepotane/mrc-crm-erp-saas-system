<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user) {
            return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ? true : null;
        });

        $this->configureRateLimiting();
        $this->configureApiDocs();
    }

    /**
     * Scramble (dev bağımlılığı) API dokümanını kendi üretmesin: docs/api ve
     * docs/api.json ucları varsayılan olarak 'web' ortayazısını (oturum) kullanır
     * ve yayın paketi --no-dev alındığından orada Scramble hiç yoktur. Doküman
     * /api-docs altında kendi kontrolcumüzden sunulur (config/api.php → docs).
     */
    protected function configureApiDocs(): void
    {
        if (! class_exists(\Dedoc\Scramble\Scramble::class)) {
            return;
        }

        \Dedoc\Scramble\Scramble::ignoreDefaultRoutes();
        \Dedoc\Scramble\Scramble::routes(function (\Illuminate\Routing\Route $route) {
            return str_starts_with($route->uri, 'api/') && ! str_contains($route->uri, 'sa-core');
        });

        /*
         * Belgenin kiracıya/hukume ait kismini Scramble uretmiyor; burada eklenir.
         * redocly lint'in "info-license" uyarisi da boylece kapanir.
         */
        \Dedoc\Scramble\Scramble::afterOpenApiGenerated(
            function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi): void {
                $openApi->info->setLicense(
                    new \Dedoc\Scramble\Support\Generator\License('MRC — kapalı kaynak', 'LicenseRef-MRC-Proprietary')
                );

                // WithExtensions anahtara kendiliginde 'x-' oneki ekler.
                $openApi->setExtensionProperty(
                    'sozlesme',
                    'Alan adlari ve durum kodlari standart REST sozlesmesine uygundur.'
                );
            }
        );
    }

    /**
     * API v1 hız sınırları .
     *
     * Sayılar config/api.php'dedir; .env ile ezilebilir (paylaşımlı hostta
     * kırpılabilir). Yanıt gövdesi bilerek tek sözdizimdedir: {message} +
     * X-RateLimit-* / Retry-After başlıkları → istemci beklemeyi buradan hesaplar.
     *
     * ÖNEMLİ: anahtar üretilirken $request->user() KULLANILMAZ. Sanctum'un guard'ı
     * token yokken AuthenticationException fırlattığı için limiter içinde çağırmak
     * sayacın hiç tutulmamasına yol açardı (kimliksiz istekler throttling dışı
     * kalırdı). Bearer token özetinden kimliklenir, token yoksa IP kullanılır.
     */
    protected function configureRateLimiting(): void
    {
        $yanit = fn (Request $request, array $headers) => $this->tooManyRequests($headers);

        // /api/* : yumuşak bütçe anahtar başına (aynı kullanıcının SPA oturumu ile
        // masaüstü anahtarı birbirini tüketmez) + IP başına sert tavan.
        RateLimiter::for('api', function (Request $request) use ($yanit) {
            return [
                Limit::perMinute((int) config('api.rate_limit.api', 120))
                    ->by($this->tokenBudgetKey($request))
                    ->response($yanit),
                Limit::perMinute((int) config('api.rate_limit.ip_hard', 600))
                    ->by('ip|'.$request->ip())
                    ->response($yanit),
            ];
        });

        // Giriş/kayıt: e-posta + IP. Oturum açma denemeleri için ayrı ve düşük bütçe.
        RateLimiter::for('auth', function (Request $request) use ($yanit) {
            $email = mb_strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute((int) config('api.rate_limit.auth', 10))
                ->by('auth|'.$request->ip().'|'.$email)
                ->response($yanit);
        });
    }

    /**
     * Bütçe anahtarı: bearer token varsa onun özeti (anahtar başına), yoksa IP.
     * Guard'ı çalıştırmaz; guard'a dokunmadan istemciyi ayırt etmeye yeter.
     */
    protected function tokenBudgetKey(Request $request): string
    {
        $token = $request->bearerToken();

        return $token
            ? 'tok|'.substr(hash('sha256', $token), 0, 16)
            : 'ip|'.$request->ip();
    }

    protected function tooManyRequests(array $headers = [])
    {
        return response()->json([
            'message' => __('Çok fazla istek gönderdiniz. Lütfen kısa bir süre bekleyip tekrar deneyin.'),
        ], 429)->withHeaders($headers);
    }
}
