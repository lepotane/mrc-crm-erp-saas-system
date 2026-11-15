<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartiesTest extends TestCase
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

        return ['response' => $response, 'token' => $response->json('token')];
    }

    protected function headersFrom(array $registration): array
    {
        return ['Authorization' => "Bearer {$registration['token']}"];
    }

    public function test_cari_olusturma_otomatik_sira_kodu_atar(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $party = $this->apiPost('/api/v1/parties', [
            'name' => 'Ortaklar Motors Ltd.',
            'type' => 'customer',
            'tax_number' => '1234567890',
            'phone' => '+90 222 000 0000',
            'city' => 'Malatya',
        ], $headers)->assertCreated()->json('party');

        $this->assertSame('00001', $party['code']);
        $this->assertSame('TRY', $party['currency_code']);
        $this->assertSame(0.0, (float) $party['opening_balance']);

        // ikinci cari sırayı büyütür
        $second = $this->apiPost('/api/v1/parties', ['name' => 'İkinci Cari', 'type' => 'supplier'], $headers)
            ->assertCreated()->json('party');
        $this->assertSame('00002', $second['code']);
    }

    public function test_cari_listesinde_tip_ve_arama_filtreleri_calisir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $this->apiPost('/api/v1/parties', ['name' => 'Alıcı A', 'type' => 'customer'], $headers)->assertCreated();
        $this->apiPost('/api/v1/parties', ['name' => 'Tedarikçi B', 'type' => 'supplier'], $headers)->assertCreated();
        $this->apiPost('/api/v1/parties', ['name' => 'Hem Alım Satım', 'type' => 'both'], $headers)->assertCreated();

        // müşteri filtresi: customer + both görünür
        $customers = $this->apiGet('/api/v1/parties?type=customer', $headers)->assertOk()->json('data');
        $customerNames = array_column($customers, 'name');
        $this->assertCount(2, $customerNames);
        $this->assertContains('Alıcı A', $customerNames);

        // arama: ad parça eşleşmesi
        $found = $this->apiGet('/api/v1/parties?search=Tedarikçi', $headers)->assertOk()->json('data');
        $this->assertCount(1, $found);
        $this->assertSame('Tedarikçi B', $found[0]['name']);

        // paginator sözleşmesi (PLAN §6): liste cevabında meta bloğu
        $this->assertSame(3, $this->apiGet('/api/v1/parties', $headers)->assertOk()->json('meta.total'));
        $this->assertSame(20, $this->apiGet('/api/v1/parties', $headers)->assertOk()->json('meta.per_page'));
    }

    public function test_tenant_izolasyonu_cariyi_gizler(): void
    {
        $a = $this->registerUser(['company_name' => 'A Firması']);
        $headersA = $this->headersFrom($a);
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'A Müşterisi'], $headersA)->assertCreated()->json('party.id');

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B Firması']);
        $headersB = $this->headersFrom($b);

        // B listesinde A'nın carisi olmamalı
        $listB = $this->apiGet('/api/v1/parties', $headersB)->assertOk()->json('data');
        $this->assertSame([], $listB);

        // B, A'nın carisine erişemez (404 — varlık dışı)
        $this->apiGet("/api/v1/parties/{$partyId}", $headersB)->assertStatus(404);
        $this->apiPatch("/api/v1/parties/{$partyId}", ['name' => 'Saldırı Denemesi'], $headersB)->assertStatus(404);
        $this->apiDelete("/api/v1/parties/{$partyId}", [], $headersB)->assertStatus(404);
    }

    public function test_cari_guncelleme_ve_silme_calisir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Eski Ad', 'type' => 'customer'], $headers)
            ->assertCreated()->json('party.id');

        $this->apiPatch("/api/v1/parties/{$partyId}", [
            'name' => 'Yeni Ad', 'opening_balance' => 5000, 'type' => 'both',
        ], $headers)->assertOk()
            ->assertJsonPath('party.name', 'Yeni Ad')
            ->assertJsonPath('party.type', 'both');

        $this->apiDelete("/api/v1/parties/{$partyId}", [], $headers)->assertOk();
        $this->apiGet("/api/v1/parties/{$partyId}", $headers)->assertStatus(404);
    }

    public function test_cari_iletisim_kisileri_eklenir_ve_tek_birincil_kalir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Motors Ltd.'], $headers)->assertCreated()->json('party.id');
        $base = "/api/v1/parties/{$partyId}/contacts";

        $c1 = $this->apiPost($base, ['name' => 'Ahmet', 'phone' => '0555', 'is_primary' => true], $headers)->assertCreated()->json('contact');
        $c2 = $this->apiPost($base, ['name' => 'Zeynep', 'is_primary' => true], $headers)->assertCreated()->json('contact');

        $contacts = $this->apiGet($base, $headers)->assertOk()->json('contacts');
        $this->assertCount(2, $contacts);

        // yalnızca bir tane birincil iletişim kişisi kalır
        $primary = array_values(array_filter($contacts, fn ($c) => $c['is_primary']));
        $this->assertCount(1, $primary);
        $this->assertSame('Zeynep', $primary[0]['name']);

        // silme
        $this->apiDelete($base."/{$c2['id']}", [], $headers)->assertOk();
        $this->assertCount(1, $this->apiGet($base, $headers)->json('contacts'));
        $this->assertNotNull($c1['id']);
    }

    public function test_hareket_gormus_cari_sililemez(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Belgeli Cari'], $headers)
            ->assertCreated()->json('party.id');

        // cariye bağlı bir taslak belge aç
        $docId = $this->apiPost('/api/v1/documents', [
            'type' => 'quote', 'party_id' => $partyId,
            'items' => [['name' => 'Teklif Kalemi', 'quantity' => 1, 'unit_price' => 100]],
        ], $headers)->assertCreated()->json('document.id');

        // party_id FK’leri cascadeOnDelete: izin verilseydi belge de giderdi → engel
        $this->apiDelete("/api/v1/parties/{$partyId}", [], $headers)->assertStatus(422)
            ->assertJsonPath('message', 'Bu cari hesap silinemez: bağlı belge veya ödeme kaydı var.');

        $this->apiGet("/api/v1/documents/{$docId}", $headers)->assertOk();

        // belge kaldırılınca cari silinebilir
        $this->apiDelete("/api/v1/documents/{$docId}", [], $headers)->assertOk();
        $this->apiDelete("/api/v1/parties/{$partyId}", [], $headers)->assertOk();
    }
}
