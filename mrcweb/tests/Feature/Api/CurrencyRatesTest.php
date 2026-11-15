<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\ExchangeRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * ÇOKLU PARA + KUR .
 *
 * Kilitlenen sözleşme: kur fotoğrafı işlem anında yazılır (sonradan değişmez);
 * kaynak zinciri TCMB → er-api → son geçerli kur; ters çevirme matematiği
 * (er-api TRY-bazlıdır) testle kilitli; pasif para belgeye giremez.
 */
class CurrencyRatesTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('kur@firma.test', 'Kur Firmasi');
    }

    protected function tcmbXml(array $kurlar): string
    {
        $satirlar = '';

        foreach ($kurlar as $kod => $alis) {
            $satirlar .= "<Currency Kod=\"{$kod}\"><ForexBuying>{$alis}</ForexBuying></Currency>";
        }

        return "<?xml version=\"1.0\"?><Tarih_Date>{$satirlar}</Tarih_Date>";
    }

    public function test_tcmb_basarisinda_kurlar_yazilir(): void
    {
        Http::fake([
            '*tcmb.gov.tr*' => Http::response($this->tcmbXml(['USD' => '34.1234', 'EUR' => '37.5000']), 200),
            '*open.er-api.com*' => Http::response(['result' => 'success', 'rates' => ['USD' => 0.0293, 'EUR' => 0.0267]], 200),
        ]);

        $sonuc = ExchangeRates::fetchDaily('2026-10-30');

        $this->assertSame('tcmb', $sonuc['source']);
        $this->assertSame(34.1234, ExchangeRates::rateFor('USD', '2026-10-30'));
        $this->assertSame(37.5, ExchangeRates::rateFor('EUR', '2026-10-30'));
        $this->assertSame(1.0, ExchangeRates::rateFor('TRY', '2026-10-30'));
        $this->assertSame('tcmb', ExchangeRate::where('currency_code', 'USD')->first()->source);

        // Aynı gün iki koşu çift satır doğurmaz.
        ExchangeRates::fetchDaily('2026-10-30');
        $this->assertSame(1, ExchangeRate::where('currency_code', 'USD')->where('rate_date', '2026-10-30')->count());
    }

    public function test_tcmb_duserse_yedek_kaynak_tersten_cevrilir(): void
    {
        Http::fake([
            '*tcmb.gov.tr*' => Http::response('', 500),
            // 1 TRY = 0.0293 USD → 1 USD ≈ 34.1297 TRY
            '*open.er-api.com*' => Http::response(['result' => 'success', 'rates' => ['USD' => 0.0293]], 200),
        ]);

        $sonuc = ExchangeRates::fetchDaily('2026-10-30');

        $this->assertSame('erapi', $sonuc['source']);
        $this->assertEqualsWithDelta(34.1297, ExchangeRates::rateFor('USD', '2026-10-30'), 0.001);
        $this->assertSame('erapi', ExchangeRate::where('currency_code', 'USD')->first()->source);
    }

    public function test_iki_kaynak_duserse_son_gecerli_kur_durur(): void
    {
        Http::fake([
            '*tcmb.gov.tr*' => Http::response('', 500),
            '*open.er-api.com*' => Http::response(['result' => 'error'], 200),
        ]);

        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => '2026-10-20', 'rate' => 33.0, 'source' => 'tcmb',
        ]);

        $sonuc = ExchangeRates::fetchDaily('2026-10-30');

        $this->assertNull($sonuc['source']);
        // Son geçerli kur hâlâ okunur (yeni satır yazılmadı).
        $this->assertSame(33.0, ExchangeRates::rateFor('USD', '2026-10-30'));
    }

    public function test_belge_kesilirken_kur_fotografi_islenir(): void
    {
        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => '2026-10-30', 'rate' => 34.5, 'source' => 'tcmb',
        ]);

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Dovizli Cari'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'currency_code' => 'USD',
            'issue_date' => '2026-10-30',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->assertSame(34.5, round((float) $belge['exchange_rate'], 4));
        $this->assertSame('2026-10-30', $belge['rate_date']);

        // Kur tablosu sonradan değişse belge fotoğrafı değişmez.
        ExchangeRate::where('currency_code', 'USD')->update(['rate' => 99.0]);

        $tekrar = $this->apiGet("/api/v1/documents/{$belge['id']}", $this->a['headers'])
            ->assertOk()->json('document');

        $this->assertSame(34.5, round((float) $tekrar['exchange_rate'], 4));
    }

    public function test_kur_bilinmiyorsa_belge_1_kuruyla_kesilir_is_durmaz(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Dovizli Cari 2'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'currency_code' => 'EUR',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->assertSame(1.0, round((float) $belge['exchange_rate'], 4));
    }

    public function test_pasif_para_belgeye_giremez(): void
    {
        Currency::where('code', 'GBP')->update(['is_active' => false]);
        Currency::forgetActiveCodes();

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Sterlin Cari'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'currency_code' => 'GBP',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertStatus(422)->assertJsonValidationErrors('currency_code');
    }

    public function test_odeme_varsayilan_belge_kurunu_isler(): void
    {
        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => '2026-10-25', 'rate' => 34.0, 'source' => 'tcmb',
        ]);

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Kur Cari'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        // Belge 25 Ekim kuruyla kesildi (34.0).
        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'currency_code' => 'USD',
            'issue_date' => '2026-10-25',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        // Bugün kur 35.0 olsa da tahsilat BELGE kuruyla (34.0) işlenir.
        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => now()->format('Y-m-d'), 'rate' => 35.0, 'source' => 'tcmb',
        ]);

        $odeme = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 100,
            'document_id' => $belge['id'], 'currency_code' => 'USD', 'method' => 'cash',
        ], $this->a['headers'])->assertCreated()->json('payment');

        $this->assertSame(34.0, round((float) $odeme['exchange_rate'], 4));
    }

    public function test_odeme_guncel_kurla_isenebilir(): void
    {
        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => '2026-10-25', 'rate' => 34.0, 'source' => 'tcmb',
        ]);
        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => now()->format('Y-m-d'), 'rate' => 35.0, 'source' => 'tcmb',
        ]);

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Kur Cari 2'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $belge = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'currency_code' => 'USD',
            'issue_date' => '2026-10-25',
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ], $this->a['headers'])->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$belge['id']}/status", ['status' => 'issued'], $this->a['headers'])->assertOk();

        $odeme = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 100,
            'document_id' => $belge['id'], 'currency_code' => 'USD', 'method' => 'cash',
            'rate_source' => 'current',
        ], $this->a['headers'])->assertCreated()->json('payment');

        $this->assertSame(35.0, round((float) $odeme['exchange_rate'], 4));

        // Denetimde hangi kurun seçildiği görünür.
        $olay = \App\Models\AuditEvent::where('action', 'payment.created')->latest('id')->first();
        $this->assertSame('current', $olay->meta['rate_source']);
        $this->assertSame(35.0, round((float) $olay->meta['rate'], 4));
    }

    public function test_belgesiz_odemede_belge_kuru_secilemez_acik_kur_gecerlidir(): void
    {
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Kur Cari 3'], $this->a['headers'])
            ->assertCreated()->json('party.id');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10,
            'currency_code' => 'USD', 'rate_source' => 'document',
        ], $this->a['headers'])->assertStatus(422)->assertJsonValidationErrors('rate_source');

        // Açık kur her zaman geçerlidir (belgesiz USD tahsilat bugünkü kurla da olur).
        ExchangeRate::create([
            'currency_code' => 'USD', 'rate_date' => now()->format('Y-m-d'), 'rate' => 35.0, 'source' => 'tcmb',
        ]);

        $odeme = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10,
            'currency_code' => 'USD', 'rate_source' => 'current',
        ], $this->a['headers'])->assertCreated()->json('payment');

        $this->assertSame(35.0, round((float) $odeme['exchange_rate'], 4));
    }

    public function test_para_listesi_herkese_acik_tanim_yalniz_admine(): void
    {
        $liste = $this->apiGet('/api/v1/currencies', $this->a['headers'])->assertOk()->json('data');
        $kodlar = array_column($liste, 'code');

        $this->assertContains('TRY', $kodlar);
        $this->assertContains('USD', $kodlar);

        $try = collect($liste)->firstWhere('code', 'TRY');
        $this->assertSame(1.0, (float) $try['rate_today']);

        // Kur uçları üyeye açık.
        $this->apiGet('/api/v1/exchange-rates', $this->a['headers'])->assertOk();

        // Personel tanımı değiştiremez.
        $this->apiPost('/api/v1/invitations', ['email' => 'uye@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();
        $uye = $this->register('uye@firma.test', 'Uye Firmasi');
        $token = \App\Models\Invitation::where('company_id', $this->a['company_id'])->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();

        $this->apiPatch('/api/v1/currencies/USD', ['decimals' => 3], $uye['headers'])->assertForbidden();

        // Admin değiştirebilir; TRY kapatılamaz.
        $this->apiPatch('/api/v1/currencies/USD', ['decimals' => 3], $this->a['headers'])
            ->assertOk()->assertJsonPath('currency.decimals', 3);
        $this->apiPatch('/api/v1/currencies/TRY', ['is_active' => false], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_kur_cekme_ucu_adminle_calisir_personelle_403(): void
    {
        Http::fake([
            'tcmb.gov.tr*' => Http::response($this->tcmbXml(['USD' => '34.1234', 'EUR' => '37.5']), 200),
        ]);

        $gun = now()->format('Y-m-d');
        $res = $this->apiPost('/api/v1/exchange-rates/fetch', [], $this->a['headers'])
            ->assertOk()->assertJsonPath('source', 'tcmb');
        $this->assertNotEmpty($res->json('rates'));
        $this->assertSame(34.1234, ExchangeRates::rateFor('USD', $gun));

        // Personel çekemez.
        $this->apiPost('/api/v1/invitations', ['email' => 'uye-kur@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();
        $uye = $this->register('uye-kur@firma.test', 'Uye Kur Firmasi');
        $token = \App\Models\Invitation::where('company_id', $this->a['company_id'])->orderByDesc('id')->first()->token;
        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/companies/select', ['company_id' => $this->a['company_id']], $uye['headers'])->assertOk();
        $this->apiPost('/api/v1/exchange-rates/fetch', [], $uye['headers'])->assertForbidden();
    }

    public function test_iki_kaynak_duserse_cekme_ucu_422_doner(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->apiPost('/api/v1/exchange-rates/fetch', [], $this->a['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('source');
    }
}
