<?php

namespace Tests\Unit\Integration;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;
use App\Integration\Enums\DocumentState;
use App\Integration\Enums\FieldSource;
use Carbon\Carbon;
use Tests\TestCase;

class CanonicalDtoTest extends TestCase
{
    public function test_para_string_decimal_olarak_korunur(): void
    {
        $line = new CanonicalOrderLine('SKU-1', 'Test', '2', '34.50000000', '20', '0', 'L1');
        $order = new CanonicalOrder(
            'EXT-1', 'SIP-1', 'TRY', '82.80000000', 'paid', 'PAY-1', true,
            Carbon::parse('2026-11-20'), null, null, [$line]
        );

        $array = $order->toArray();

        $this->assertSame('34.50000000', $array['lines'][0]['unit_price']);
        $this->assertSame('82.80000000', $array['grand_total']);
    }

    public function test_fis_dengesi_bcmath_ile_dogrulanir(): void
    {
        $balanced = new CanonicalVoucher(
            'FIS-1', '2026/001', Carbon::parse('2026-11-20'), 'TRY',
            [
                new CanonicalVoucherLine('120', '100.0000', '0.0000'),
                new CanonicalVoucherLine('600', '0.0000', '100.0000'),
            ],
            DocumentState::Draft,
            '1234567890'
        );

        $unbalanced = new CanonicalVoucher(
            'FIS-2', '2026/002', Carbon::parse('2026-11-20'), 'TRY',
            [new CanonicalVoucherLine('120', '100.0000', '0.0000')]
        );

        $this->assertTrue($balanced->isBalanced());
        $this->assertFalse($unbalanced->isBalanced());
        $this->assertSame('draft', $balanced->toArray()['state']);
    }

    public function test_stok_ve_fiyat_dtolari_alan_sahipligini_tasir(): void
    {
        $stock = new CanonicalStock('SKU-1', 'ANA', '25.0000', 7, 'mrc');
        $price = new CanonicalPrice('SKU-1', 'USD', '49.90000000', '30.00000000');
        $payment = new CanonicalPayment('PAY-1', 'EXT-1', '82.8000', 'TRY', 'card', null, 'TRX-1');

        $this->assertSame(7, $stock->version);
        $this->assertSame('49.90000000', $price->toArray()['sale_price']);
        $this->assertSame('TRX-1', $payment->toArray()['transaction_ref']);
        $this->assertSame('tsoft', FieldSource::Tsoft->value);
    }
}
