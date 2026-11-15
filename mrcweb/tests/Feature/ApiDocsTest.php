<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Faz 5 — API dokümanı kapısı (/api-docs, /api-docs.json, /api-docs/postman).
 *
 * Yayın paketinde Scramble YOKTUR (composer install --no-dev): belgenin kendisi
 * local'da üretilir (npm run openapi) ve public/docs/ altındaki STATIK dosyalar-
 * dan sunulur. Bu testler hem kapıyı (config/api.php → docs) hem de artifact
 * yokluğundaki davranışı kilitler; böylece sunucuda "eksik belge" sessizce 500
 * olmaz, okunabilir bir 404'e düşer.
 */
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    /** public/ altında tutulan gecici artifact klaseri (uretilmis belgeyi taklit eder) */
    private string $docsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docsDir = public_path('docs-test');
        config(['api.docs.path' => 'docs-test']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsDir);

        parent::tearDown();
    }

    private function artifactVar(): void
    {
        File::ensureDirectoryExists($this->docsDir);
        file_put_contents($this->docsDir.'/api.json', '{"openapi":"3.1.2","info":{"title":"MRC API v1","version":"1.0.0"},"paths":{}}');
        file_put_contents($this->docsDir.'/postman_collection.json', '{"info":{"name":"MRC API v1"},"item":[]}');
        file_put_contents($this->docsDir.'/index.html', '<!DOCTYPE html><html lang="tr"><body>MRC-API-DOC-SAYFASI</body></html>');
    }

    public function test_kapaliyken_uc_belge_ucta_da_404_doner(): void
    {
        config(['api.docs.enabled' => false, 'api.docs.key' => '']);
        $this->artifactVar();

        foreach (['/api-docs', '/api-docs.json', '/api-docs/postman'] as $uri) {
            $this->get($uri)->assertNotFound();
            $this->get($uri.'?key=herhengibirkey')->assertNotFound();
        }
    }

    public function test_artefact_uretilmemisse_anlasilir_404_doner(): void
    {
        config(['api.docs.enabled' => true, 'api.docs.key' => 'anahtar-123']);

        // public/docs-test bos birakiliyor: `npm run openapi` calistirilmamis gibi
        $this->get('/api-docs?key=anahtar-123')->assertNotFound();
        $this->get('/api-docs.json?key=anahtar-123')->assertNotFound();
        $this->get('/api-docs/postman?key=anahtar-123')->assertNotFound();
    }

    public function test_anahtar_dogruysa_uc_ucta_da_icerik_doner(): void
    {
        config(['api.docs.enabled' => true, 'api.docs.key' => 'anahtar-123']);
        $this->artifactVar();

        $sayfa = $this->get('/api-docs?key=anahtar-123')->assertOk();
        $this->assertStringContainsString('text/html', (string) $sayfa->headers->get('Content-Type'));

        // index.html akitarak verilir (buyuk statik belge): BinaryFileResponse govde
        // dondurmez, bu yuzde sunulan dosyanin kendisi dogrulanir
        $temel = $sayfa->baseResponse;
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $temel);
        $this->assertStringContainsString('docs-test', (string) $temel->getFile()->getRealPath());

        $spec = $this->get('/api-docs.json?key=anahtar-123')->assertOk();
        $this->assertStringContainsString('application/json', (string) $spec->headers->get('Content-Type'));

        $postman = $this->get('/api-docs/postman?key=anahtar-123')->assertOk();
        $this->assertStringContainsString('attachment; filename="mrc-api-v1-postman.json"', (string) $postman->headers->get('Content-Disposition'));
    }

    public function test_anahtar_yanlis_veya_eksikse_403(): void
    {
        config(['api.docs.enabled' => true, 'api.docs.key' => 'anahtar-123']);
        $this->artifactVar();

        $this->get('/api-docs')->assertForbidden();
        $this->get('/api-docs?key=yanlis')->assertForbidden();
        $this->get('/api-docs.json?key=yanlis')->assertForbidden();

        // baslik ile de verilebilir (curl/Postman kullanimi)
        $this->get('/api-docs.json', ['X-Docs-Key' => 'anahtar-123'])->assertOk();
    }

    public function test_anahtar_bossa_giris_yapan_kullanici_yeterli_guest_403(): void
    {
        config(['api.docs.enabled' => true, 'api.docs.key' => '']);
        $this->artifactVar();

        $this->get('/api-docs')->assertForbidden();

        $user = User::factory()->create();
        $this->actingAs($user)->get('/api-docs')->assertOk();
        $this->actingAs($user)->get('/api-docs.json')->assertOk();
    }

    public function test_bearer_anahtar_ile_de_okunabilir(): void
    {
        // masaustu istemci sozlesmeyi bu ucten alir: token ile GET /api-docs.json
        config(['api.docs.enabled' => true, 'api.docs.key' => '']);
        $this->artifactVar();

        $token = $this->apiPost('/api/v1/auth/register', [
            'name' => 'Belge Kullanıcısı',
            'email' => 'belge@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Belge Firması',
        ])->assertCreated()->json('token');

        $this->apiGet('/api-docs.json', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('info.title', 'MRC API v1');
    }

    public function test_scramble_in_kendi_belge_uclari_kayitli_degil(): void
    {
        // AppServiceProvider::configureApiDocs() ignoreDefaultRoutes() cagirmazsa
        // docs/api (varsayilan 'web' ara yazilimiyla) tekrar belirir.
        $this->assertNull(Route::getRoutes()->getByName('scramble.docs.ui'));
        $this->assertNull(Route::getRoutes()->getByName('scramble.docs.document'));
    }

    public function test_dokuman_yollari_spa_karsilayici_rotasi_ile_catisir_degil(): void
    {
        // 'api-docs' SPA kisitini (api($|/)) tetiklemez; ayrica SPA kabugu /api-docs
        // istegini yutmamali (yoksa dokuman sayfasi yerine Vue kabugu doner).
        config(['api.docs.enabled' => false]);

        $res = $this->get('/api-docs');

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringNotContainsString('id="app"', $res->getContent());
    }

    public function test_doküman_ucleri_api_hata_sozlesmesini_bozmaz(): void
    {
        // /api-docs 'api' ile baslamaz; api hata render'i bunu etkilemez
        config(['api.docs.enabled' => true, 'api.docs.key' => 'x']);

        $res = $this->get('/api-docs.json?key=y');

        $this->assertSame(403, $res->getStatusCode());
    }

    /**
     * Kapalı doküman ucu `abort(404)` ile düşer (boş mesaj). JSON bekleyen bir istemci
     * (masaüstü uygulama `GET /api-docs.json`) bu durumda `{"message": ""}` görmemeli;
     * sözleşme §5 gereği gövde çevrilmiş ve boş olmayan bir mesaj taşır.
     */
    public function test_kapali_dokumen_ucu_json_istemciye_bos_olmayan_mesaj_doner(): void
    {
        config(['api.docs.enabled' => false, 'api.docs.key' => '']);
        $this->artifactVar();

        foreach (['/api-docs', '/api-docs.json', '/api-docs/postman'] as $uri) {
            $res = $this->get($uri, ['Accept' => 'application/json']);

            $res->assertNotFound()->assertJsonStructure(['message']);
            $this->assertNotSame('', (string) $res->json('message'), "{$uri} hata mesaji bos");
            $this->assertStringNotContainsString('id="app"', $res->getContent());
        }
    }
}
