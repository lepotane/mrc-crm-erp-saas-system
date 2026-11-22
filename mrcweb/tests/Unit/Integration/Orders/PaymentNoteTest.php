<?php

namespace Tests\Unit\Integration\Orders;

use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Orders\PaymentBridgeService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** Saf not üreticisi: saf birim testi, veritabanı/uygulama kabuğu yok. */
class PaymentNoteTest extends TestCase
{
    public function test_tutar_korunur_ve_kisisel_veri_sirlardan_arindirilir(): void
    {
        $payment = new CanonicalPayment(
            'odeme.alice@example.com',
            'ORD-9',
            '82.8000',
            'TRY',
            'card',
            Carbon::parse('2026-11-22 12:30:00'),
            'TRX-1 +90 532 123 45 67 Atatürk sokak No:5 token=abc123',
        );

        $note = PaymentBridgeService::buildNote($payment);

        $this->assertStringContainsString('82.8000', $note);
        $this->assertStringContainsString('MRC Ödeme', $note);
        $this->assertStringNotContainsString('example.com', $note);
        $this->assertStringNotContainsString('532 123', $note);
        $this->assertStringNotContainsString('sokak', $note);
        $this->assertStringNotContainsString('token=', $note);
        $this->assertStringNotContainsString('abc123', $note);
    }

    public function test_serbest_metin_de_ayni_kurallara_indirgenir(): void
    {
        $note = PaymentBridgeService::sanitizeNote(
            "MRC Ödeme | Tutar: 82.8000 TRY | alice@example.com | 0532 123 45 67\nsecret=xyz"
        );

        $this->assertStringContainsString('82.8000', $note);
        $this->assertStringNotContainsString('example.com', $note);
        $this->assertStringNotContainsString('0532', $note);
        $this->assertStringNotContainsString('secret=', $note);
        $this->assertStringNotContainsString("\n", $note);
    }
}
