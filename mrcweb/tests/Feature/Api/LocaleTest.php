<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Çok dillilik (i18n): ana dil Türkçe, ikinci dil İngilizce.
 * Tercih sırası: kullanıcı (users.locale) → X-Locale → Accept-Language → varsayılan (tr).
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return ['token' => $response->json('token'), 'headers' => ['Authorization' => "Bearer {$response->json('token')}"]];
    }

    public function test_kullanici_dil_tercihi_one_gecer_ve_mesaj_turkce_uretilir(): void
    {
        $u = $this->registerUser();
        $me = $this->apiGet('/api/v1/me', $u['headers'])->assertOk();

        // yeni kullanıcı varsayılan dilde başlar; me cevabı dili ve seçenekleri taşır
        $this->assertSame('tr', $me->json('user.locale'));
        $this->assertSame('tr', $me->json('locale'));
        $this->assertSame(['tr', 'en'], $me->json('locales'));

        // kimlikli istekte kullanıcı tercihi (tr), X-Locale: en başlığına baskın çıkar
        $res = $this->apiPost('/api/v1/documents', ['items' => []],
            array_merge($u['headers'], ['X-Locale' => 'en']));
        $res->assertStatus(422);
        $this->assertSame('Cari hesap alanı zorunludur.', $res->json('errors.party_id.0'));
    }

    public function test_misafir_isteginde_xlocale_basligi_dili_belirler(): void
    {
        $tr = $this->apiPost('/api/v1/auth/register', ['email' => 'a@firma.test', 'password' => 'parola1234'],
            ['X-Locale' => 'tr'])->assertStatus(422);
        $this->assertSame('Ad alanı zorunludur.', $tr->json('errors.name.0'));
        $this->assertSame('Firma adı alanı zorunludur.', $tr->json('errors.company_name.0'));

        $en = $this->apiPost('/api/v1/auth/register', ['email' => 'b@firma.test', 'password' => 'parola1234'],
            ['X-Locale' => 'en'])->assertStatus(422);
        $this->assertSame('The name field is required.', $en->json('errors.name.0'));
        // attribute adları dil ile birlikte çözülür (karışım olmaz: "The Ad field" görülmez)
        $this->assertStringNotContainsString('Ad field', $en->json('errors.name.0'));
    }

    public function test_dil_degisikligi_kalici_olarak_saklanir(): void
    {
        $u = $this->registerUser();

        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $u['headers'])
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('locale_format', 'en-US')
            ->assertJsonPath('user.locale', 'en');

        $this->apiGet('/api/v1/me', $u['headers'])->assertOk()->assertJsonPath('user.locale', 'en');
    }

    public function test_is_kurali_mesaji_kullanici_dilinde_donur(): void
    {
        $u = $this->registerUser();
        $warehouseId = $this->apiGet('/api/v1/warehouses', $u['headers'])->assertOk()->json('warehouses.0.id');
        $productId = $this->apiPost('/api/v1/products', ['name' => 'Kalem'], $u['headers'])->assertCreated()->json('product.id');

        $payload = ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'type' => 'out', 'quantity' => -5];

        // tr: birebir mevcut Türkçe metin (diğer testlerle aynı sözleşme)
        $this->apiPost('/api/v1/stock-movements', $payload, $u['headers'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Giriş/çıkış miktarı sıfırdan büyük olmalıdır.');

        // en: aynı kural İngilizce üretilir
        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $u['headers'])->assertOk();
        $this->apiPost('/api/v1/stock-movements', $payload, $u['headers'])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Inbound/outbound quantity must be greater than zero.');
    }

    public function test_desteklenmeyen_dil_reddedilir(): void
    {
        $u = $this->registerUser();

        $this->apiPatch('/api/v1/me/locale', ['locale' => 'de'], $u['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');
    }
}
