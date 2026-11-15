<?php

namespace Tests\Feature\Api;

use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccountsTest extends TestCase
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

    public function test_ilk_hesap_is_default_false_gonderilse_bile_varsayilan_olur(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        // SPA formu is_default alanını her zaman gönderir (false dahil).
        // PHP’de $data + $defaults birleşiminde sol kazandığı için ilk hesabın
        // varsayılan işareti eziliyordu → sunucu kuralı geçerli olmalı.
        $first = $this->apiPost('/api/v1/bank-accounts', [
            'name' => 'Nakit Kasa', 'type' => 'cash', 'is_default' => false,
        ], $headers)->assertCreated()->json('bank_account');
        $this->assertTrue($first['is_default']);

        // ikinci hesap açıkça false ile açılırsa varsayılan devralınmaz
        $second = $this->apiPost('/api/v1/bank-accounts', [
            'name' => 'İkinci Kasa', 'type' => 'cash', 'is_default' => false,
        ], $headers)->assertCreated()->json('bank_account');
        $this->assertFalse($second['is_default']);

        $list = collect($this->apiGet('/api/v1/bank-accounts', $headers)->assertOk()->json('bank_accounts'))->keyBy('name');
        $this->assertTrue($list['Nakit Kasa']['is_default']);
        $this->assertFalse($list['İkinci Kasa']['is_default']);
    }

    public function test_kasa_hesabi_olusturulur_ve_odeme_sayaci_doner(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        // İlk hesap otomatik varsayılan olur
        $first = $this->apiPost('/api/v1/bank-accounts', ['name' => 'Ana Kasa'], $headers)
            ->assertCreated()->json('bank_account');
        $this->assertTrue($first['is_default']);
        $this->assertSame(0.0, round((float) $first['balance'], 4));

        // İkinci hesap is_default ile açılınca ilki pasifleşir
        $second = $this->apiPost('/api/v1/bank-accounts', [
            'name' => 'Banka', 'type' => 'bank', 'iban' => 'TR000000000000000000000001', 'is_default' => true,
        ], $headers)->assertCreated()->json('bank_account');
        $this->assertTrue($second['is_default']);

        $list = $this->apiGet('/api/v1/bank-accounts', $headers)->assertOk()->json('bank_accounts');
        $this->assertCount(2, $list);
        $byName = collect($list)->keyBy('name');
        $this->assertFalse($byName['Ana Kasa']['is_default']);
        $this->assertTrue($byName['Banka']['is_default']);

        // Hesaba tahsilat bağlanır: sayaç ve bakiye güncellenir
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Müşteri Ltd'], $headers)
            ->assertCreated()->json('party.id');
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 250,
            'bank_account_id' => $second['id'],
        ], $headers)->assertCreated();

        $after = collect($this->apiGet('/api/v1/bank-accounts', $headers)->assertOk()->json('bank_accounts'))
            ->keyBy('name');
        $this->assertSame(1, $after['Banka']['payments_count']);
        $this->assertSame(250.0, round((float) $after['Banka']['balance'], 4));

        // type filtresi
        $onlyCash = $this->apiGet('/api/v1/bank-accounts?type=cash', $headers)->assertOk()->json('bank_accounts');
        $this->assertCount(1, $onlyCash);
        $this->assertSame('Ana Kasa', $onlyCash[0]['name']);

        // PATCH ile isim/iban güncellenir
        $this->apiPatch("/api/v1/bank-accounts/{$second['id']}", [
            'name' => 'Ana Banka', 'iban' => 'TR990000000000000000000099',
        ], $headers)->assertOk()->assertJsonPath('bank_account.name', 'Ana Banka')
            ->assertJsonPath('bank_account.iban', 'TR990000000000000000000099');

        // Hareketli hesap silinemez
        $this->apiDelete("/api/v1/bank-accounts/{$second['id']}", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bu hesapta ödeme kaydı mevcut; silinemez.');

        // Boş hesap silinir
        $emptyId = $this->apiPost('/api/v1/bank-accounts', ['name' => 'Boş Kasa'], $headers)
            ->assertCreated()->json('bank_account.id');
        $this->apiDelete("/api/v1/bank-accounts/{$emptyId}", [], $headers)
            ->assertOk()->assertJsonPath('message', 'Kasa/banka hesabı silindi.');

        // Hesap kaydının DB'den kalkması bağlı ödemeyi null bırakır (nullOnDelete)
        BankAccount::find($second['id'])?->forceDelete();
        $payments = $this->apiGet('/api/v1/payments', $headers)->assertOk()->json('data');
        $this->assertCount(1, $payments);
        $this->assertNull($payments[0]['bank_account_id']);
    }

    public function test_pasif_hesaba_odeme_baglanamaz(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $accountId = $this->apiPost('/api/v1/bank-accounts', ['name' => 'Kasa'], $headers)
            ->assertCreated()->json('bank_account.id');
        $this->apiPatch("/api/v1/bank-accounts/{$accountId}", ['is_active' => false], $headers)->assertOk();

        // aktif filtresi pasifi gizler
        $this->assertCount(0, $this->apiGet('/api/v1/bank-accounts?active=1', $headers)->assertOk()->json('bank_accounts'));
        $this->assertCount(1, $this->apiGet('/api/v1/bank-accounts?active=0', $headers)->assertOk()->json('bank_accounts'));

        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Müşteri'], $headers)
            ->assertCreated()->json('party.id');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10, 'bank_account_id' => $accountId,
        ], $headers)->assertStatus(422)->assertJsonPath('errors.bank_account_id.0', 'Pasif hesaba ödeme kaydedilemez.');
    }

    public function test_banka_hesabi_tenant_izolasyonu(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $accountId = $this->apiPost('/api/v1/bank-accounts', ['name' => 'Kasa'], $headersA)
            ->assertCreated()->json('bank_account.id');

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $this->assertCount(0, $this->apiGet('/api/v1/bank-accounts', $headersB)->assertOk()->json('bank_accounts'));
        $this->apiPatch("/api/v1/bank-accounts/{$accountId}", ['name' => 'X'], $headersB)->assertNotFound();
        $this->apiDelete("/api/v1/bank-accounts/{$accountId}", [], $headersB)->assertNotFound();
    }
}
