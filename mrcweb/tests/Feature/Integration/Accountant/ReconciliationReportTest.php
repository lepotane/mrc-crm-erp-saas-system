<?php

namespace Tests\Feature\Integration\Accountant;

use App\Integration\Accountant\ReconciliationReport;
use App\Models\AccountantTransfer;
use App\Models\Document;
use App\Models\GibDocument;
use App\Models\Party;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class ReconciliationReportTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_it_reports_totals_counts_and_difference(): void
    {
        $ctx = $this->register('recon@firma.test', 'Mutabakat Firmasi');
        $companyId = $ctx['company_id'];

        $party = Party::create([
            'company_id' => $companyId,
            'type' => 'customer',
            'code' => '00001',
            'name' => 'Musteri',
        ]);

        $document = Document::create([
            'company_id' => $companyId,
            'type' => Document::TYPE_INVOICE,
            'serial' => 'FTR-26-00001',
            'party_id' => $party->id,
            'status' => 'issued',
            'issue_date' => '2026-11-10',
            'grand_total' => '120.0000',
        ]);

        GibDocument::create([
            'company_id' => $companyId,
            'document_id' => $document->id,
            'document_type' => 'invoice',
            'ettN' => '11111111-1111-4111-8111-111111111111',
            'status' => 'official_pending',
        ]);

        AccountantTransfer::create([
            'company_id' => $companyId,
            'voucher_ids' => [$document->id],
            'driver' => 'file',
            'status' => 'transferred',
            'transferred_at' => now(),
        ]);

        $report = (new ReconciliationReport)->generate(
            $companyId,
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30')
        );

        $this->assertSame(1, $report['mrc']['count']);
        $this->assertSame('120.0000', $report['mrc']['total']);
        $this->assertSame(1, $report['gib']['count']);
        $this->assertSame('120.0000', $report['gib']['total']);
        $this->assertSame(1, $report['gib']['by_status']['official_pending']['count']);
        $this->assertSame(0, $report['gib']['by_status']['official']['count']);
        $this->assertSame(1, $report['luca']['success_count']);
        $this->assertSame('0.0000', $report['difference']);
        $this->assertTrue($report['masked']);
    }
}
