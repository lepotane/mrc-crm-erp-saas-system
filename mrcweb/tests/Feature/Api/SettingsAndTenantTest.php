<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettingsAndTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Yeni bir firma sahibi kaydeder ve auth header döndürür.
     */
    protected function companyHeaders(array $overrides = []): array
    {
        $email = $overrides['email'] ?? uniqid('sahip-').'@firma.test';
        ['token' => $token] = $this->registerUser([
            'email' => $email,
            'company_name' => $overrides['company_name'] ?? 'Firma '.Str::random(4),
        ]);

        return ['Authorization' => "Bearer {$token}"];
    }

    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return ['response' => $response, 'token' => $response->json('token')];
    }

    public function test_ayarlar_api_varsayilanlari_doner(): void
    {
        $headers = $this->companyHeaders();

        $response = $this->apiGet('/api/v1/settings', $headers);

        $response->assertOk()
            ->assertJsonPath('settings.currency_code', 'TRY')
            ->assertJsonPath('settings.default_tax_rate', '20')
            ->assertJsonPath('settings.invoice_series_prefix', 'FT')
            ->assertJsonPath('settings.default_payment_days', '7');
    }

    public function test_admin_ayarlarini_gunceller_para_birimi_senkron_kalir(): void
    {
        $headers = $this->companyHeaders();

        $this->apiPatch('/api/v1/settings', [
            'settings' => ['currency_code' => 'USD', 'default_tax_rate' => '8'],
        ], $headers)->assertOk()
            ->assertJsonPath('settings.currency_code', 'USD')
            ->assertJsonPath('settings.default_tax_rate', '8');

        $company = $this->apiGet('/api/v1/company', $headers)->assertOk()->json('company');
        $this->assertSame('USD', $company['currency_code']);
    }

    public function test_ayarlar_bilinmeyen_keyleri_yoksayar(): void
    {
        $headers = $this->companyHeaders();

        $this->apiPatch('/api/v1/settings', [
            'settings' => ['bilinmeyen_anahtar' => 'x', 'default_payment_days' => '30'],
        ], $headers)->assertOk()
            ->assertJsonPath('settings.default_payment_days', '30');

        // bilinmeyen key kalıcıya yazılmamalı
        $this->apiGet('/api/v1/settings', $headers)->assertOk()
            ->assertJsonMissing(['bilinmeyen_anahtar' => 'x']);
    }

    public function test_uye_olmayan_baska_firmani_secemez(): void
    {
        $headersA = $this->companyHeaders(['company_name' => 'A Firması']);
        $headersB = $this->companyHeaders(['company_name' => 'B Firması']);

        $companyIdA = $this->apiGet('/api/v1/company', $headersA)->json('company.id');

        $this->apiPost('/api/v1/companies/select', ['company_id' => $companyIdA], $headersB)
            ->assertStatus(403);
    }

    public function test_firma_profil_guncellemesi_izole_calisir(): void
    {
        $headersA = $this->companyHeaders(['company_name' => 'A Firması']);
        $headersB = $this->companyHeaders(['company_name' => 'B Firması']);

        $this->apiPatch('/api/v1/company', ['name' => 'B Firması Değişti'], $headersB)->assertOk();

        $a = $this->apiGet('/api/v1/company', $headersA)->json('company');
        $b = $this->apiGet('/api/v1/company', $headersB)->json('company');

        $this->assertSame('A Firması', $a['name']);
        $this->assertSame('B Firması Değişti', $b['name']);
    }

    public function test_firma_vergi_dairesi_alani_kaydedilir_ve_goruntulenir(): void
    {
        $headers = $this->companyHeaders(['company_name' => 'Mükellef A.Ş.']);

        $this->apiPatch('/api/v1/company', [
            'tax_number' => '1234567890',
            'tax_office' => 'Malatya Vergi Dairesi',
        ], $headers)->assertOk()
            ->assertJsonPath('company.tax_office', 'Malatya Vergi Dairesi')
            ->assertJsonPath('company.tax_number', '1234567890');

        $this->apiGet('/api/v1/company', $headers)->assertOk()
            ->assertJsonPath('company.tax_office', 'Malatya Vergi Dairesi');

        // alan nullable: temizlenebilir (PDF’de satır otomatik düşer)
        $this->apiPatch('/api/v1/company', ['tax_office' => null], $headers)->assertOk()
            ->assertJsonPath('company.tax_office', null);
    }

    public function test_firma_profil_guncellemesi_admin_yetkisi_ister(): void
    {
        // Personel yüklenip profili güncellemeye çalışır → 403
        $ownerHeaders = $this->companyHeaders(['company_name' => 'A Firması']);

        $this->apiPost('/api/v1/invitations', [
            'email' => 'uye@firma-a.test',
            'role' => 'Personel',
        ], $ownerHeaders)->assertCreated();

        ['token' => $invitedToken] = $this->registerUser([
            'email' => 'uye@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Kendi Firması',
        ]);
        $invitedHeaders = ['Authorization' => "Bearer {$invitedToken}"];

        $this->apiPost('/api/v1/invitations/accept', ['token' => \App\Models\Invitation::first()->token], $invitedHeaders)
            ->assertOk();

        $companyIdA = $this->apiGet('/api/v1/company', $ownerHeaders)->json('company.id');
        $this->apiPost('/api/v1/companies/select', ['company_id' => $companyIdA], $invitedHeaders)->assertOk();

        $this->apiPatch('/api/v1/company', ['name' => 'Saldiri Adı'], $invitedHeaders)
            ->assertStatus(403);
    }
}
