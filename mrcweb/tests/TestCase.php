<?php

namespace Tests;

use App\Models\User;
use App\Support\SuperAdminBootstrap;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    /**
     * Testlerde kullanılan SAHTE süper yönetici parolası.
     *
     * Gerçek üretim parolası testte/depoda BULUNMAZ. Bu değer yalnız
     * `phpunit` altında geçerlidir; üretimde hiçbir yerde kullanılmaz.
     */
    public const SUPERADMIN_PAROLA = 'Test-SuperAdmin-2026!';

    /**
     * setUp sırasında yakalanarak auth.defaults.guard mutasyonuna karşı referans
     */
    protected ?string $authGuardDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authGuardDefault = config('auth.defaults.guard');
    }

    /**
     * Test içinde art arda atılan istekler aynı AuthManager/RequestGuard
     * örneğini paylaşır; RequestGuard::user() çözülen kullanıcıyı önbelleğe
     * alır (ör. logout sonrası 401 doğrulaması bozulur). Ayrıca
     * auth:sanctum → AuthManager::setDefaultDriver() config'teki
     * auth.defaults.guard değerini proses bazında mutasyona uğratarak
     * spatie rol yaratma guard'ını bozabilir. Üretimde her HTTP isteği ayrı
     * container olduğundan bu davranış yoktur — yalnız test yardımcısıdır.
     */
    protected function resetAuthGuards(): void
    {
        $auth = $this->app->make('auth');

        $auth->forgetGuards();
        $this->app['config']->set('auth.defaults.guard', $this->authGuardDefault);
    }

    /**
     * Üretim semantiğini korumak için her API çağrısı öncesi auth guard
     * önbelleği sıfırlanır; böylece aynı test içindeki her istek bağımsız
     * bir HTTP isteği gibi davranır.
     */
    protected function apiCall(string $method, string $uri, array $data = [], array $headers = [])
    {
        $this->resetAuthGuards();

        return $this->json($method, $uri, $data, $headers);
    }

    protected function apiGet(string $uri, array $headers = [])
    {
        return $this->apiCall('GET', $uri, [], $headers);
    }

    protected function apiPost(string $uri, array $data = [], array $headers = [])
    {
        return $this->apiCall('POST', $uri, $data, $headers);
    }

    protected function apiPatch(string $uri, array $data = [], array $headers = [])
    {
        return $this->apiCall('PATCH', $uri, $data, $headers);
    }

    protected function apiPut(string $uri, array $data = [], array $headers = [])
    {
        return $this->apiCall('PUT', $uri, $data, $headers);
    }

    protected function apiDelete(string $uri, array $data = [], array $headers = [])
    {
        return $this->apiCall('DELETE', $uri, $data, $headers);
    }

    /**
     * multipart/form-data isteği (dosya yükleme uçları: POST /company/logo).
     *
     * Laravel 12 `postMultipart()` yardımcıını kaldırdı; dosyalar `call()`
     * üzerinden `$files` çantası olarak taşınır. Çağıran taraf `apiPost` ile
     * aynı yazım alışkanlığını kullansın diye `$data` içindeki UploadedFile
     * örnekleri otomatik ayrıştırılır. `Accept: application/json` eklenir ki
     * doğrulama hataları HTML değil sözleşmeye uygun JSON dönsün.
     */
    protected function apiUpload(string $uri, array $data = [], array $headers = [])
    {
        $this->resetAuthGuards();

        $files = [];

        foreach ($data as $key => $value) {
            if ($value instanceof UploadedFile) {
                $files[$key] = $value;
                unset($data[$key]);
            }
        }

        $server = $this->transformHeadersToServerVars(
            array_merge(['Accept' => 'application/json'], $headers)
        );

        return $this->call('POST', $uri, $data, [], $files, $server);
    }

    /**
     * Süper Yönetici hesabını test ortamında oluşturur.
     *
     * Neden gerekli: süper yönetici hesabı 07 Kas'ta migration'da seed
     * ediliyordu ve bu, "kullanıcı sayısı = 1" diye sayan 7 testi kırıyordu
     * (AdminProvisioning, AuthFlow, PasswordReset). Artık migration test
     * ortamında hesabı oluşturmuyor; ihtiyacı olan test bunu çağırır.
     *
     * Parola `self::SUPERADMIN_PAROLA` (sahte) — GERÇEK parola hiçbir yerde
     * (testte, depoda, dokümanda) bulunmaz.
     */
    protected function seedSuperAdmin(?string $email = null, ?string $password = null): User
    {
        $id = SuperAdminBootstrap::ensure($email, $password ?? self::SUPERADMIN_PAROLA);

        if (! $id) {
            $this->fail('Süper yönetici hesabı oluşturulamadı.');
        }

        return User::findOrFail($id);
    }
}
