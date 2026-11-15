<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * FİRMA AYARLARININ ÜRETİME BAĞLANMASI .
 *
 * `Ayarlar → Genel Ayarlar`daki dört alan (para birimi, varsayılan KDV, fatura
 * seri öneki, varsayılan vade) uzun süre yalnız KAYDEDİLİYORDU: ne sunucu ne SPA
 * yeni belge/cari/ürün kurarken bu değerleri okuyordu (SPA boş satırı sabit %20
 * KDV ile açıyor, Vade boş kalıyor, numara sabit önekle üretiliyordu).
 *
 * Bu dosya artık o değerin ÜRETİME geçtiğini kilitler ve iki sınırı da doğrular:
 *  1) Kullanıcı açıkça değer gönderdiyse AYAR DEĞİL kullanıcının değeri kazanır.
 *  2) Ayar değiştirilince ESKİ kayıtlar geriye dönük yeniden yazılmaz.
 */
class CompanySettingsAppliedTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function settings(array $ctx, array $values): void
    {
        $this->apiPatch('/api/v1/settings', ['settings' => $values], $ctx['headers'])->assertOk();
    }

    protected function partyId(array $ctx, string $name = 'Ayar Test Carisi'): int
    {
        return (int) $this->apiPost('/api/v1/parties', ['name' => $name], $ctx['headers'])
            ->assertCreated()->json('party.id');
    }

    public function test_varsayilan_kdv_orani_urun_baglantisi_olmayan_satira_uygulanir(): void
    {
        $a = $this->register('ayar-kdv@firma.test', 'Ayar Firmasi');
        $this->settings($a, ['default_tax_rate' => 8]);

        $res = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId($a),
            'issue_date' => '2026-03-01',
            'items' => [['name' => 'Danışmanlık', 'quantity' => 1, 'unit_price' => 1000]],
        ], $a['headers'])->assertCreated();

        $res->assertJsonPath('items.0.tax_rate', '8.00');
        $res->assertJsonPath('document.tax_total', '80.0000');
        $res->assertJsonPath('document.grand_total', '1080.0000');
    }

    public function test_urun_kdv_orani_firma_varsayilaninin_onune_gecer(): void
    {
        $a = $this->register('ayar-urun-kdv@firma.test', 'Ayar Firmasi 2');
        $this->settings($a, ['default_tax_rate' => 8]);

        $unitId = $this->unitId($a);
        $product = $this->apiPost('/api/v1/products', [
            'name' => 'KDV 10 Urun', 'unit_id' => $unitId, 'cost_price' => 10, 'sale_price' => 100,
            'tax_rate' => 10,
        ], $a['headers'])->assertCreated()->json('product');

        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId($a),
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'unit_price' => 100]],
        ], $a['headers'])->assertCreated()
            ->assertJsonPath('items.0.tax_rate', '10.00');
    }

    public function test_govdede_verilen_kdv_ayari_ezer(): void
    {
        $a = $this->register('ayar-kdv-ezme@firma.test', 'Ayar Firmasi 3');
        $this->settings($a, ['default_tax_rate' => 8]);

        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId($a),
            'items' => [['name' => 'Serbest satır', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 25]],
        ], $a['headers'])->assertCreated()->assertJsonPath('items.0.tax_rate', '25.00');
    }

    public function test_urun_kartina_islenen_kdv_de_ayardan_gelir(): void
    {
        $a = $this->register('ayar-urunkart@firma.test', 'Ayar Firmasi 4');
        $this->settings($a, ['default_tax_rate' => 18]);

        $this->apiPost('/api/v1/products', [
            'name' => 'Varsayilan KDV Urun', 'unit_id' => $this->unitId($a), 'cost_price' => 1, 'sale_price' => 2,
        ], $a['headers'])->assertCreated()->assertJsonPath('product.tax_rate', '18.00');
    }

    public function test_varsayilan_vade_fatura_ve_alim_belgesinde_vadeyi_doldurur(): void
    {
        $a = $this->register('ayar-vade@firma.test', 'Ayar Firmasi 5');
        $this->settings($a, ['default_payment_days' => 14]);
        $partyId = $this->partyId($a);

        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $partyId, 'issue_date' => '2026-03-01',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated()->assertJsonPath('document.due_date', '2026-03-15');

        // Alım faturası da vadeli doğar (ödeme takibi için kritik).
        $tedarikci = $this->apiPost('/api/v1/parties', ['name' => 'Ayar Tedarikçisi', 'type' => 'supplier'], $a['headers'])
            ->assertCreated()->json('party.id');
        $this->apiPost('/api/v1/documents', [
            'type' => 'purchase', 'party_id' => $tedarikci, 'issue_date' => '2026-03-01',
            'items' => [['name' => 'Mal', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated()->assertJsonPath('document.due_date', '2026-03-15');
    }

    public function test_vade_ayari_teklif_ve_siparisi_vadeli_yapmaz(): void
    {
        $a = $this->register('ayar-vade-teklif@firma.test', 'Ayar Firmasi 6');
        $this->settings($a, ['default_payment_days' => 14]);
        $partyId = $this->partyId($a);

        foreach (['quote', 'sales_order'] as $tip) {
            $this->apiPost('/api/v1/documents', [
                'type' => $tip, 'party_id' => $partyId, 'issue_date' => '2026-03-01',
                'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
            ], $a['headers'])->assertCreated()->assertJsonPath('document.due_date', null);
        }
    }

    public function test_acikca_girilen_vade_ayarin_onune_gecer_ve_vade_0_iken_alan_bos_kalir(): void
    {
        $a = $this->register('ayar-vade-bos@firma.test', 'Ayar Firmasi 7');
        $partyId = $this->partyId($a);

        // Vade ayarı hiç yok (varsayılan 7 gün) -> açıkça girilen vade korunur.
        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $partyId, 'issue_date' => '2026-03-01', 'due_date' => '2026-12-31',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated()->assertJsonPath('document.due_date', '2026-12-31');

        // Vade 0 = "vade önerme": alan boş kalır (yanlışlıkla bugün tarihi yapıştırılmaz).
        $this->settings($a, ['default_payment_days' => 0]);
        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $partyId, 'issue_date' => '2026-03-01',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated()->assertJsonPath('document.due_date', null);
    }

    public function test_fatura_seri_oneki_ayardan_uretilir_diger_turler_sabit_kalir(): void
    {
        $a = $this->register('ayar-onek@firma.test', 'Ayar Firmasi 8');
        $this->settings($a, ['invoice_series_prefix' => 'ms']);
        $partyId = $this->partyId($a);

        $fatura = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated()->json('document.serial');
        $teklif = $this->apiPost('/api/v1/documents', [
            'type' => 'quote', 'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated()->json('document.serial');

        $this->assertStringStartsWith('MS-26-', $fatura, 'fatura öneki ayardan gelmeli');
        $this->assertStringStartsWith('TEK-26-', $teklif, 'teklif öneki sabit kalmalı');
    }

    public function test_gecersiz_onek_ayari_dogrulanir_ama_numara_uretimi_kirilmaz(): void
    {
        $a = $this->register('ayar-onek-bos@firma.test', 'Ayar Firmasi 9');

        // Uzun/sembollü önek reddedilir (kullanıcıya 422 ile bildirilir)...
        $this->apiPatch('/api/v1/settings', ['settings' => ['invoice_series_prefix' => 'FTR-2026!']], $a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('settings.invoice_series_prefix');

        // ...ve boş/okunmamış önek durumunda sistem sabit öneke düşer, numarasız belge kalmaz.
        $a2 = $this->register('ayar-onek-dusuk@firma.test', 'Ayar Firmasi 10');
        $this->apiPatch('/api/v1/settings', ['settings' => ['invoice_series_prefix' => '']], $a2['headers'])->assertOk();

        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $this->partyId($a2),
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a2['headers'])->assertCreated()->assertJsonPath('document.serial', 'FTR-26-00001');
    }

    public function test_firma_para_birimi_cari_urun_ve_belgenin_varsayilanidir(): void
    {
        $a = $this->register('ayar-cc@firma.test', 'Ayar Firmasi 11');
        $this->settings($a, ['currency_code' => 'USD']);

        $this->apiPost('/api/v1/parties', ['name' => 'Doviz Cari'], $a['headers'])
            ->assertCreated()->assertJsonPath('party.currency_code', 'USD');

        $this->apiPost('/api/v1/products', [
            'name' => 'Doviz Urun', 'unit_id' => $this->unitId($a), 'cost_price' => 1, 'sale_price' => 2,
        ], $a['headers'])->assertCreated()->assertJsonPath('product.currency_code', 'USD');

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $this->partyId($a, 'Doviz Cari'),
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $a['headers'])->assertCreated();
        $belge->assertJsonPath('document.currency_code', 'USD');

        // Açıkça TRY diyen TRY alir (ayar öneridir, kement degil).
        $this->apiPost('/api/v1/parties', ['name' => 'TRY Cari', 'currency_code' => 'TRY'], $a['headers'])
            ->assertCreated()->assertJsonPath('party.currency_code', 'TRY');
    }

    public function test_donusumle_dogan_fatura_da_varsayilan_vadeyi_alir(): void
    {
        $a = $this->register('ayar-donusum@firma.test', 'Ayar Firmasi 13');
        $this->settings($a, ['default_payment_days' => 21]);
        $partyId = $this->partyId($a);

        $teklif = $this->apiPost('/api/v1/documents', [
            'type' => 'quote', 'party_id' => $partyId, 'issue_date' => '2026-05-01',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100]],
        ], $a['headers'])->assertCreated()->json('document');
        $this->assertNull($teklif['due_date'], 'teklif vadesiz doğar');

        $siparis = $this->apiPost("/api/v1/documents/{$teklif['id']}/convert", ['to' => 'sales_order'], $a['headers'])
            ->assertCreated()->json('document');
        $this->assertNull($siparis['due_date'], 'sipariş de vadesiz kalır');

        $bugun = date('Y-m-d');
        $fatura = $this->apiPost("/api/v1/documents/{$siparis['id']}/convert", ['to' => 'invoice'], $a['headers'])
            ->assertCreated()->json('document');

        $this->assertSame(
            date('Y-m-d', strtotime($bugun.' +21 days')),
            $fatura['due_date'],
            'dönüşen fatura firmanın vadesini almalı'
        );
    }

    public function test_ayar_degisince_eski_kayitlar_geri_yazilmaz(): void
    {
        $a = $this->register('ayar-geri@firma.test', 'Ayar Firmasi 12');
        $partyId = $this->partyId($a);

        $eski = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice', 'party_id' => $partyId, 'issue_date' => '2026-01-05',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100]],
        ], $a['headers'])->assertCreated()->json('document');
        $this->assertSame('2026-01-12', $eski['due_date'], 'varsayılan vade 7 gün');

        $this->settings($a, ['default_payment_days' => 90]);

        $this->apiGet("/api/v1/documents/{$eski['id']}", $a['headers'])->assertOk()
            ->assertJsonPath('document.due_date', '2026-01-12');
    }
}
