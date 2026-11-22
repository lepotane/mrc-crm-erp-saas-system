<?php

namespace Tests\Feature\Integration\Finance;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Orders\OrderSyncService;
use App\Integration\Payments\TsoftPaymentImportService;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/** Tsoft onaylı ödeme içe aktarımı: mükerrer koruma + KVKK (kişisel veri yok). */
final class TsoftPaymentImportTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        // Servis dış sisteme çağrı yapmaz; yine de ağ erişimini kapatırız.
        Http::fake();
    }

    public function test_onayli_odeme_tahsilat_olur_ve_idempotendir(): void
    {
        $companyId = $this->register('fin-pay-1@firma.test', 'Finans Odeme')['company_id'];
        $order = $this->order('ORD-FIN-1', '1', 'TX-FIN-1');

        (new OrderSyncService)->ingest($companyId, $order);
        $service = new TsoftPaymentImportService;

        $first = $service->import($companyId, [$order]);
        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(1, Payment::where('company_id', $companyId)->count());

        $payment = Payment::where('company_id', $companyId)->firstOrFail();
        $this->assertSame('in', $payment->direction);
        $this->assertSame('card', $payment->method);
        $this->assertSame('150.0000', $payment->amount);
        $this->assertSame('TX-FIN-1', $payment->reference);

        $second = $service->import($companyId, [$order]);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped_mapped']);
        $this->assertSame(1, Payment::where('company_id', $companyId)->count());
    }

    public function test_onaysiz_odeme_atlanir(): void
    {
        $companyId = $this->register('fin-pay-2@firma.test', 'Finans Onaysiz')['company_id'];
        $order = $this->order('ORD-FIN-2', '0', 'TX-FIN-2');

        (new OrderSyncService)->ingest($companyId, $order);

        $result = (new TsoftPaymentImportService)->import($companyId, [$order]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped_not_approved']);
        $this->assertSame(0, Payment::where('company_id', $companyId)->count());
    }

    public function test_scan_documents_minimal_ve_kisiteli_satir_dondurur(): void
    {
        $companyId = $this->register('fin-pay-3@firma.test', 'Finans Tarama')['company_id'];
        $order = $this->order('ORD-FIN-3', '1', 'TX-FIN-3');
        (new OrderSyncService)->ingest($companyId, $order);

        $rows = (new TsoftPaymentImportService)->scanDocuments($companyId, 10);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('ORD-FIN-3', $row['order_external_ref']);
        $this->assertSame('TX-FIN-3', $row['payment_external_ref']);
        $this->assertSame('150.0000', $row['amount']);
        $this->assertArrayNotHasKey('name', $row);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('tax_number', $row);

        $result = (new TsoftPaymentImportService)->import($companyId, $rows);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, Payment::where('company_id', $companyId)->count());
    }

    private function order(string $externalRef, string $approved, string $transaction): CanonicalOrder
    {
        return new CanonicalOrder(
            $externalRef,
            $externalRef,
            'TRY',
            '150.0000',
            '6',
            $transaction,
            $approved === '1',
            Carbon::parse('2026-11-20 10:00:00'),
            null,
            null,
            [new CanonicalOrderLine('SKU-FIN-1', 'Ürün 1', '1.0000', '150.0000', '0', '0')],
            [
                'OrderStatusId' => '6',
                'PaymentApproved' => $approved,
                'PaymentTransactionId' => $transaction,
                'PaymentMethod' => 'kart',
                'OrderTotalPrice' => '150.0000',
                'CustomerCode' => 'MUS-1',
            ],
        );
    }
}
