<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * BELGE DURUM AKISI .
 *
 * Iki kapi birden kilitlenir:
 *  1) Durum makinesi gercekten calisir: teklif gonderilir/kabul-reddedilir,
 *     siparis onaylanir, fatura ihrac edilir. (Arayuzde bu gecislerin butonu
 *     yoktu; "Ihrac et" butonu teklif/sipariste de gorunup 422 uretiyordu.)
 *  2) Ret cevabinda durum ANAHTARI degil kullaniciyin gordugu okunur AD gosterilir
 *     ("Bu belge Taslak durumundan Ihraç durumuna gecirilemez.") — ve ceviri
 *     dile uyar (EN: "Draft" / "Issued"). Ham anahtar gostermek sozlesmeye
 *     aykiri: kullanici ekranda gordugu kelimeyi okumali.
 */
class DocumentStatusFlowTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function belge(array $ctx, string $tip, array $extra = []): array
    {
        $party = $this->apiPost('/api/v1/parties', ['name' => 'Durum Akisi Carisi'], $ctx['headers'])
            ->assertCreated()->json('party.id');

        return $this->apiPost('/api/v1/documents', array_merge([
            'type' => $tip,
            'party_id' => $party,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100]],
        ], $extra), $ctx['headers'])->assertCreated()->json('document');
    }

    public function test_teklif_gonderilir_kabul_edilir_veya_reddedilir(): void
    {
        $a = $this->register('durum-teklif@firma.test', 'Durum Firmasi');

        $kabul = $this->belge($a, 'quote');
        $this->apiPost("/api/v1/documents/{$kabul['id']}/status", ['status' => 'sent'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'sent');
        $this->apiPost("/api/v1/documents/{$kabul['id']}/status", ['status' => 'accepted'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'accepted');

        $red = $this->belge($a, 'quote');
        $this->apiPost("/api/v1/documents/{$red['id']}/status", ['status' => 'sent'], $a['headers'])->assertOk();
        $this->apiPost("/api/v1/documents/{$red['id']}/status", ['status' => 'rejected'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'rejected');

        // Kapali makine: reddedilmis teklif tekrar kabul edilemez.
        $this->apiPost("/api/v1/documents/{$red['id']}/status", ['status' => 'accepted'], $a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_gonderilen_teklif_iptal_edilebilir_ihrac_edilemez(): void
    {
        $a = $this->register('durum-iptal@firma.test', 'Durum Firmasi 2');
        $teklif = $this->belge($a, 'quote');

        // Teklif "ihrac" edilmez; makinede issued yoktur.
        $this->apiPost("/api/v1/documents/{$teklif['id']}/status", ['status' => 'issued'], $a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->apiPost("/api/v1/documents/{$teklif['id']}/status", ['status' => 'sent'], $a['headers'])->assertOk();
        $this->apiPost("/api/v1/documents/{$teklif['id']}/status", ['status' => 'cancelled'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'cancelled');
    }

    public function test_siparis_onaylanir(): void
    {
        $a = $this->register('durum-siparis@firma.test', 'Durum Firmasi 3');
        $siparis = $this->belge($a, 'sales_order');

        $this->apiPost("/api/v1/documents/{$siparis['id']}/status", ['status' => 'confirmed'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'confirmed');

        // Onaylanan siparis yeniden taslag donmez.
        $this->apiPost("/api/v1/documents/{$siparis['id']}/status", ['status' => 'draft'], $a['headers'])
            ->assertStatus(422);
    }

    public function test_durum_hatasi_kullanicinin_gordugu_adla_yazilir_ham_anahtar_degil(): void
    {
        $a = $this->register('durum-mesaj@firma.test', 'Durum Firmasi 4');
        $teklif = $this->belge($a, 'quote');

        $mesaj = $this->apiPost("/api/v1/documents/{$teklif['id']}/status", ['status' => 'issued'], $a['headers'])
            ->assertStatus(422)
            ->json('errors.status.0');

        $this->assertStringContainsString('Taslak', $mesaj, 'durum adi cevrilmis okunmali: '.$mesaj);
        $this->assertStringContainsString('İhraç', $mesaj);
        $this->assertStringNotContainsString('draft', $mesaj, 'ham anahtar arayuzde gorunmez');
        $this->assertStringNotContainsString('issued', $mesaj);
    }

    public function test_durum_hatasi_ingilizce_arayuzde_ingilizce_durum_adi_ile_yazilir(): void
    {
        $a = $this->register('durum-mesaj-en@firma.test', 'Durum Firmasi 5');

        // Dil cozumu: kullanici tercihi X-Locale basligina baskindir (LocaleTest),
        // bu yuzden EN icin once tercih degistirilir.
        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $a['headers'])->assertOk();

        $teklif = $this->belge($a, 'quote');

        $mesaj = $this->apiPost(
            "/api/v1/documents/{$teklif['id']}/status",
            ['status' => 'issued'],
            $a['headers'],
        )->assertStatus(422)->json('errors.status.0');

        $this->assertStringContainsString('Draft', $mesaj, 'EN arayuzde durum adi Ingilizce olmali: '.$mesaj);
        $this->assertStringContainsString('Issued', $mesaj);
        $this->assertStringNotContainsString('Taslak', $mesaj, 'EN cevapta Turkce karisimi olmamali');
    }

    public function test_fatura_ihrac_dongusu_duzenli_calisir(): void
    {
        $a = $this->register('durum-fatura@firma.test', 'Durum Firmasi 6');
        $fatura = $this->belge($a, 'invoice');

        $this->apiPost("/api/v1/documents/{$fatura['id']}/status", ['status' => 'issued'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'issued')
            ->assertJsonPath('stock_applied', false);

        // Karar 44: kesilmiş fatura iptal EDİLEBİLİR (stok aynaya düşer);
        // iptal makine yoluyla olur, doğrudan silme kapalıdır (DocumentDeleteTest).
        $this->apiPost("/api/v1/documents/{$fatura['id']}/status", ['status' => 'cancelled'], $a['headers'])
            ->assertOk()->assertJsonPath('document.status', 'cancelled');
    }
}
