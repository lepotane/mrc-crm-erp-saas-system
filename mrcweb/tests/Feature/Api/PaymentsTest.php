<?php

namespace Tests\Feature\Api;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentsTest extends TestCase
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

    protected function createParty(array $headers, string $name = 'Müşteri Ltd', array $overrides = []): int
    {
        return $this->apiPost('/api/v1/parties', array_merge(['name' => $name], $overrides), $headers)
            ->assertCreated()->json('party.id');
    }

    protected function createIssuedDocument(array $headers, int $partyId, string $type, float $total, array $overrides = []): array
    {
        $doc = $this->apiPost('/api/v1/documents', array_merge([
            'type' => $type,
            'party_id' => $partyId,
            'items' => [['name' => 'Hizmet', 'quantity' => 1, 'unit_price' => $total, 'tax_rate' => 0]],
        ], $overrides), $headers)->assertCreated()->json('document');

        $this->apiPost("/api/v1/documents/{$doc['id']}/status", ['status' => 'issued'], $headers)->assertOk();

        return $doc;
    }

    public function test_tahsilat_alacagi_dusurur_belge_durumunu_gunceller(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $invoice = $this->createIssuedDocument($headers, $partyId, 'invoice', 120);

        // Kısmi tahsilat → partially_paid, bakiye 80
        $first = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 40,
            'document_id' => $invoice['id'], 'method' => 'cash',
        ], $headers)->assertCreated();

        $this->assertSame('partially_paid', $first->json('document_status'));
        $this->assertSame(80.0, round((float) $first->json('party_balance'), 4));
        $payment = $first->json('payment');
        $this->assertSame('in', $payment['direction']);
        $this->assertSame('Müşteri Ltd', $payment['party_name']);
        $this->assertSame($invoice['serial'], $payment['document_serial']);

        // Kalan tutar → paid, bakiye 0
        $second = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 80,
            'document_id' => $invoice['id'],
        ], $headers)->assertCreated();

        $this->assertSame('paid', $second->json('document_status'));
        $this->assertSame(0.0, round((float) $second->json('party_balance'), 4));

        $this->assertSame(2, $this->apiGet("/api/v1/payments?document_id={$invoice['id']}", $headers)
            ->assertOk()->json('meta.total'));

        // Ödeme silinince durum geriye döner
        $paymentId = $second->json('payment.id');
        $deleted = $this->apiDelete("/api/v1/payments/{$paymentId}", [], $headers)->assertOk();
        $this->assertSame('partially_paid', $deleted->json('document_status'));
        $this->assertSame('Ödeme silindi.', $deleted->json('message'));
    }

    public function test_odeme_belge_turu_uyumsuzlugunda_422_doner(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $customerId = $this->createParty($headers);
        $invoice = $this->createIssuedDocument($headers, $customerId, 'invoice', 100);

        // Satış faturasına çıkış yönlü ödeme bağlanamaz
        $this->apiPost('/api/v1/payments', [
            'party_id' => $customerId, 'direction' => 'out', 'amount' => 10,
            'document_id' => $invoice['id'],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('direction');

        // Tedarikçi cariye tahsilat kaydedilemez
        $supplierId = $this->createParty($headers, 'Tedarikçi AŞ', ['type' => 'supplier']);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $supplierId, 'direction' => 'in', 'amount' => 10,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.direction.0', 'Bu cari hesap tipi için bu yönde ödeme kaydedilemez.');

        // Tedarikçiye alım faturası ödemesi serbesttir
        $purchase = $this->createIssuedDocument($headers, $supplierId, 'purchase', 500);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $supplierId, 'direction' => 'out', 'amount' => 200,
            'document_id' => $purchase['id'],
        ], $headers)->assertCreated()->assertJsonPath('document_status', 'partially_paid');
    }

    public function test_odeme_tutar_sifir_veya_tersi_yonde_red_eder(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 0,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Tutar sıfırdan büyük olmalıdır.');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => -50,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_odeme_para_birimi_belgeyle_eslesmelidir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);
        $invoice = $this->createIssuedDocument($headers, $partyId, 'invoice', 100, ['currency_code' => 'USD']);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10,
            'document_id' => $invoice['id'], 'currency_code' => 'TRY',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('currency_code');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10,
            'document_id' => $invoice['id'], 'currency_code' => 'USD',
        ], $headers)->assertCreated();
    }

    public function test_tenant_izolasyonu_odeme_listesi(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $partyId = $this->createParty($headersA);
        $paymentId = $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10,
        ], $headersA)->assertCreated()->json('payment.id');

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $this->assertSame(0, $this->apiGet('/api/v1/payments', $headersB)->assertOk()->json('meta.total'));
        $this->apiDelete("/api/v1/payments/{$paymentId}", [], $headersB)->assertNotFound();
        $this->assertSame(1, $this->apiGet('/api/v1/payments', $headersA)->assertOk()->json('meta.total'));
    }

    public function test_teklif_silinmis_belgeye_odeme_baglanamaz(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        // Taslak belgeye ödeme bağlanamaz
        $draftId = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $partyId,
            'items' => [['name' => 'Kalem', 'quantity' => 1, 'unit_price' => 10]],
        ], $headers)->assertCreated()->json('document.id');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 5, 'document_id' => $draftId,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.document_id.0', 'Seçilen belge bu ödeme için uygun değil.');

        // Belge kaydı kalkınca bağlı ödemenin document_id'si null kalır
        $invoice = $this->createIssuedDocument($headers, $partyId, 'invoice', 100);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 30,
            'document_id' => $invoice['id'],
        ], $headers)->assertCreated();

        Document::find($invoice['id'])?->forceDelete();

        $rows = $this->apiGet('/api/v1/payments', $headers)->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['document_id']);
    }

    public function test_ingilizce_dilde_odeme_hatalari(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $headers)->assertOk();

        $supplierId = $this->createParty($headers, 'Supplier Co', ['type' => 'supplier']);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 0,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Amount must be greater than zero.');

        $this->apiPost('/api/v1/payments', [
            'party_id' => $supplierId, 'direction' => 'in', 'amount' => 10,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.direction.0', 'A payment in this direction cannot be recorded for this account type.');
    }

    public function test_odeme_baska_carinin_belgesine_baglanamaz(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $musteriA = $this->createParty($headers, 'A Cari');
        $musteriB = $this->createParty($headers, 'B Cari');
        $invoiceB = $this->createIssuedDocument($headers, $musteriB, 'invoice', 120);

        // A’nın tahsilatını B’nin faturasına bağlamak: belge „paid” olur ama bakiye
        // A hanesinde düşer → iki taraf da yanlış okur. Engellenmeli.
        $this->apiPost('/api/v1/payments', [
            'party_id' => $musteriA, 'direction' => 'in', 'amount' => 120,
            'document_id' => $invoiceB['id'], 'method' => 'cash',
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.document_id.0', 'Seçilen belge bu cari hesaba ait değil.');

        // B doğru carisiyle tahsil edebilir
        $this->apiPost('/api/v1/payments', [
            'party_id' => $musteriB, 'direction' => 'in', 'amount' => 120,
            'document_id' => $invoiceB['id'], 'method' => 'cash',
        ], $headers)->assertCreated()->assertJsonPath('document_status', 'paid');

        // A’nın bakiyesi etkilenmemiş olmalı
        $this->assertSame(0.0, round((float) $this->apiGet("/api/v1/parties/{$musteriA}/balance", $headers)
            ->assertOk()->json('totals.balance'), 4));
    }

    public function test_hesap_para_birimi_odemeyle_eslesmelidir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $usdHesap = $this->apiPost('/api/v1/bank-accounts', [
            'name' => 'Döviz Hesabı', 'type' => 'bank', 'currency_code' => 'USD',
        ], $headers)->assertCreated()->json('bank_account.id');

        // v1’de kur çevrimi yok: TRY tahsilat USD hesaba işlenemez.
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 50,
            'currency_code' => 'TRY', 'method' => 'bank', 'bank_account_id' => $usdHesap,
        ], $headers)->assertStatus(422)
            ->assertJsonPath('errors.bank_account_id.0', 'Ödeme para birimi hesap para birimiyle aynı olmalıdır.');

        // aynı hesap, doğru para birimi → kaydedilir
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 50,
            'currency_code' => 'USD', 'method' => 'bank', 'bank_account_id' => $usdHesap,
        ], $headers)->assertCreated();
    }

    public function test_kur_degeri_sifir_veya_negatif_olamaz(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 10, 'exchange_rate' => 0,
        ], $headers)->assertStatus(422);
    }
}
