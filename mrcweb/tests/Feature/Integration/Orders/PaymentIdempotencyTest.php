<?php

namespace Tests\Feature\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Orders\OrderSyncService;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_ayni_odeme_iki_kez_gelirse_tek_odeme_ve_durum_guncellenir(): void
    {
        $ctx = $this->register('paymentidem@firma.test', 'Payment Idempotency');
        $companyId = $ctx['company_id'];
        $service = new OrderSyncService;

        $order = new CanonicalOrder(
            'ORD-400',
            'SIP-400',
            'TRY',
            '100.0000',
            '',
            'PAY-400',
            true,
            Carbon::parse('2026-11-20 10:00:00'),
            null,
            null,
            [new CanonicalOrderLine('SKU-1', 'Ürün 1', '1.0000', '100.0000', '0', '0')],
        );
        $document = $service->ingest($companyId, $order);

        $payment = new CanonicalPayment(
            'PAY-EXT-400',
            'ORD-400',
            '100.0000',
            'TRY',
            'kart',
            Carbon::parse('2026-11-20 11:00:00'),
            'TX-400',
        );

        $first = $service->markPaid($companyId, 'ORD-400', $payment);
        $second = $service->markPaid($companyId, 'ORD-400', $payment);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::where('company_id', $companyId)->count());
        $this->assertSame('100.0000', $first->fresh()->amount);
        $this->assertSame('card', $first->fresh()->method);
        $this->assertSame('in', $first->fresh()->direction);
        $this->assertSame($document->id, $first->fresh()->document_id);
        $this->assertSame('paid', $document->fresh()->status);
    }
}
