<?php

namespace Tests\Unit\Integration\Gib;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Enums\Channel;
use App\Integration\Gib\DocumentVoucherBuilder;
use App\Integration\Gib\VoucherBuilderRegistry;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class DocumentVoucherBuilderTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        VoucherBuilderRegistry::clear();
    }

    protected function tearDown(): void
    {
        VoucherBuilderRegistry::clear();
        parent::tearDown();
    }

    public function test_it_builds_a_balanced_draft_voucher_with_configured_account_codes(): void
    {
        $ctx = $this->register('voucher@firma.test', 'Voucher Firmasi');
        $this->configureMap($ctx['company_id'], [
            'sale' => ['debit' => '120', 'credit' => '600', 'tax' => '391'],
        ]);

        $voucher = (new DocumentVoucherBuilder)->build($this->order($ctx['company_id']));

        $this->assertTrue($voucher->isBalanced());
        $this->assertSame('draft', $voucher->state->value);

        $codes = array_map(fn ($line) => $line->accountCode, $voucher->lines);
        $this->assertContains('120', $codes);
        $this->assertContains('600', $codes);
        $this->assertContains('391', $codes);

        // 2*10 + 5*20 = 120 net, +20% tax = 24, gross = 144.
        $counter = $voucher->lines[count($voucher->lines) - 1];
        $this->assertSame('600', $counter->accountCode);
        $this->assertSame('144.0000', $counter->credit);
        $this->assertSame('0.0000', $counter->debit);
    }

    public function test_it_accepts_a_flat_mapping_shape(): void
    {
        $ctx = $this->register('voucher-flat@firma.test', 'Voucher Flat Firmasi');
        $this->configureMap($ctx['company_id'], ['debit' => '100', 'credit' => '200']);

        $voucher = (new DocumentVoucherBuilder)->build($this->order($ctx['company_id']));

        $this->assertTrue($voucher->isBalanced());
        $codes = array_map(fn ($line) => $line->accountCode, $voucher->lines);
        $this->assertContains('100', $codes);
        $this->assertContains('200', $codes);
    }

    public function test_it_throws_when_the_account_map_is_missing(): void
    {
        $ctx = $this->register('voucher-nomap@firma.test', 'Voucher No Map Firmasi');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('account_map');

        (new DocumentVoucherBuilder)->build($this->order($ctx['company_id']));
    }

    public function test_registry_wildcard_resolves_after_bootstrap(): void
    {
        DocumentVoucherBuilder::bootstrap();

        $this->assertInstanceOf(DocumentVoucherBuilder::class, VoucherBuilderRegistry::resolve('*'));
        $this->assertNull(VoucherBuilderRegistry::resolve('invoice'));
    }

    /** @param array<string,mixed> $map */
    private function configureMap(int $companyId, array $map): void
    {
        TenantIntegration::create([
            'company_id' => $companyId,
            'channel' => Channel::Gib->value,
            'driver' => 'file',
            'settings' => ['account_map' => $map],
            'status' => TenantIntegration::STATUS_ACTIVE,
        ]);
    }

    private function order(int $companyId): CanonicalOrder
    {
        return new CanonicalOrder(
            'ext-1',
            'FTR-2026-0001',
            'TRY',
            '144.0000',
            'draft',
            null,
            false,
            now(),
            '1234567890',
            'party-1',
            [
                new CanonicalOrderLine('SKU-1', 'Kalem 1', '2', '10.00', '20'),
                new CanonicalOrderLine('SKU-2', 'Kalem 2', '5', '20.00', '20'),
            ],
            [
                'company_id' => $companyId,
                'document_type' => 'sale',
                'ettn' => 'ettn-1',
                'description' => 'Test aciklamasi',
            ],
        );
    }
}
