<?php

namespace Tests\Feature\Integration;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Ürün adı çevirisi: MyMemory (salt okunur) + çevrimdışı sözlük fallback.
 * Tsoft'a hiçbir yazım yapılmaz; yalnızca MRC products.name güncellenir.
 */
final class TranslateProductNamesTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // Test hızı için çağrılar arası nezaket gecikmesini kapat.
        config(['integrations.translation.delay_ms' => 0]);
    }

    public function test_ceviri_yapilir_ve_ikinci_calismada_onbellek_kullanilir(): void
    {
        Http::fake([
            // Google ilk denenir; boş gövde MyMemory'ye düşmesini sağlar.
            'https://translate.googleapis.com/*' => Http::response([], 200),
            'https://api.mymemory.translated.net/*' => Http::response([
                'responseData' => ['translatedText' => 'Akıllı Anahtar'],
                'quotaFinished' => false,
                'responseStatus' => 200,
            ], 200),
        ]);

        $ctx = $this->register('ceviri@firma.test', 'Ceviri Firma');
        $companyId = $ctx['company_id'];

        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Smart Key',
            'name_en' => 'Smart Key',
            'sku' => 'TR-001',
        ]);

        $this->artisan('integration:translate-product-names', ['--company' => $companyId])
            ->assertSuccessful();

        $product->refresh();
        $this->assertSame('Akıllı Anahtar', $product->name);
        $this->assertSame('Smart Key', $product->name_en); // İngilizce ad korunur.

        $hash = hash('sha256', 'Smart Key');
        $this->assertDatabaseHas('product_name_translations', [
            'company_id' => $companyId,
            'source_hash' => $hash,
            'source_text' => 'Smart Key',
            'translated_text' => 'Akıllı Anahtar',
            'provider' => 'mymemory',
        ]);
        $this->assertDatabaseHas('sync_logs', [
            'company_id' => $companyId,
            'entity' => 'translation',
            'status' => 'success',
        ]);

        Http::assertSentCount(2); // Google + MyMemory (Google boş döndü)

        // Yeni bir ürün aynı kaynak adla tekrar görünsün: API'ye gidilmemeli.
        $product->update(['name' => 'Smart Key']);

        $this->artisan('integration:translate-product-names', ['--company' => $companyId])
            ->assertSuccessful();

        $this->assertSame('Akıllı Anahtar', $product->fresh()->name);
        Http::assertSentCount(2); // Yalnızca ilk çalışmadaki iki istek.
    }

    public function test_force_olmadan_elle_degistirilmis_ad_ezilmez_force_ile_ezilir(): void
    {
        Http::fake([
            // Google ilk denenir; boş gövde MyMemory'ye düşmesini sağlar.
            'https://translate.googleapis.com/*' => Http::response([], 200),
            'https://api.mymemory.translated.net/*' => Http::response([
                'responseData' => ['translatedText' => 'Akıllı Anahtar'],
                'quotaFinished' => false,
                'responseStatus' => 200,
            ], 200),
        ]);

        $ctx = $this->register('ceviri-force@firma.test', 'Ceviri Force Firma');
        $companyId = $ctx['company_id'];

        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Smart Key',
            'name_en' => 'Smart Key',
            'sku' => 'TR-002',
        ]);

        $product->update(['name' => 'Elle Değiştirilmiş Ad']);

        $this->artisan('integration:translate-product-names', ['--company' => $companyId])
            ->assertSuccessful();

        $this->assertSame('Elle Değiştirilmiş Ad', $product->fresh()->name);
        Http::assertSentCount(0);

        $this->artisan('integration:translate-product-names', [
            '--company' => $companyId,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('Akıllı Anahtar', $product->fresh()->name);
        Http::assertSentCount(2); // Google + MyMemory
    }

    public function test_api_hata_verirse_cevrimdisi_sozluge_dusulur(): void
    {
        Http::fake([
            'https://translate.googleapis.com/*' => Http::response([], 200),
            'https://api.mymemory.translated.net/*' => Http::response(['error' => 'service down'], 500),
        ]);

        $ctx = $this->register('ceviri-fallback@firma.test', 'Ceviri Fallback Firma');
        $companyId = $ctx['company_id'];

        $product = Product::create([
            'company_id' => $companyId,
            'name' => 'Remote Control',
            'name_en' => 'Remote Control',
            'sku' => 'TR-003',
        ]);

        $this->artisan('integration:translate-product-names', ['--company' => $companyId])
            ->assertSuccessful();

        $this->assertSame('Uzaktan Kumanda', $product->fresh()->name);

        $this->assertDatabaseHas('product_name_translations', [
            'company_id' => $companyId,
            'source_hash' => hash('sha256', 'Remote Control'),
            'translated_text' => 'Uzaktan Kumanda',
            'provider' => 'offline',
        ]);
    }
}
