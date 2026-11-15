<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * MRC BRIDGE .
 *
 * Kilitlenen sözleşme: eşleşme kodu tek kullanımlık + süreli; yoklama 204 ya da
 * kilitli iş (`claim_token`); ACK yalnız güncel kilitle kabul edilir (çift baskı
 * yok); basılan satır silinir; sahipsiz kilit yeniden dağıtılır; deneme kotası
 * dolan terminal `failed` olur ve 24 saatte budanır.
 */
class BridgeTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a = [];

    protected int $partyId = 0;

    protected int $productId = 0;

    protected int $docId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('bridge@firma.test', 'Bridge Firmasi');
        $h = $this->a['headers'];

        $this->partyId = $this->apiPost('/api/v1/parties', ['name' => 'Bridge Cari', 'type' => 'customer'], $h)
            ->assertCreated()->json('party.id');

        $this->productId = $this->apiPost('/api/v1/products', ['name' => 'Bridge Ürünü', 'sale_price' => 100], $h)
            ->assertCreated()->json('product.id');

        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $this->productId,
            'warehouse_id' => $this->defaultWarehouseId($this->a),
            'type' => 'in',
            'quantity' => 10,
            'unit_cost' => 10,
        ], $h)->assertCreated();

        $this->docId = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $this->partyId,
            'items' => [['product_id' => $this->productId, 'quantity' => 1, 'unit_price' => 100]],
        ], $h)->assertCreated()->json('document.id');
        $this->apiPost("/api/v1/documents/{$this->docId}/status", ['status' => 'issued'], $h)->assertOk();
    }

    /** Cihaz aç + eşleştir → cihaz anahtarı. */
    protected function bridgeHeaders(): array
    {
        $h = $this->a['headers'];

        $kod = $this->apiPost('/api/v1/devices', ['name' => 'Kasa PC'], $h)
            ->assertCreated()->json('pairing_code');

        $token = $this->apiPost('/api/v1/bridge/pair', [
            'pairing_code' => $kod,
            'printers' => ['Termal-80'],
        ])->assertOk()->json('token');

        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array{job: array{id: int}} */
    protected function kuyrugaAt(array $bh): array
    {
        $cihaz = Device::where('company_id', $this->a['company_id'])->first();

        $res = $this->apiPost('/api/v1/print-jobs', [
            'device_id' => $cihaz->id,
            'printer_name' => 'Termal-80',
            'kind' => 'escpos',
            'document_id' => $this->docId,
        ], $this->a['headers'])->assertCreated();

        return $res->json();
    }

    public function test_eslesme_kodu_tek_kullanimlik_ve_surelidir(): void
    {
        $h = $this->a['headers'];

        $kod = $this->apiPost('/api/v1/devices', ['name' => 'Kasa PC'], $h)
            ->assertCreated()->json('pairing_code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $kod);

        // İkinci kullanım reddedilir.
        $this->apiPost('/api/v1/bridge/pair', ['pairing_code' => $kod, 'printers' => ['X']], $h)->assertOk();
        $this->apiPost('/api/v1/bridge/pair', ['pairing_code' => $kod, 'printers' => ['X']])->assertNotFound();

        // Süresi dolmuş kod reddedilir.
        $kod2 = $this->apiPost('/api/v1/devices', ['name' => 'Kasa 2'], $h)
            ->assertCreated()->json('pairing_code');
        Device::where('company_id', $this->a['company_id'])->where('name', 'Kasa 2')
            ->update(['pairing_expires_at' => now()->subMinute()]);
        $this->apiPost('/api/v1/bridge/pair', ['pairing_code' => $kod2, 'printers' => ['X']])->assertNotFound();
    }

    public function test_bos_kuyruk_204_doner_anahtarsiz_401(): void
    {
        $bh = $this->bridgeHeaders();

        $this->apiGet('/api/v1/bridge/poll', $bh)->assertNoContent();
        $this->apiGet('/api/v1/bridge/poll')->assertUnauthorized();
    }

    public function test_kuyruk_kilit_ack_dongusu_cift_baskisiz(): void
    {
        $bh = $this->bridgeHeaders();
        $this->kuyrugaAt($bh);

        // İlk yoklama işi kilitler.
        $is = $this->apiGet('/api/v1/bridge/poll', $bh)->assertOk()->json('job');
        $this->assertSame('Termal-80', $is['printer_name']);
        $this->assertNotEmpty($is['claim_token']);
        $this->assertSame(1, (int) $is['attempts']);

        // İkinci yoklama boş döner (kilit başkasına verilmez).
        $this->apiGet('/api/v1/bridge/poll', $bh)->assertNoContent();

        // Yanlış kilit reddedilir.
        $this->apiPost("/api/v1/bridge/jobs/{$is['id']}/ack", [
            'claim_token' => 'yanlis', 'status' => 'printed',
        ], $bh)->assertForbidden();

        // Doğru kilit: basıldı → satır silinir.
        $this->apiPost("/api/v1/bridge/jobs/{$is['id']}/ack", [
            'claim_token' => $is['claim_token'], 'status' => 'printed',
        ], $bh)->assertOk()->assertJsonPath('deleted', true);

        $this->assertSame(0, PrintJob::count());

        // Silinmiş işe tekrar ACK 404.
        $this->apiPost("/api/v1/bridge/jobs/{$is['id']}/ack", [
            'claim_token' => $is['claim_token'], 'status' => 'printed',
        ], $bh)->assertNotFound();
    }

    public function test_sahipsiz_kilit_yeniden_dagitilir(): void
    {
        $bh = $this->bridgeHeaders();
        $this->kuyrugaAt($bh);

        $is = $this->apiGet('/api/v1/bridge/poll', $bh)->assertOk()->json('job');

        // ACK gelmeden kilit eskisin.
        PrintJob::whereKey($is['id'])->update(['claimed_at' => now()->subSeconds(61)]);

        $yeni = $this->apiGet('/api/v1/bridge/poll', $bh)->assertOk()->json('job');
        $this->assertSame($is['id'], $yeni['id']);
        $this->assertNotSame($is['claim_token'], $yeni['claim_token']);
        $this->assertSame(2, (int) $yeni['attempts']);

        // Eski kilit artık geçersiz.
        $this->apiPost("/api/v1/bridge/jobs/{$is['id']}/ack", [
            'claim_token' => $is['claim_token'], 'status' => 'printed',
        ], $bh)->assertForbidden();
    }

    public function test_deneme_kotasi_dolan_terminal_failed_olur_ve_budanir(): void
    {
        $bh = $this->bridgeHeaders();
        $this->kuyrugaAt($bh);

        $is = null;

        for ($i = 0; $i <= PrintJob::MAX_ATTEMPTS; $i++) {
            $is = $this->apiGet('/api/v1/bridge/poll', $bh)->assertOk()->json('job');
            PrintJob::whereKey($is['id'])->update(['claimed_at' => now()->subSeconds(61)]);
            $is = $this->apiGet('/api/v1/bridge/poll', $bh)->assertOk()->json('job');

            $res = $this->apiPost("/api/v1/bridge/jobs/{$is['id']}/ack", [
                'claim_token' => $is['claim_token'], 'status' => 'failed', 'error' => 'Kağıt sıkıştı',
            ], $bh)->assertOk();

            if (($res->json('status') ?? null) === 'failed') {
                break;
            }
        }

        $this->assertSame('failed', PrintJob::find($is['id'])?->status);

        // 24 saatlik budama eski başarısızı siler.
        PrintJob::whereKey($is['id'])->update(['created_at' => now()->subHours(25)]);
        $this->artisan('bridge:prune')->assertSuccessful();
        $this->assertNull(PrintJob::find($is['id']));
    }

    public function test_baska_firmanin_isi_gorunmez(): void
    {
        $bh = $this->bridgeHeaders();
        $this->kuyrugaAt($bh);

        $b = $this->register('bridge-b@firma.test', 'Bridge B Firmasi');
        $kod = $this->apiPost('/api/v1/devices', ['name' => 'B PC'], $b['headers'])
            ->assertCreated()->json('pairing_code');
        $tokenB = $this->apiPost('/api/v1/bridge/pair', [
            'pairing_code' => $kod, 'printers' => ['X'],
        ])->assertOk()->json('token');

        // B'nin yoklaması boş: A'nın işi B'ye görünmez.
        $this->apiGet('/api/v1/bridge/poll', ['Authorization' => 'Bearer '.$tokenB])->assertNoContent();
    }

    public function test_anahtar_dondurme_eskisini_dusurur(): void
    {
        $bh = $this->bridgeHeaders();

        $yeni = $this->apiPost('/api/v1/bridge/rotate', [], $bh)->assertOk()->json('token');
        $this->assertNotSame($bh['Authorization'], 'Bearer '.$yeni);

        $this->apiGet('/api/v1/bridge/poll', $bh)->assertUnauthorized();
        $this->apiGet('/api/v1/bridge/poll', ['Authorization' => 'Bearer '.$yeni])->assertNoContent();
    }
}
