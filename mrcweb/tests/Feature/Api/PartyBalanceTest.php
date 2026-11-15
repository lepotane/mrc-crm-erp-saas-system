<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyBalanceTest extends TestCase
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

    protected function createParty(array $headers, string $name, array $overrides = []): array
    {
        return $this->apiPost('/api/v1/parties', array_merge(['name' => $name], $overrides), $headers)
            ->assertCreated()->json('party');
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

    public function test_cari_bakiye_alacak_hesabi_ve_yaslandirma(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $party = $this->createParty($headers, 'Müşteri Ltd', ['opening_balance' => 100]);
        $partyId = $party['id'];

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $invoice = $this->createIssuedDocument($headers, $partyId, 'invoice', 1000, ['due_date' => $yesterday]);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 400,
            'document_id' => $invoice['id'], 'reference' => 'DEKONT-1',
        ], $headers)->assertCreated()->assertJsonPath('document_status', 'partially_paid');

        $balance = $this->apiGet("/api/v1/parties/{$partyId}/balance", $headers)->assertOk();

        // balance = 100 + 1000 − 400 = 700 (alacağımız)
        $this->assertSame(700.0, round((float) $balance->json('totals.balance'), 4));
        $this->assertSame(1000.0, round((float) $balance->json('totals.sales_total'), 4));
        $this->assertSame(400.0, round((float) $balance->json('totals.receipts_total'), 4));

        $open = $balance->json('open_documents');
        $this->assertCount(1, $open);
        $this->assertSame(600.0, round((float) $open[0]['open_amount'], 4));
        $this->assertSame(400.0, round((float) $open[0]['paid_total'], 4));

        // Dün vadesi dolan belge 1-30 kutusunda
        $this->assertSame(600.0, round((float) $balance->json('aging.days_1_30'), 4));
        $this->assertSame(0.0, round((float) $balance->json('aging.not_due'), 4));

        // Ödeme listesinde son tahsilat görünür
        $this->assertCount(1, $balance->json('payments'));
        $this->assertSame('DEKONT-1', $balance->json('payments.0.reference'));

        // Vadesi 10 gün sonra olan ikinci fatura not_due kutusuna düşer
        $future = date('Y-m-d', strtotime('+10 days'));
        $this->createIssuedDocument($headers, $partyId, 'invoice', 500, ['due_date' => $future]);

        $second = $this->apiGet("/api/v1/parties/{$partyId}/balance", $headers)->assertOk();
        $openDocs = $second->json('open_documents');
        $this->assertCount(2, $openDocs);
        // Vade artan sıra: dün vadesi dolan önce
        $this->assertSame($yesterday, $openDocs[0]['due_date']);
        $this->assertSame($future, $openDocs[1]['due_date']);
        $this->assertSame(500.0, round((float) $second->json('aging.not_due'), 4));
        $this->assertSame(1200.0, round((float) $second->json('totals.balance'), 4));

        // Para birimi ayrımı: USD belge + USD tahsilat
        $usdInvoice = $this->createIssuedDocument($headers, $partyId, 'invoice', 100, ['currency_code' => 'USD']);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'in', 'amount' => 40,
            'document_id' => $usdInvoice['id'], 'currency_code' => 'USD',
        ], $headers)->assertCreated();

        $third = $this->apiGet("/api/v1/parties/{$partyId}/balance", $headers)->assertOk();
        $byCcy = $third->json('by_currency');
        $this->assertSame(100.0, round((float) $byCcy['USD']['sales'], 4));
        $this->assertSame(40.0, round((float) $byCcy['USD']['receipts'], 4));
        $this->assertSame(60.0, round((float) $byCcy['USD']['balance'], 4));
        $this->assertSame(1500.0, round((float) $byCcy['TRY']['sales'], 4));
    }

    public function test_tedarikci_borc_bakiyesi_negatif(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $partyId = $this->createParty($headers, 'Tedarikçi AŞ', ['type' => 'supplier'])['id'];
        $purchase = $this->createIssuedDocument($headers, $partyId, 'purchase', 500);

        $this->apiPost('/api/v1/payments', [
            'party_id' => $partyId, 'direction' => 'out', 'amount' => 200,
            'document_id' => $purchase['id'],
        ], $headers)->assertCreated()->assertJsonPath('document_status', 'partially_paid');

        $balance = $this->apiGet("/api/v1/parties/{$partyId}/balance", $headers)->assertOk();

        // balance = 0 + 0 − 0 − 500 + 200 = −300 (borcumuz)
        $this->assertSame(-300.0, round((float) $balance->json('totals.balance'), 4));
        $this->assertSame(500.0, round((float) $balance->json('totals.purchases_total'), 4));
        $this->assertSame(200.0, round((float) $balance->json('totals.payments_total'), 4));
        $this->assertCount(1, $balance->json('open_documents'));
        $this->assertSame(300.0, round((float) $balance->json('open_documents.0.open_amount'), 4));
        // Alım faturası yaşlandırmaya girmez
        $this->assertSame(0.0, round((float) $balance->json('aging.days_1_30') + (float) $balance->json('aging.not_due'), 4));
    }

    public function test_bakiye_tenant_izolasyonu(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $partyId = $this->createParty($headersA, 'Müşteri')['id'];

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $this->apiGet("/api/v1/parties/{$partyId}/balance", $headersB)->assertNotFound();
    }
}
