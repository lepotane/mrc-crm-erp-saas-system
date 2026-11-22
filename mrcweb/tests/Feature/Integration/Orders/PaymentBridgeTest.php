<?php

namespace Tests\Feature\Integration\Orders;

use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\SyncResult;
use App\Integration\Orders\PaymentBridgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class PaymentBridgeTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_dry_run_da_adapter_cagirilmaz_ve_sonuc_skipped_donner(): void
    {
        $ctx = $this->register('bridge-dry@firma.test', 'Bridge Dry Run');
        $companyId = $ctx['company_id'];
        $fake = $this->fakeAdapter();
        $bridge = new PaymentBridgeService(null, fn (int $id): object => $fake);

        config()->set('integrations.dry_run', true);
        config()->set('integrations.channels.tsoft.payment_write_enabled', true);

        $result = $bridge->toTsoft($companyId, $this->payment());

        $this->assertTrue($result->skipped);
        $this->assertSame([], $fake->noteCalls);
        $this->assertSame([], $fake->statusCalls);
    }

    public function test_yazma_kapaliyken_adapter_cagirilmaz_ve_guvenli_skipped_donner(): void
    {
        $ctx = $this->register('bridge-off@firma.test', 'Bridge Kapali');
        $companyId = $ctx['company_id'];
        $fake = $this->fakeAdapter();
        $bridge = new PaymentBridgeService(null, fn (int $id): object => $fake);

        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.payment_write_enabled', false);

        $result = $bridge->toTsoft($companyId, $this->payment());

        $this->assertTrue($result->skipped || ! $result->ok);
        $this->assertSame([], $fake->noteCalls);
        $this->assertSame([], $fake->statusCalls);
    }

    public function test_acik_kipte_not_bir_kez_yazilir_ve_kisisel_veri_tasinmaz(): void
    {
        $ctx = $this->register('bridge-on@firma.test', 'Bridge Acik');
        $companyId = $ctx['company_id'];
        $fake = $this->fakeAdapter();
        $bridge = new PaymentBridgeService(null, fn (int $id): object => $fake);

        config()->set('integrations.dry_run', false);
        config()->set('integrations.channels.tsoft.payment_write_enabled', true);

        $payment = new CanonicalPayment(
            'odeme.alice@example.com',
            'ORD-1',
            '82.8000',
            'TRY',
            'card',
            Carbon::parse('2026-11-22 12:30:00'),
            'TRX-1 +90 532 123 45 67 sokak No:5 password=abc',
        );

        $result = $bridge->toTsoft($companyId, $payment);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $fake->noteCalls);
        $this->assertSame([], $fake->statusCalls);

        $note = (string) $fake->noteCalls[0]['note'];
        $this->assertStringContainsString('82.8000', $note);
        $this->assertStringContainsString('TRY', $note);
        $this->assertStringNotContainsString('example.com', $note);
        $this->assertStringNotContainsString('532 123', $note);
        $this->assertStringNotContainsString('sokak', $note);
        $this->assertStringNotContainsString('password', $note);
    }

    private function payment(): CanonicalPayment
    {
        return new CanonicalPayment(
            'PAY-1',
            'ORD-1',
            '10.0000',
            'TRY',
            'card',
            Carbon::parse('2026-11-22 10:00:00'),
            'TRX-1',
        );
    }

    /** @return object{noteCalls:array,statusCalls:array} */
    private function fakeAdapter(): object
    {
        return new class
        {
            /** @var array<int,array<string,mixed>> */
            public array $noteCalls = [];

            /** @var array<int,array<int,string>> */
            public array $statusCalls = [];

            public function pushPaymentNote(CanonicalPayment $payment, ?string $note = null): SyncResult
            {
                $this->noteCalls[] = ['payment' => $payment, 'note' => $note];

                return SyncResult::success('NOTE-1', 'Ödeme notu yazıldı');
            }

            public function pushStatus(string $externalRef, string $status): SyncResult
            {
                $this->statusCalls[] = [$externalRef, $status];

                return SyncResult::success($externalRef, 'Durum yazıldı');
            }
        };
    }
}
