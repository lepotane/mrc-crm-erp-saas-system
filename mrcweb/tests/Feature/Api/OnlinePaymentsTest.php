<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * ONLİNE TAHSİLAT .
 *
 * Bu dosya paranın deftere NE ZAMAN yazıldığını kilitler:
 *  - Link üretmek tahsilat DEĞİLDİR (sağlayıcı sayfası açılır, kayıt doğmaz).
 *  - Tahsilat yalnız İMZASI DOĞRULANMIŞ bildirimle doğar (PayTR hash / iyzico detail).
 *  - İmzasız, mükerrer, iptal edilmiş linke gelen bildirim ASLA ikinci kayıt üretmez.
 *
 * Sağlayıcı HTTP çağrıları `Http::fake()` ile karşılanır: testte gerçek para hareketi
 * olmaz, imza matematiği gerçektir (sahte istekteki hash'i test kendisi üretir).
 */
class OnlinePaymentsTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('odeme@firma.test', 'Odeme Firmasi');
    }

    // ---------- kurulum yardımcıları ----------

    protected function cariAc(array $overrides = []): int
    {
        return $this->apiPost('/api/v1/parties', array_merge([
            'name' => 'Odeme Musterisi',
            'email' => 'musteri@ornek.test',
            'phone' => '05321234567',
        ], $overrides), $this->a['headers'])->assertCreated()->json('party.id');
    }

    protected function faturaKes(int $partyId, float $tutar = 120.0): array
    {
        $doc = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Hizmet', 'quantity' => 1, 'unit_price' => $tutar, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$doc['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        return $doc;
    }

    protected function paytrHesabi(array $overrides = []): void
    {
        $this->apiPut('/api/v1/payment-settings/paytr', array_merge([
            'credentials' => [
                'merchant_id' => '100001',
                'merchant_key' => 'test-merchant-key-12345',
                'merchant_salt' => 'test-merchant-salt-67890',
            ],
            'is_sandbox' => true,
            'is_active' => true,
        ], $overrides), $this->a['headers'])->assertOk();
    }

    protected function iyzicoHesabi(): void
    {
        $this->apiPut('/api/v1/payment-settings/iyzico', [
            'credentials' => [
                'api_key' => 'sandbox-test-api-key',
                'secret_key' => 'sandbox-test-secret-key',
            ],
            'is_sandbox' => true,
            'is_active' => true,
        ], $this->a['headers'])->assertOk();
    }

    protected function paytrHash(string $token, string $status, string $total): string
    {
        return base64_encode(hash_hmac(
            'sha256',
            $token.'test-merchant-salt-67890'.$status.$total,
            'test-merchant-key-12345',
            true
        ));
    }

    /** docs.iyzico.com „Ardıl Sıfırlar" kuralının test tarafındaki karşılığı. */
    protected function yalincik(string $deger): string
    {
        if (! str_contains($deger, '.')) {
            return $deger;
        }
        $t = rtrim(rtrim($deger, '0'), '.');

        return $t === '' ? '0' : $t;
    }

    protected function iyzicoImza(array $res): string
    {
        return hash_hmac('sha256', implode(':', [
            $res['paymentStatus'], $res['paymentId'], $res['currency'],
            $res['basketId'], $res['conversationId'],
            $this->yalincik($res['paidPrice']), $this->yalincik($res['price']),
            $res['token'],
        ]), 'sandbox-test-secret-key');
    }

    // ---------- hesap yönetimi ----------

    public function test_odeme_hesaplari_iki_saglayiciyla_gorunur_sirlar_maskeli(): void
    {
        $res = $this->apiGet('/api/v1/payment-settings', $this->a['headers'])->assertOk()->json('data');

        $this->assertCount(2, $res);
        $this->assertSame(['paytr', 'iyzico'], array_column($res, 'provider'));

        foreach ($res as $satir) {
            $this->assertFalse($satir['configured']);
            $this->assertStringEndsWith('/api/paytr/callback', $res[0]['callback_url']);
            $this->assertStringEndsWith('/api/iyzico/callback', $res[1]['callback_url']);
        }

        $this->paytrHesabi();

        $satir = collect(
            $this->apiGet('/api/v1/payment-settings', $this->a['headers'])->assertOk()->json('data')
        )->firstWhere('provider', 'paytr');

        $this->assertTrue($satir['configured']);
        $this->assertTrue($satir['is_active']);
        // Sır düz dönmez: son 4 karakter görünür, başı maskeli.
        $this->assertSame('100001', $satir['fields']['merchant_id']);
        $this->assertStringEndsWith('2345', $satir['fields']['merchant_key']);
        $this->assertStringStartsWith('••••', $satir['fields']['merchant_key']);
        $this->assertSame([], $satir['missing_fields']);
    }

    public function test_personel_hesap_goremez_degistiremez_ama_link_uretebilir(): void
    {
        // Personel akışı: davet → kendi kaydıyla kabul → firma seçimi.
        $this->apiPost('/api/v1/invitations', ['email' => 'uye@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();
        $uye = $this->register('uye@firma.test', 'Uye Firmasi');
        $token = \App\Models\Invitation::where('company_id', $this->a['company_id'])->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();
        $uyeHeaders = $uye['headers'];

        // Hesap ekranı YALNIZ Admin'e açık.
        $this->apiGet('/api/v1/payment-settings', $uyeHeaders)->assertForbidden();
        $this->apiPut('/api/v1/payment-settings/paytr', ['is_active' => true], $uyeHeaders)->assertForbidden();

        // Ama link üretmek satış takibidir: Personel de üretebilir.
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-personel'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);

        $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $uyeHeaders)->assertCreated();
    }

    public function test_eksik_hesap_etkinlestirilemez(): void
    {
        // merchant_salt verilmeden etkinleştirme istenirse hesap kayıt olur ama kapalı kalır.
        $res = $this->apiPut('/api/v1/payment-settings/paytr', [
            'credentials' => ['merchant_id' => '100001', 'merchant_key' => 'k'],
            'is_active' => true,
        ], $this->a['headers'])->assertStatus(422);

        $res->assertJsonValidationErrors('credentials');
        $this->assertStringContainsString('Güvenlik parolası', $res->json('errors.credentials.0'));
    }

    // ---------- PayTR link + bildirim ----------

    public function test_eksik_hesapla_link_uretilemez_yarim_kayit_kalmaz(): void
    {
        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);

        $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');

        $this->assertSame(0, PaymentLink::count(), 'hesap yokken link satırı doğmamalı');
    }

    public function test_paytr_linki_odeme_sayfasi_acar_tahsilat_yazmaz(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-abc-1'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);

        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $this->assertSame('Bekliyor', $link['status_label']);
        $this->assertSame('https://www.paytr.com/odeme/guvenli/prov-abc-1', $link['pay_url']);
        $this->assertSame(0, Payment::count(), 'link üretmek tahsilat değildir');
    }

    public function test_paytr_gecerli_bildirimi_tahsilati_deftere_isler(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-odeme'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $total = '12000'; // 120.00 TL × 100
        $res = $this->post('/api/paytr/callback', [
            'merchant_oid' => $link['token'],
            'status' => 'success',
            'total_amount' => $total,
            'hash' => $this->paytrHash($link['token'], 'success', $total),
            'payment_type' => 'card',
            'currency' => 'TL',
            'payment_amount' => $total,
        ]);

        $res->assertOk();
        $this->assertSame('OK', $res->getContent(), 'PayTR yalnız düz OK kabul eder');

        $this->assertSame(1, Payment::count());
        $odeme = Payment::first();
        $this->assertSame('paytr', $odeme->method);
        $this->assertSame('in', $odeme->direction);
        $this->assertSame(120.0, round((float) $odeme->amount, 2));

        $link = PaymentLink::first();
        $this->assertSame('paid', $link->status);
        $this->assertSame($odeme->id, $link->payment_id);

        // Belge kapandı, cari bakiye sıfırlandı.
        $this->assertSame('paid', \App\Models\Document::find($fatura['id'])->status);
        $bakiye = $this->apiGet("/api/v1/parties/{$partyId}/balance", $this->a['headers'])
            ->assertOk()->json('totals.balance');
        $this->assertSame(0.0, round((float) $bakiye, 2));
    }

    public function test_paytr_bozuk_imzali_bildirim_kayit_uretmez(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-kotu'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $partyId ? $fatura['id'] : null,
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $res = $this->post('/api/paytr/callback', [
            'merchant_oid' => $link['token'],
            'status' => 'success',
            'total_amount' => '12000',
            'hash' => 'kurcalanmis-imza',
            'payment_type' => 'card',
            'currency' => 'TL',
            'payment_amount' => '12000',
        ]);

        $res->assertForbidden();
        $this->assertSame(0, Payment::count(), 'imzasız bildirim tahsilat yazmaz');
        $this->assertSame('pending', PaymentLink::first()->status);
    }

    public function test_paytr_mukerrer_bildirimi_tek_tahsilat_uretir(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-tekrar'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $govde = [
            'merchant_oid' => $link['token'],
            'status' => 'success',
            'total_amount' => '12000',
            'payment_type' => 'card',
            'currency' => 'TL',
            'payment_amount' => '12000',
        ];
        $govde['hash'] = $this->paytrHash($link['token'], 'success', '12000');

        $this->post('/api/paytr/callback', $govde)->assertOk();
        // Ağ tekrarı: aynı bildirim ikinci kez gelir.
        $this->post('/api/paytr/callback', $govde)->assertOk();

        $this->assertSame(1, Payment::count(), 'mükerrer bildirim ikinci tahsilat doğurmamalı');
        $this->assertSame(2, (int) PaymentLink::first()->notification_count);
    }

    public function test_paytr_tanimadigi_siparis_numarasini_kabul_etmez(): void
    {
        $govde = [
            'merchant_oid' => 'hic-olmayan-token-1234567890abcdef',
            'status' => 'success',
            'total_amount' => '12000',
            'hash' => 'x',
            'payment_type' => 'card',
        ];

        $this->post('/api/paytr/callback', $govde)->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    public function test_iptal_edilmis_linkin_gec_bildirimi_kayit_uretmez(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-iptal'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $this->apiPost("/api/v1/payment-links/{$link['id']}/cancel", [], $this->a['headers'])
            ->assertOk()->assertJsonPath('payment_link.status', 'cancelled');

        $govde = [
            'merchant_oid' => $link['token'],
            'status' => 'success',
            'total_amount' => '12000',
            'payment_type' => 'card',
            'currency' => 'TL',
            'payment_amount' => '12000',
        ];
        $govde['hash'] = $this->paytrHash($link['token'], 'success', '12000');

        // İptal edilmiş linkin bildirimi: yanıt OK (PayTR tekrar denemesin) ama kayıt YOK.
        $res = $this->post('/api/paytr/callback', $govde);
        $res->assertOk();
        $this->assertSame('OK', $res->getContent());
        $this->assertSame(0, Payment::count());
        $this->assertSame('cancelled', PaymentLink::first()->status);
    }

    // ---------- iyzico link + geri dönüş ----------

    public function test_iyzico_linki_odeme_sayfasi_acar(): void
    {
        $this->iyzicoHesabi();
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'token' => 'iyz-token-1',
            'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=iyz-token-1',
        ], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);

        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'iyzico',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $this->assertStringContainsString('iyz-token-1', $link['pay_url']);
        $this->assertSame('iyz-token-1', PaymentLink::first()->provider_token);
        $this->assertSame(0, Payment::count());
    }

    public function test_iyzico_geri_donusu_sonucu_sorgulayip_tahsilat_yazar(): void
    {
        $this->iyzicoHesabi();

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = PaymentLink::create([
            'company_id' => $this->a['company_id'],
            'token' => PaymentLink::generateToken(),
            'provider' => 'iyzico',
            'party_id' => $partyId,
            'document_id' => $fatura['id'],
            'amount' => 120,
            'currency_code' => 'TRY',
            'status' => 'pending',
            'provider_token' => 'iyz-token-2',
            'created_by' => $this->a['user_id'],
        ]);

        $detail = [
            'status' => 'success',
            'paymentStatus' => 'success',
            'paymentId' => '20260101',
            'currency' => 'TRY',
            'basketId' => $link->token,
            'conversationId' => $link->token,
            'paidPrice' => '120.00',
            'price' => '120.00',
            'token' => 'iyz-token-2',
        ];
        $detail['signature'] = $this->iyzicoImza($detail);

        Http::fake(['*' => Http::response($detail, 200)]);

        // Müşterinin tarayıcısı token ile döner; sonuç iyzico'dan sorgulanır.
        $res = $this->get('/api/iyzico/callback?token=iyz-token-2');
        $res->assertRedirect('/odeme/'.$link->token);

        $this->assertSame(1, Payment::count());
        $odeme = Payment::first();
        $this->assertSame('iyzico', $odeme->method);
        $this->assertSame('20260101', $odeme->reference);
        $this->assertSame('paid', PaymentLink::find($link->id)->status);
        $this->assertSame('paid', \App\Models\Document::find($fatura['id'])->status);
    }

    public function test_iyzico_imzasi_tutmayinca_odeme_yazilmaz(): void
    {
        $this->iyzicoHesabi();

        $partyId = $this->cariAc();
        $link = PaymentLink::create([
            'company_id' => $this->a['company_id'],
            'token' => PaymentLink::generateToken(),
            'provider' => 'iyzico',
            'party_id' => $partyId,
            'document_id' => null,
            'amount' => 50,
            'currency_code' => 'TRY',
            'status' => 'pending',
            'provider_token' => 'iyz-token-3',
            'created_by' => $this->a['user_id'],
        ]);

        Http::fake(['*' => Http::response([
            'status' => 'success',
            'paymentStatus' => 'success',
            'paymentId' => '999',
            'currency' => 'TRY',
            'basketId' => $link->token,
            'conversationId' => $link->token,
            'paidPrice' => '50.00',
            'price' => '50.00',
            'token' => 'iyz-token-3',
            'signature' => 'kurcalanmis',
        ], 200)]);

        $this->get('/api/iyzico/callback?token=iyz-token-3')
            ->assertRedirect('/odeme/'.$link->token.'?durum=dogrulanamadi');

        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', PaymentLink::find($link->id)->status);
    }

    // ---------- link kuralları ----------

    public function test_baska_firmanin_linki_gorunmez(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-a'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $b = $this->register('baska@firma.test', 'Baska Firmasi');

        $this->apiGet("/api/v1/payment-links/{$link['id']}", $b['headers'])->assertNotFound();
        $this->apiPost("/api/v1/payment-links/{$link['id']}/cancel", [], $b['headers'])->assertNotFound();
    }

    public function test_taslak_belgeye_link_uretilemez(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-taslak'], 200)]);

        $partyId = $this->cariAc();
        $taslak = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Hizmet', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $taslak['id'],
            'amount' => 100,
        ], $this->a['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_id');

        $this->assertSame(0, PaymentLink::count());
    }

    public function test_link_tutari_kalan_alacagi_asamaz(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-asan'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId, 100);

        // Kısmi tahsilat: kalan 60.
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 40,
            'document_id' => $fatura['id'], 'method' => 'cash',
        ], $this->a['headers'])->assertCreated();

        $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => 100,
        ], $this->a['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_odeme_linki_plana_tabidir(): void
    {
        Company::where('id', $this->a['company_id'])->update(['plan' => 'testplan']);
        config(['plans.plans.testplan' => [
            'label' => 'Test Planı',
            'limits' => ['users' => 50, 'products' => 50, 'documents_per_month' => 50, 'payment_links_per_month' => 1],
        ]]);

        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-plan'], 200)]);

        $partyId = $this->cariAc();

        $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'party_id' => $partyId,
            'amount' => 10,
        ], $this->a['headers'])->assertCreated();

        // Sağlayıcıya ikinci kez bile gidilmez: limit kapısı önce sorulur.
        $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'party_id' => $partyId,
            'amount' => 10,
        ], $this->a['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_limit');
    }

    public function test_manuel_odemede_online_yontem_reddedilir(): void
    {
        $partyId = $this->cariAc();

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10, 'method' => 'paytr',
        ], $this->a['headers'])->assertStatus(422)->assertJsonValidationErrors('method');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10, 'method' => 'iyzico',
        ], $this->a['headers'])->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_odenen_link_iptal_edilemez(): void
    {
        $this->paytrHesabi();
        Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'prov-odendi'], 200)]);

        $partyId = $this->cariAc();
        $fatura = $this->faturaKes($partyId);
        $link = $this->apiPost('/api/v1/payment-links', [
            'provider' => 'paytr',
            'document_id' => $fatura['id'],
            'amount' => (float) $fatura['grand_total'],
        ], $this->a['headers'])->assertCreated()->json('payment_link');

        $govde = [
            'merchant_oid' => $link['token'],
            'status' => 'success',
            'total_amount' => '12000',
            'payment_type' => 'card',
            'currency' => 'TL',
            'payment_amount' => '12000',
        ];
        $govde['hash'] = $this->paytrHash($link['token'], 'success', '12000');
        $this->post('/api/paytr/callback', $govde)->assertOk();

        $this->apiPost("/api/v1/payment-links/{$link['id']}/cancel", [], $this->a['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_musteri_sonuc_sayfasi_giris_istemez(): void
    {
        $link = PaymentLink::create([
            'company_id' => $this->a['company_id'],
            'token' => PaymentLink::generateToken(),
            'provider' => 'paytr',
            'party_id' => $this->cariAc(),
            'document_id' => null,
            'amount' => 75.5,
            'currency_code' => 'TRY',
            'status' => 'pending',
            'created_by' => $this->a['user_id'],
        ]);

        $this->get('/odeme/'.$link->token)->assertOk();
        $this->get('/odeme/'.$link->token.'/durum')->assertOk()
            ->assertJsonPath('status', 'pending');
        // Bilinmeyen token: dostça „geçersiz" sayfası (200), JSON uçta 404.
        // ('f' × 32 desene uyar ama veritabanında yoktur.)
        // Dil: başlıkta ?dil=tr verilir (yoksa tarayıcı diline göre İngilizce açılır).
        $this->get('/odeme/ffffffffffffffffffffffffffffffff?dil=tr')->assertOk()
            ->assertSee('Bağlantı geçersiz');
        $this->get('/odeme/ffffffffffffffffffffffffffffffff/durum')->assertNotFound();
    }
}
