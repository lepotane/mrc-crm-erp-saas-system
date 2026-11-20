<?php

namespace Tests\Unit\Integration;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;
use App\Integration\Gib\UblBuilder;
use Carbon\Carbon;
use DOMDocument;
use PHPUnit\Framework\TestCase;

class UblBuilderTest extends TestCase
{
    public function test_it_builds_well_formed_ubl_tr_xml(): void
    {
        $xml = (new UblBuilder)->build($this->voucher(true));

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml), 'Generated UBL XML must be well formed.');

        $this->assertStringContainsString('<cbc:UBLVersionID>2.1</cbc:UBLVersionID>', $xml);
        $this->assertStringContainsString('<cbc:CustomizationID>TR1.2</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('<cac:AccountingSupplierParty>', $xml);
        $this->assertStringContainsString('<cac:AccountingCustomerParty>', $xml);
        $this->assertStringContainsString('<cbc:TaxIdentificationID>1234567890</cbc:TaxIdentificationID>', $xml);
        $this->assertStringContainsString('<cac:InvoiceLine>', $xml);
        $this->assertStringNotContainsString('ds:Signature', $xml);
    }

    public function test_it_escapes_special_characters(): void
    {
        $xml = (new UblBuilder)->build($this->voucher(true, 'A<B & <script>x</script>'));

        $this->assertStringContainsString('A&lt;B &amp;', $xml);
        $this->assertStringNotContainsString('<script>', $xml);

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
    }

    /**
     * The builder is intentionally unaware of balance validation: balancing is
     * enforced by ResmilesTirService (a DB-backed service), not by XML building.
     */
    public function test_builder_accepts_balanced_and_unbalanced_vouchers_alike(): void
    {
        $builder = new UblBuilder;

        $this->assertSame(
            '100.00',
            $this->firstLineAmount($builder->build($this->voucher(true))),
        );
        $this->assertSame(
            '100.00',
            $this->firstLineAmount($builder->build($this->voucher(false))),
        );
    }

    private function firstLineAmount(string $xml): string
    {
        preg_match('/<cbc:LineExtensionAmount[^>]*>([^<]+)<\/cbc:LineExtensionAmount>/', $xml, $matches);

        return $matches[1] ?? '';
    }

    private function voucher(bool $balanced, string $description = 'Test description'): CanonicalVoucher
    {
        return new CanonicalVoucher(
            'ext-1',
            'FTR-2026-0001',
            Carbon::parse('2026-11-20'),
            'TRY',
            [
                new CanonicalVoucherLine('100', '100.00', $balanced ? '100.00' : '0.00', $description, '20'),
                new CanonicalVoucherLine('120', $balanced ? '0.00' : '100.00', $balanced ? '100.00' : '0.00', null, '20'),
            ],
            taxId: '1234567890',
            description: 'Müşavir A.Ş.',
            ettn: '11111111-1111-4111-8111-111111111111',
        );
    }
}
