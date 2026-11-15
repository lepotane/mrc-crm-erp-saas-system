<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Kayıt ucu üzerinden kullanıcı+firma kurar; token döner.
     */
    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return [
            'response' => $response,
            'token' => $response->json('token'),
        ];
    }

    public function test_kayit_kullanici_ve_firma_tenant_olusturur(): void
    {
        $r = $this->registerUser(['email' => 'yeni@firma-a.test']);
        $response = $r['response'];

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'company' => ['id', 'name', 'slug']]);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Company::count());
        $this->assertSame(Company::first()->id, User::first()->current_company_id);
        $this->assertSame('Firma A', $response->json('company.name'));
    }

    public function test_kayit_eden_kullanici_admin_rolunu_firma_bazli_alir(): void
    {
        ['token' => $token] = $this->registerUser(['email' => 'sahip@firma-a.test']);

        $response = $this->apiGet('/api/v1/me', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()
            ->assertJsonPath('user.roles.0', 'Admin')
            ->assertJsonPath('company.name', 'Firma A');

        $this->assertCount(1, $response->json('companies'));
    }

    public function test_gecerli_bilgilerle_login_calisir(): void
    {
        $this->registerUser(['email' => 'sahip@firma-a.test', 'password' => 'parola1234']);

        $response = $this->apiPost('/api/v1/auth/login', [
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user', 'company' => ['id', 'name', 'slug'], 'companies']);

        $this->assertCount(1, $response->json('companies'));
    }

    public function test_hatali_sifre_422_dogrlama_hatasi_doner(): void
    {
        $this->registerUser(['email' => 'sahip@firma-a.test', 'password' => 'parola1234']);

        $response = $this->apiPost('/api/v1/auth/login', [
            'email' => 'sahip@firma-a.test',
            'password' => 'yanlis-sifre',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_token_olmadan_api_401_json_doner(): void
    {
        // Dil başlığı açık verilir: Symfony test istemcisi isteklere varsayılan
        // "Accept-Language: en" enjekte eder, aksi halde mesaj dili belirsiz olur.
        $response = $this->apiGet('/api/v1/me', ['X-Locale' => 'tr']);

        $response->assertStatus(401)->assertJson(['message' => 'Kimlik doğrulaması gerekli.']);

        // aynı uç İngilizce tercihle İngilizce mesaj üretir (i18n sözleşmesi)
        $this->apiGet('/api/v1/me', ['X-Locale' => 'en'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Authentication required.']);
    }

    public function test_logout_tokeni_gecersizleştirir(): void
    {
        ['token' => $token] = $this->registerUser(['email' => 'sahip@firma-a.test']);
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->apiPost('/api/v1/auth/logout', [], $headers)->assertOk();

        // logout PAT'i gerçekten sildi (DB'de token kalmadı)
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());

        $this->apiGet('/api/v1/me', $headers)->assertStatus(401);
    }

    public function test_eposta_zorunlu_ve_benzersizdir(): void
    {
        $this->apiPost('/api/v1/auth/register', [
            'name' => 'Eksik', 'email' => '', 'password' => 'parola1234', 'company_name' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->registerUser(['email' => 'ayni@mail.test']);
        $this->apiPost('/api/v1/auth/register', [
            'name' => 'Yinelenen', 'email' => 'ayni@mail.test', 'password' => 'parola1234', 'company_name' => 'Y',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
}
