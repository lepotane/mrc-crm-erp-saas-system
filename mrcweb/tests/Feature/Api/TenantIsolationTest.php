<?php

namespace Tests\Feature\Api;

use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * KİRACI (Firma) İZOLASYONU SÖZLEŞMESİ — uçtan uca kilit.
 *
 * Kural: başka firmanın kaydı bu sistemde HİÇBİR şekilde görünmez, düzenlenmez,
 * silinmez ve eyleme sokulamaz. Cevap 403 değil **404**'tür: varlığını doğrulamak
 * sızmasıdır (`.
 *
 * İkinci yönlü kural (bu dosya açılana kadar TEST EDİLMEMİŞTİ): bir kayıt
 * oluşturulurken/güncellenirken gövdeden gönderilen foreign id (parti, ürün,
 * depo, hesap, belge, kategori, birim) KENDİ firmasına ait olmak zorundadır;
 * değilse **422** (validation) döner. Yani "A'nın faturasına B'nin carisini
 * yazma" saldırısı sessizce kabul edilmez.
 *
 * Bu dosya, kapsam matrisinde boş kalan grupları kapatır: katalog tekil erişimleri,
 * ürün PATCH/DELETE, belge yaşam döngüsü (status/convert), iletişim kişileri,
 * ayarlar/davetler/üyeler ve TÜM id bağlama yönleri.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** İki firma kurar: A (saldırıya uğrayan taraf gibi) ve B (saldırgan). */
    protected function twoCompanies(): array
    {
        $a = $this->register('a-sahip@firma-a.test', 'Firma A');
        $b = $this->register('b-sahip@firma-b.test', 'Firma B');

        return ['a' => $a, 'b' => $b];
    }

    protected function party(array $ctx, string $name, string $type = 'customer'): array
    {
        return $this->apiPost('/api/v1/parties', ['name' => $name, 'type' => $type], $ctx['headers'])
            ->assertCreated()->json('party');
    }

    protected function product(array $ctx, string $name, ?int $unitId = null): array
    {
        $unitId = $unitId ?? $this->unitId($ctx);

        return $this->apiPost('/api/v1/products', [
            'name' => $name,
            'unit_id' => $unitId,
            'sku' => substr(md5($name), 0, 8),
            'cost_price' => 40,
            'sale_price' => 75,
            'tax_rate' => 10,
        ], $ctx['headers'])->assertCreated()->json('product');
    }

    protected function document(array $ctx, int $partyId, array $overrides = []): array
    {
        return $this->apiPost('/api/v1/documents', array_merge([
            'type' => 'quote',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100]],
        ], $overrides), $ctx['headers'])->assertCreated()->json('document');
    }

    /** errors anahtarından alan adı (nokta ile nested: items.0.product_id). */
    protected function assertFieldRejected(array $errors, string $field): void
    {
        $this->assertArrayHasKey($field, $errors, 'beklenen alan hatası yok: '.$field);
    }

    // ------------------------------------------------------------------
    // 1) KATALOG: tekil erişim + liste izolasyonu (daha önce test edilmemişti)
    // ------------------------------------------------------------------

    public function test_kategori_guncelleme_ve_silme_karsi_firmaya_kapali(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $catA = $this->apiPost('/api/v1/categories', ['name' => 'Tarım İlaçları', 'color' => '#BC5FCF'], $a['headers'])
            ->assertCreated()->json('category.id');

        $this->apiPatch("/api/v1/categories/{$catA}", ['name' => 'Silindi Sayıldı'], $b['headers'])->assertStatus(404);
        $this->apiDelete("/api/v1/categories/{$catA}", [], $b['headers'])->assertStatus(404);

        // B'nin kategori listesinde A'nın kategorisi görünmez; A'nınki durur
        $this->assertNotContains($catA, array_column($this->apiGet('/api/v1/categories', $b['headers'])->assertOk()->json('categories') ?? [], 'id'));
        $this->assertContains($catA, array_column($this->apiGet('/api/v1/categories', $a['headers'])->assertOk()->json('categories') ?? [], 'id'));
    }

    public function test_birim_silme_karsi_firmaya_kapali_ve_listeler_izole(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $unitA = $this->apiPost('/api/v1/units', ['name' => 'Teneke', 'code' => 'teneke'], $a['headers'])
            ->assertCreated()->json('unit.id');

        $this->apiDelete("/api/v1/units/{$unitA}", [], $b['headers'])->assertStatus(404);

        $this->assertNotContains($unitA, array_column($this->apiGet('/api/v1/units', $b['headers'])->assertOk()->json('units') ?? [], 'id'));
        $this->assertContains($unitA, array_column($this->apiGet('/api/v1/units', $a['headers'])->assertOk()->json('units') ?? [], 'id'));
    }

    public function test_depo_listesi_guncellemesi_ve_silmesi_karsi_firmaya_kapali(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $whA = $this->apiPost('/api/v1/warehouses', ['name' => 'Malatya Deposu'], $a['headers'])
            ->assertCreated()->json('warehouse.id');

        // B, A'nın deposunu ne listeleyebilir ne güncelleyebilir ne silebilir
        $this->assertNotContains($whA, array_column($this->apiGet('/api/v1/warehouses', $b['headers'])->assertOk()->json('warehouses') ?? [], 'id'));
        $this->apiPatch("/api/v1/warehouses/{$whA}", ['name' => 'B Depoladi'], $b['headers'])->assertStatus(404);
        $this->apiDelete("/api/v1/warehouses/{$whA}", [], $b['headers'])->assertStatus(404);

        // A'nın deposu (varsayılan Ana Depo + yenisi) yerinde
        $this->assertContains($whA, array_column($this->apiGet('/api/v1/warehouses', $a['headers'])->assertOk()->json('warehouses') ?? [], 'id'));
    }

    // ------------------------------------------------------------------
    // 2) ÜRÜN: PATCH/DELETE (liste ve tekil GET zaten kilitliydi)
    // ------------------------------------------------------------------

    public function test_urun_guncelleme_ve_silme_karsi_firmaya_kapali(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $pA = $this->product($a, 'IZOLE URUN');

        $this->apiPatch("/api/v1/products/{$pA['id']}", ['name' => 'B ASLINDA BENIM'], $b['headers'])->assertStatus(404);
        $this->apiDelete("/api/v1/products/{$pA['id']}", [], $b['headers'])->assertStatus(404);

        $this->apiGet("/api/v1/products/{$pA['id']}", $a['headers'])->assertOk()
            ->assertJsonPath('product.name', 'IZOLE URUN');
    }

    // ------------------------------------------------------------------
    // 3) ID BAĞLAMA: başka firmanın id'sini kendi kaydına yazma → 422
    //    (bu sınıf hata daha önce YALNIZ warehouse_id için test edilmişti)
    // ------------------------------------------------------------------

    public function test_urun_kendi_kategori_ve_birimi_karsi_firmadan_olamaz(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $catB = $this->apiPost('/api/v1/categories', ['name' => 'B Kategorisi'], $b['headers'])->assertCreated()->json('category.id');
        $unitB = $this->apiPost('/api/v1/units', ['name' => 'B Birimi', 'code' => 'bbir'], $b['headers'])->assertCreated()->json('unit.id');

        // OLUŞTURMA: kategori + birim B'den
        $red = $this->apiPost('/api/v1/products', [
            'name' => 'Karsi Firma Id Denemesi',
            'category_id' => $catB,
            'unit_id' => $unitB,
            'cost_price' => 1, 'sale_price' => 2,
        ], $a['headers'])->assertStatus(422);

        $this->assertFieldRejected($red->json('errors') ?? [], 'category_id');
        $this->assertFieldRejected($red->json('errors') ?? [], 'unit_id');

        // GÜNCELLEME: A'nın ürününü B'nin birimine bağlama
        $pA = $this->product($a, 'KATALOG URUNU');
        $this->apiPatch("/api/v1/products/{$pA['id']}", ['unit_id' => $unitB], $a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('unit_id');

        $this->apiGet("/api/v1/products/{$pA['id']}", $a['headers'])->assertOk();
    }

    public function test_belge_cari_depo_ve_urun_id_karsi_firmadan_olamaz(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $partyB = $this->party($b, 'B Carisi')['id'];
        $whB = $this->defaultWarehouseId($b);
        $prodB = $this->product($b, 'B URUNU');

        // Cari B'den
        $this->apiPost('/api/v1/documents', [
            'type' => 'quote',
            'party_id' => $partyB,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertStatus(422)->assertJsonValidationErrors('party_id');

        // Depo B'den
        $partyA = $this->party($a, 'A Carisi')['id'];
        $this->apiPost('/api/v1/documents', [
            'type' => 'quote',
            'party_id' => $partyA,
            'warehouse_id' => $whB,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

        // Satırdaki ürün B'den (nested alan adı: items.0.product_id)
        $red = $this->apiPost('/api/v1/documents', [
            'type' => 'quote',
            'party_id' => $partyA,
            'items' => [['product_id' => $prodB['id'], 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertStatus(422);

        $this->assertFieldRejected($red->json('errors') ?? [], 'items.0.product_id');

        // Belge güncellemesinde de aynı kural
        $docA = $this->document($a, $partyA);
        $this->apiPatch("/api/v1/documents/{$docA['id']}", ['party_id' => $partyB], $a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('party_id');
    }

    public function test_odeme_cari_belge_ve_hesap_id_karsi_firmadan_olamaz(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $partyA = $this->party($a, 'A Tahsilat Carisi')['id'];
        $partyB = $this->party($b, 'B Carisi')['id'];
        $bankB = $this->apiPost('/api/v1/bank-accounts', ['name' => 'B Kasası'], $b['headers'])
            ->assertCreated()->json('bank_account.id');
        $docB = $this->document($b, $partyB);

        // 1) cari B'den
        $this->apiPost('/api/v1/payments', ['party_id' => $partyB, 'direction' => 'in', 'amount' => 50], $a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('party_id');

        // 2) banka hesabı B'den
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyA, 'direction' => 'in', 'amount' => 50, 'bank_account_id' => $bankB,
        ], $a['headers'])->assertStatus(422)->assertJsonValidationErrors('bank_account_id');

        // 3) belge B'den
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyA, 'direction' => 'in', 'amount' => 50, 'document_id' => $docB['id'],
        ], $a['headers'])->assertStatus(422)->assertJsonValidationErrors('document_id');

        // Sağlama: aynı firmalar içindeki geçerli ödeme kabul edilir (kural fazla sıkı değil)
        $this->apiPost('/api/v1/payments', ['party_id' => $partyA, 'direction' => 'in', 'amount' => 50], $a['headers'])
            ->assertCreated();
    }

    public function test_stok_hareketi_urun_id_karsi_firmadan_olamaz(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $prodB = $this->product($b, 'B STOK URUNU');
        $whA = $this->defaultWarehouseId($a);

        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $prodB['id'],
            'warehouse_id' => $whA,
            'type' => 'in',
            'quantity' => 1,
        ], $a['headers'])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    // ------------------------------------------------------------------
    // 4) BELGE YAŞAM DÖNGÜSÜ: status + convert (daha önce test edilmemişti)
    // ------------------------------------------------------------------

    public function test_belge_durum_degistirme_ve_donusturme_karsi_firmaya_kapali(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $docA = $this->document($a, $this->party($a, 'A Carisi')['id']);

        $this->apiPost("/api/v1/documents/{$docA['id']}/status", ['status' => 'issued'], $b['headers'])->assertStatus(404);
        $this->apiPost("/api/v1/documents/{$docA['id']}/convert", ['type' => 'invoice'], $b['headers'])->assertStatus(404);

        // A tarafında belge hâlâ taslak ve ihraç edilebilir durumda
        $this->apiGet("/api/v1/documents/{$docA['id']}", $a['headers'])->assertOk()
            ->assertJsonPath('document.status', 'draft');
    }

    // ------------------------------------------------------------------
    // 5) İLETİŞİM KİŞİLERİ alt kaynağı (daha önce test edilmemişti)
    // ------------------------------------------------------------------

    public function test_iletisim_kisileri_karsi_firmaya_kapali(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $partyA = $this->party($a, 'A Carisi')['id'];
        $partyB = $this->party($b, 'B Carisi')['id'];

        $contactA = $this->apiPost("/api/v1/parties/{$partyA}/contacts", ['name' => 'A Yetkilisi'], $a['headers'])
            ->assertCreated()->json('contact.id');

        // B, A'nın carisinin kişilerini listeyemez / ekleyemez
        $this->apiGet("/api/v1/parties/{$partyA}/contacts", $b['headers'])->assertStatus(404);
        $this->apiPost("/api/v1/parties/{$partyA}/contacts", ['name' => 'Sizma Yetkili'], $b['headers'])->assertStatus(404);

        // B kendi carisi üzerinden A'nın kişi id'sini silmeye çalışamaz
        $this->apiDelete("/api/v1/parties/{$partyB}/contacts/{$contactA}", [], $b['headers'])->assertStatus(404);

        // A'nın kişisi yerinde
        $this->apiGet("/api/v1/parties/{$partyA}/contacts", $a['headers'])->assertOk()
            ->assertJsonPath('contacts.0.id', $contactA);
    }

    // ------------------------------------------------------------------
    // 6) AYARLAR, FİRMA LİSTESİ, ÜYELER, DAVETLER
    // ------------------------------------------------------------------

    public function test_firma_ayarlari_karsi_firmaya_sizmaz_ve_karsi_firma_ustune_yazamaz(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $this->apiPatch('/api/v1/settings', ['settings' => [
            'invoice_series_prefix' => 'ZZZ',
            'default_tax_rate' => 8,
        ]], $a['headers'])->assertOk()
            ->assertJsonPath('settings.invoice_series_prefix', 'ZZZ');

        // B kendi varsayılanlarını görür, A'nınki sızmaz
        $this->apiGet('/api/v1/settings', $b['headers'])->assertOk()
            ->assertJsonPath('settings.invoice_series_prefix', 'FT')
            ->assertJsonPath('settings.default_tax_rate', '20');

        // B yazmaya çalışır, A etkilenmez
        $this->apiPatch('/api/v1/settings', ['settings' => ['invoice_series_prefix' => 'QQQ']], $b['headers'])->assertOk();
        $this->apiGet('/api/v1/settings', $a['headers'])->assertOk()
            ->assertJsonPath('settings.invoice_series_prefix', 'ZZZ');
    }

    public function test_firma_listesi_uye_olmayan_firmayi_gostermez_ve_uye_listesi_izoledir(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $ids = array_column($this->apiGet('/api/v1/companies', $b['headers'])->assertOk()->json('companies') ?? [], 'id');

        $this->assertSame([$b['company_id']], $ids, 'üye olunmayan firma listede olmamalı');

        // B'nin üye listesinde A'nın sahibi görünmez
        $memberIds = array_column($this->apiGet('/api/v1/company/members', $b['headers'])->assertOk()->json('members') ?? [], 'id');
        $this->assertNotContains($a['user_id'], $memberIds);
        $this->assertContains($b['user_id'], $memberIds);
    }

    public function test_davet_listesi_ve_davet_silme_karsi_firmaya_kapali(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $invA = $this->apiPost('/api/v1/invitations', ['email' => 'calisan@c-test.test', 'role' => 'Personel'], $a['headers'])
            ->assertCreated()->json('invitation.id');

        // B, A'nın davetini listeyemez ve silemez
        $this->assertNotContains($invA, array_column($this->apiGet('/api/v1/invitations', $b['headers'])->assertOk()->json('invitations') ?? [], 'id'));
        $this->apiDelete("/api/v1/invitations/{$invA}", [], $b['headers'])->assertStatus(404);

        // A silebilir (kural fazla sıkı değil)
        $this->apiDelete("/api/v1/invitations/{$invA}", [], $a['headers'])->assertOk();
    }

    // ------------------------------------------------------------------
    // 7) ÇAPRAZ YÖN: B'nin kaydı da A'ya kapalı (tek yönlü koruma tuzağı)
    // ------------------------------------------------------------------

    public function test_izolasyon_cift_yonludur_b_firmasinin_carisi_a_ile_404(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $partyB = $this->party($b, 'B Carisi')['id'];

        $this->apiGet("/api/v1/parties/{$partyB}", $a['headers'])->assertStatus(404);
        $this->apiPatch("/api/v1/parties/{$partyB}", ['name' => 'A Yazdi'], $a['headers'])->assertStatus(404);
        $this->apiDelete("/api/v1/parties/{$partyB}", [], $a['headers'])->assertStatus(404);
        $this->apiGet("/api/v1/parties/{$partyB}/balance", $a['headers'])->assertStatus(404);

        $this->apiGet("/api/v1/parties/{$partyB}", $b['headers'])->assertOk();
    }

    // ------------------------------------------------------------------
    // 8) KALAN KUYRUK: logo (multipart), üye listesi, belge dönüştürme
    // ------------------------------------------------------------------

    public function test_firma_logosu_kendi_firmasina_yazilir_karsi_firmanin_logosuna_dokunmaz(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        // Sahte disk: depolama katmanını doğrularken gerçek storage/app/public
        // (yani yedek zincirinin aldığı klasör) kirlenmez.
        Storage::fake('public');

        $logoA = $this->apiUpload('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('a-logo.png', 120, 40)], $a['headers'])
            ->assertOk()->json('company.logo_path');
        $this->assertNotEmpty($logoA, 'logo yolu dönmeli');

        $logoB = $this->apiUpload('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('b-logo.png', 120, 40)], $b['headers'])
            ->assertOk()->json('company.logo_path');

        $this->assertNotSame($logoA, $logoB);

        // B kendi profilinde A'nın logosunu göremez; A'nın dosyası diskte durur
        // (controller "eski logoyu sil" adımını kendi firması üzerinden yapar)
        $this->apiGet('/api/v1/company', $b['headers'])->assertOk()->assertJsonPath('company.logo_path', $logoB);
        Storage::disk('public')->assertExists($logoA);
        $this->apiGet('/api/v1/company', $a['headers'])->assertOk()->assertJsonPath('company.logo_path', $logoA);

        // A'nın deposundaki eski logo, B'nin yüklemesiyle silinmedi: B'nin ikinci
        // yüklemesi yalnız B'nin eski dosyasını düşürür
        $this->apiUpload('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('b-logo2.png', 120, 40)], $b['headers'])->assertOk();
        Storage::disk('public')->assertExists($logoA);
        Storage::disk('public')->assertMissing($logoB);
    }

    public function test_personel_rolu_firma_logosunu_degistiremez_kiraci_kapisindan_sonra_gelir(): void
    {
        $a = $this->register('a-sahip@firma-a.test', 'Firma A');

        Storage::fake('public');

        // A, kendi firmasına bir Personel davet eder; kullanıcı kabul edip A bağlamına geçer
        $this->apiPost('/api/v1/invitations', ['email' => 'a-personel@firma-a.test', 'role' => 'Personel'], $a['headers'])->assertCreated();
        $token = (string) Invitation::where('company_id', $a['company_id'])->where('email', 'a-personel@firma-a.test')->value('token');

        $personel = $this->register('a-personel@firma-a.test', 'Personel Kendi Firmasi');
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $personel['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $a['company_id']], $personel['headers'])->assertOk();

        // Rol kapısı: Personel logoyu değiştiremez (403), sahibi Admin değiştirebilir.
        // Reddedilen yükleme diskte iz de bırakmamalı.
        $this->apiUpload('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('yasak.png', 60, 20)], $personel['headers'])
            ->assertStatus(403);

        $this->assertCount(0, Storage::disk('public')->files('logos'), 'yetkisiz yükleme dosya bırakmamalı');

        $logoA = $this->apiUpload('/api/v1/company/logo', ['file' => UploadedFile::fake()->image('a-logo.png', 60, 20)], $a['headers'])
            ->assertOk()->json('company.logo_path');

        Storage::disk('public')->assertExists($logoA);
    }

    public function test_davetle_katilan_kullanici_yalniz_davet_eden_firmanin_uyesi_gorunur(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();
        $c = $this->register('c-sahip@firma-c.test', 'Firma C');

        // A, C'nin e-postasına davet gönderir; C kabul eder → C artık A'nın da üyesi
        $this->apiPost('/api/v1/invitations', ['email' => 'c-sahip@firma-c.test', 'role' => 'Personel'], $a['headers'])
            ->assertCreated();
        $token = (string) Invitation::where('company_id', $a['company_id'])->where('email', 'c-sahip@firma-c.test')->value('token');
        $this->assertNotEmpty($token, 'davet tokenı üretildi (API listesinde yayımlanmaz, modelden okunur)');

        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $c['headers'])->assertOk();

        $uyeIds = fn (array $ctx) => array_column($this->apiGet('/api/v1/company/members', $ctx['headers'])->assertOk()->json('members') ?? [], 'id');

        // C → A üyesi; listelerde doğrulama: A görür, B görmez, A'da B'nin sahibi yoktur
        $this->assertContains($c['user_id'], $uyeIds($a));
        $this->assertNotContains($c['user_id'], $uyeIds($b));
        $this->assertNotContains($b['user_id'], $uyeIds($a));

        // C iki firmanın üyesidir; kendi listesi de yalnız kendi üyeliklerini gösterir
        $cSirketleri = array_column($this->apiGet('/api/v1/companies', $c['headers'])->assertOk()->json('companies') ?? [], 'id');
        $this->assertEqualsCanonicalizing([$c['company_id'], $a['company_id']], $cSirketleri);

        // Bağlamını A yapabilir; B'nin firmasını asla seçemez
        $this->apiPost('/api/v1/companies/select', ['company_id' => $a['company_id']], $c['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $b['company_id']], $c['headers'])->assertStatus(403);
    }

    public function test_belge_donusturme_satirlari_kendi_firma_urununu_korur(): void
    {
        ['a' => $a, 'b' => $b] = $this->twoCompanies();

        $prodA = $this->product($a, 'DONUSUM URUNU');
        $partyA = $this->party($a, 'A Carisi')['id'];

        $quote = $this->document($a, $partyA, [
            'items' => [['product_id' => $prodA['id'], 'quantity' => 2, 'unit_price' => 100]],
        ]);

        $donusen = $this->apiPost("/api/v1/documents/{$quote['id']}/convert", ['to' => 'sales_order'], $a['headers'])
            ->assertCreated()
            ->json();

        // Dönüşen belge A'ya ait ve satırı A'nın ürününü taşır
        $this->assertSame($a['company_id'], (int) $donusen['document']['company_id']);
        $this->assertSame($prodA['id'], (int) $donusen['items'][0]['product_id']);

        // B ne dönüşen belgeyi görür ne kendi belgelerinde A'nın ürününü taşır
        $this->apiGet("/api/v1/documents/{$donusen['document']['id']}", $b['headers'])->assertStatus(404);
        $this->apiGet('/api/v1/documents', $b['headers'])->assertOk()->assertJsonPath('meta.total', 0);

        // A'nın ürün stoğu/ürünü yerinde; B'nin kataloğu etkilenmedi
        $this->apiGet("/api/v1/products/{$prodA['id']}", $a['headers'])->assertOk();
        $this->apiGet('/api/v1/products', $b['headers'])->assertOk()->assertJsonPath('meta.total', 0);
    }
}
