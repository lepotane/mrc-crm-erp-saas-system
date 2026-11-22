<?php

namespace Tests\Feature\Integration\Accountant;

use App\Integration\Accountant\AccountMappingService;
use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Enums\Channel;
use App\Integration\Gib\DocumentVoucherBuilder;
use App\Models\LucaAccountPlan;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class AccountMappingServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_it_imports_plan_maps_codes_and_resolves_them(): void
    {
        $companyId = $this->register('account-map@firma.test', 'Hesap Eslme Firmasi')['company_id'];
        $service = new AccountMappingService;

        $summary = $service->importPlan($companyId, array_merge($this->planRows(), [
            ['code' => '', 'name' => 'Bos kod'],
        ]));

        $this->assertSame(4, $summary['total']);
        $this->assertSame(3, $summary['imported']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame([], $summary['errors']);
        $this->assertTrue(LucaAccountPlan::upToDate($companyId));

        $mapping = $service->setMapping($companyId, 'sale', 'debit', 'CODE-CUST');
        $this->assertSame('CODE-CUST', $mapping->account_code);
        $this->assertSame('manual', $mapping->source);

        $service->setMapping($companyId, 'sale', 'credit', 'CODE-SALE');
        $service->setMapping($companyId, 'sale', 'tax', 'CODE-VAT');

        $this->assertSame([
            'debit' => 'CODE-CUST',
            'credit' => 'CODE-SALE',
            'tax' => 'CODE-VAT',
        ], $service->resolve($companyId, 'sale'));
    }

    public function test_resolve_throws_for_a_missing_role(): void
    {
        $companyId = $this->register('account-map-missing@firma.test', 'Eksik Rol Firmasi')['company_id'];
        $service = new AccountMappingService;

        $service->importPlan($companyId, $this->planRows());
        $service->setMapping($companyId, 'sale', 'debit', 'CODE-CUST');
        $service->setMapping($companyId, 'sale', 'credit', 'CODE-SALE');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"tax" account for document type "sale"');

        $service->resolve($companyId, 'sale');
    }

    public function test_to_tenant_settings_is_consumed_by_the_existing_voucher_builder(): void
    {
        $companyId = $this->register('account-map-builder@firma.test', 'Fis Uyum Firmasi')['company_id'];
        $service = new AccountMappingService;

        $service->importPlan($companyId, $this->planRows());
        $service->setMapping($companyId, 'sale', 'debit', 'CODE-CUST');
        $service->setMapping($companyId, 'sale', 'credit', 'CODE-SALE');
        $service->setMapping($companyId, 'sale', 'tax', 'CODE-VAT');

        $settings = $service->toTenantSettings($companyId);

        $this->assertSame([
            'debit' => 'CODE-CUST',
            'credit' => 'CODE-SALE',
            'tax' => 'CODE-VAT',
        ], $settings['sale']);

        TenantIntegration::create([
            'company_id' => $companyId,
            'channel' => Channel::Gib->value,
            'driver' => 'file',
            'settings' => ['account_map' => $settings],
            'status' => TenantIntegration::STATUS_ACTIVE,
        ]);

        $voucher = (new DocumentVoucherBuilder)->build($this->order($companyId));
        $codes = array_map(fn ($line) => $line->accountCode, $voucher->lines);

        $this->assertContains('CODE-CUST', $codes);
        $this->assertContains('CODE-SALE', $codes);
        $this->assertContains('CODE-VAT', $codes);
        $this->assertTrue($voucher->isBalanced());
    }

    public function test_manual_code_is_allowed_without_a_plan_but_rejected_after_import(): void
    {
        $companyId = $this->register('account-map-manual@firma.test', 'Manuel Kod Firmasi')['company_id'];
        $service = new AccountMappingService;

        $manual = $service->setMapping($companyId, 'sale', 'debit', 'CODE-MANUAL');
        $this->assertSame('CODE-MANUAL', $manual->account_code);
        $this->assertFalse(LucaAccountPlan::upToDate($companyId));

        $service->importPlan($companyId, $this->planRows());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist in the imported plan');

        $service->setMapping($companyId, 'sale', 'credit', 'CODE-UNKNOWN');
    }

    public function test_suggestions_come_only_from_plan_names(): void
    {
        $companyId = $this->register('account-map-suggest@firma.test', 'Oneri Firmasi')['company_id'];
        $service = new AccountMappingService;

        $this->assertSame([], $service->suggestFromPlan($companyId, 'sale'));

        $service->importPlan($companyId, $this->planRows());

        $this->assertSame([
            'debit' => 'CODE-CUST',
            'credit' => 'CODE-SALE',
            'tax' => 'CODE-VAT',
        ], $service->suggestFromPlan($companyId, 'sale'));
    }

    /** @return array<int,array<string,mixed>> */
    private function planRows(): array
    {
        return [
            ['code' => 'CODE-CUST', 'name' => 'Müşteriler', 'parent_code' => null, 'is_active' => true],
            ['code' => 'CODE-SALE', 'name' => 'Satışlar', 'parent_code' => null, 'is_active' => true],
            ['code' => 'CODE-VAT', 'name' => 'Hesaplanan KDV', 'parent_code' => null, 'is_active' => true],
        ];
    }

    private function order(int $companyId): CanonicalOrder
    {
        return new CanonicalOrder(
            'ext-account-map',
            'FTR-2026-0001',
            'TRY',
            '24.0000',
            'draft',
            null,
            false,
            now(),
            '1234567890',
            'party-1',
            [new CanonicalOrderLine('SKU-1', 'Kalem 1', '2', '10.00', '20')],
            ['company_id' => $companyId, 'document_type' => 'sale'],
        );
    }
}
