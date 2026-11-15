<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 5 — API hata gövdesi sözleşmesi .
 *
 * Kural: /api/* altındaki HER hata {"message": "..."} (gerekirse + "errors")
 * döner; çerçevenin ham metni ("No query results for model ...", "The route ...
 * could not be found"), yığın izi veya HTML kabuk asla istemciye ulaşmaz.
 * Eşleşmeyen api yollarının SPA karşılama rotasına düşüp 200 HTML basması da
 * bu testlerle kilitlenir (masaüstü istemci bunu "parse error" sanıyordu).
 *
 * Not: hata üreten isteklerin çoğu rota EŞLEŞTİĞİ için locale middleware'i
 * çalışmaz ve Symfony test isteğinin varsayılan Accept-Language'i İngilizceye
 * çeker. Türkçe sözleşme metinleri bu yüzden X-Locale: tr ile sabitlenir
 * (üretimde de tarayıcı/istemci X-Locale veya Accept-Language: tr-TR gönderir).
 */
class ApiErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function ownerToken(): string
    {
        return $this->apiPost('/api/v1/auth/register', [
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ])->assertCreated()->json('token');
    }

    /** @return array<string,string> kimlik + Türkçe sözleşme metinleri */
    protected function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'X-Locale' => 'tr'];
    }

    public function test_eslesmeyen_api_yolu_json_404_doner_html_degil(): void
    {
        $token = $this->ownerToken();

        $res = $this->apiGet('/api/v1/ole-maybe-bir-uc', $this->bearer($token))
            ->assertStatus(404)
            ->assertJsonStructure(['message']);

        $this->assertSame('application/json', $res->headers->get('Content-Type'));
        $this->assertSame('Bu uç nokta mevcut değil.', $res->json('message'));
        $this->assertStringNotContainsString('<!DOCTYPE', $res->getContent());
        $this->assertStringNotContainsString('could not be found', $res->getContent());
    }

    public function test_api_kok_yollari_de_json_404_doner(): void
    {
        foreach (['/api', '/api/', '/api/v1', '/api/v1/', '/api/v2/parties'] as $uri) {
            $res = $this->apiGet($uri, ['X-Locale' => 'tr'])->assertStatus(404);

            $this->assertSame('application/json', $res->headers->get('Content-Type'), $uri);
            $this->assertSame('Bu uç nokta mevcut değil.', $res->json('message'), $uri);
        }
    }

    public function test_api_yoluna_html_istense_bile_json_404_doner(): void
    {
        // Accept: text/html, ForceJson'un api grubunda çalışmadığı (rota eşleşmediği)
        // durumda bile HTML kabuğa düşmemeli: api alan adı her zaman JSON sözleşmesindedir.
        $res = $this->get('/api/v1/ole-maybe-bir-uc', ['Accept' => 'text/html', 'X-Locale' => 'tr'])
            ->assertStatus(404);

        $this->assertStringStartsWith('application/json', (string) $res->headers->get('Content-Type'));
        $this->assertSame('Bu uç nokta mevcut değil.', $res->json('message'));
    }

    public function test_metod_yanlissa_json_405_doner(): void
    {
        // uç var ama yalnız POST
        $res = $this->apiGet('/api/v1/auth/login', ['X-Locale' => 'tr'])->assertStatus(405);

        $this->assertSame('application/json', $res->headers->get('Content-Type'));
        $this->assertSame('Bu uç nokta GET isteği için kullanılamaz.', $res->json('message'));

        // units için GET show ucu yoktur → 405 (404 değil)
        $this->apiGet('/api/v1/units/1')->assertStatus(405);
    }

    public function test_route_model_binding_basarisizsa_cevrilmis_kisa_mesaj_doner(): void
    {
        $token = $this->ownerToken();

        // Ürün kaydı yok: Laravel'in "No query results for model [...]" metni
        // ve APP_DEBUG yığını yerine sözleşmedeki mesaj gelir.
        $res = $this->apiGet('/api/v1/products/999999', $this->bearer($token))
            ->assertStatus(404)
            ->assertJsonStructure(['message']);

        $this->assertSame('Ürün bulunamadı.', $res->json('message'));
        $this->assertStringNotContainsString('No query results', $res->getContent());
        $this->assertStringNotContainsString('trace', $res->getContent());
        $this->assertStringNotContainsString('App\\Models', $res->getContent());
    }

    /**
     * Çerçeve gürültüsü APP_DEBUG kapalıyken de sızmasın: debug kapalıyken
     * Laravel yığını zaten kırpar ama mesaj yine çevrilmemiş kalıyordu.
     *
     * Not: yalnız GERÇEK uçlar deneniyor (units/categories/warehouses/bank-accounts
     * için GET show ucu yok; onlar 405'e düşer — o ayrı bir testin konusu).
     */
    public function test_debug_kapaliyken_de_cevrilmis_mesaj_doner(): void
    {
        config(['app.debug' => false]);

        $token = $this->ownerToken();
        $h = $this->bearer($token);

        foreach ([
            ['GET', '/api/v1/products/999999', 'Ürün bulunamadı.'],
            ['GET', '/api/v1/parties/999999', 'Cari kaydı bulunamadı.'],
            ['GET', '/api/v1/documents/999999', 'Belge bulunamadı.'],
            ['PATCH', '/api/v1/warehouses/999999', 'Depo bulunamadı.'],
            ['PATCH', '/api/v1/categories/999999', 'Kategori bulunamadı.'],
            ['PATCH', '/api/v1/bank-accounts/999999', 'Kasa/banka hesabı bulunamadı.'],
            ['DELETE', '/api/v1/units/999999', 'Birim bulunamadı.'],
            ['DELETE', '/api/v1/payments/999999', 'Ödeme bulunamadı.'],
            ['DELETE', '/api/v1/invitations/999999', 'Davet bulunamadı.'],
        ] as [$method, $uri, $beklenen]) {
            $this->apiCall($method, $uri, [], $h)->assertStatus(404)
                ->assertJsonPath('message', $beklenen);
        }
    }

    public function test_baska_firmanin_kaydi_da_ayni_bulunamadi_mesajiyla_doner(): void
    {
        // Kiracı izolasyonu: varlığın kendisi sızdırılmaz (403 değil, 404).
        $ownerToken = $this->ownerToken();
        $owner = $this->bearer($ownerToken);

        $unitId = $this->apiGet('/api/v1/units', $owner)->assertOk()->json('units.0.id');
        $urunId = $this->apiPost('/api/v1/products', [
            'name' => 'Kiracı Test Ürünü',
            'sku' => 'KIRACI-TEST',
            'unit_id' => $unitId,
            'cost_price' => 10,
            'sale_price' => 20,
            'currency_code' => 'TRY',
            'track_stock' => false,
        ], $owner)->assertCreated()->json('product.id');

        $baska = $this->apiPost('/api/v1/auth/register', [
            'name' => 'Başka Sahip',
            'email' => 'baska@firma-b.test',
            'password' => 'parola1234',
            'company_name' => 'Firma B',
        ])->assertCreated()->json('token');

        $this->apiGet("/api/v1/products/{$urunId}", $this->bearer($baska))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Ürün bulunamadı.');

        // sahibi erişebiliyor (kayıt gerçekten var ve doğru firmada)
        $this->apiGet("/api/v1/products/{$urunId}", $owner)->assertOk();
    }

    public function test_validation_hatasi_message_ve_errors_ile_doner(): void
    {
        $token = $this->ownerToken();

        $this->apiPost('/api/v1/parties', ['name' => '', 'type' => 'yanlis'], $this->bearer($token))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name', 'type']])
            ->assertJsonValidationErrors(['name', 'type']);
    }

    public function test_kimlik_gereken_ucta_html_ister_de_json_401_doner(): void
    {
        // redirectGuestsTo api için null veriyor: api ucu asla yönlendirmez,
        // her zaman {"message": ...} gövdesi döner (istemci sözleşmesi).
        $this->get('/api/v1/me', ['Accept' => 'text/html', 'X-Locale' => 'tr'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Kimlik doğrulaması gerekli.');
    }

    public function test_spa_karsilayici_rotasi_api_disinda_calismaya_devam_eder(): void
    {
        // SPA history modu: olmayan web yolu blade kabuğunu döndürür (F5 tuzağı).
        $this->get('/raporlar')->assertOk()->assertSee('id="app"', false);
        $this->get('/tamamen-olmayan-bir-sayfa')->assertOk()->assertSee('id="app"', false);
        $this->get('/ayni-sey/derin/bir-yol')->assertOk()->assertSee('id="app"', false);

        // "api" ile BAŞLAYAN ama api olmayan yollar SPA kabuğuna düşmeye devam eder
        // (web.php kısıtı yalnız tam "api" ve "api/..." segmenlerini dışlar).
        $this->get('/apihan')->assertOk()->assertSee('id="app"', false);
        $this->get('/apiler/rapor')->assertOk()->assertSee('id="app"', false);
    }

    public function test_health_ucu_etkilenmedi(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_varolan_kayda_erisim_bozulmadi_binding_yerinde_calisir(): void
    {
        $token = $this->ownerToken();
        $h = $this->bearer($token);

        $unitId = $this->apiGet('/api/v1/units', $h)->assertOk()->json('units.0.id');
        $created = $this->apiPost('/api/v1/products', [
            'name' => 'Binding Kontrol Ürünü',
            'sku' => 'BIND-KONTROL',
            'unit_id' => $unitId,
            'cost_price' => 10,
            'sale_price' => 20,
            'currency_code' => 'TRY',
            'track_stock' => false,
        ], $h)->assertCreated()->json('product.id');

        $this->apiGet("/api/v1/products/{$created}", $h)->assertOk()
            ->assertJsonPath('product.sku', 'BIND-KONTROL');
        $this->apiPatch("/api/v1/products/{$created}", ['sale_price' => 25], $h)->assertOk();
        $this->apiDelete("/api/v1/products/{$created}", [], $h)->assertOk();

        // silinen kayıt artık binding'de bulunamaz → çevrilmiş 404
        $this->apiGet("/api/v1/products/{$created}", $h)->assertStatus(404)
            ->assertJsonPath('message', 'Ürün bulunamadı.');
    }
}
