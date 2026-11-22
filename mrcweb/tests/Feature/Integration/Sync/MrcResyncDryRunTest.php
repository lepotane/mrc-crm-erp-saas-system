<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Sync\MrcResyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class MrcResyncDryRunTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_dry_run_returns_plan_and_deletes_nothing_without_calling_adapter(): void
    {
        $ctx = $this->register('mrc-resync-dry@firma.test', 'Mrc Resync Dry');
        $companyId = $ctx['company_id'];

        $partyId = DB::table('parties')->insertGetId([
            'company_id' => $companyId,
            'type' => 'customer',
            'code' => '00001',
            'name' => 'Test Cari',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            [
                'company_id' => $companyId,
                'name' => 'Urun 1',
                'sku' => 'RESYNC-DRY-1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $companyId,
                'name' => 'Urun 2',
                'sku' => 'RESYNC-DRY-2',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('documents')->insert([
            'company_id' => $companyId,
            'type' => 'sales_order',
            'serial' => 'SIP-DRY-00001',
            'party_id' => $partyId,
            'status' => 'draft',
            'issue_date' => date('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adapterCalls = 0;
        $backupCalls = 0;

        $service = new MrcResyncService(
            adapterFactory: function () use (&$adapterCalls) {
                $adapterCalls++;

                return new \stdClass;
            },
            backupRunner: function () use (&$backupCalls) {
                $backupCalls++;

                return 0;
            },
        );

        $report = $service->run($companyId, false, true, true);

        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['executed']);
        $this->assertSame([], $report['deleted']);
        $this->assertArrayHasKey('delete', $report['plan']);
        $this->assertSame(2, $report['plan']['delete']['products']);
        $this->assertSame(1, $report['plan']['delete']['documents']);
        $this->assertSame(1, $report['plan']['delete']['parties']);
        $this->assertArrayHasKey('import', $report['plan']);
        $this->assertSame(0, $adapterCalls, 'Dry-run sirasinda Tsoft adapteri hic cagirilmamali.');
        $this->assertSame(0, $backupCalls);

        $this->assertSame(2, DB::table('products')->where('company_id', $companyId)->count());
        $this->assertSame(1, DB::table('documents')->where('company_id', $companyId)->count());
        $this->assertSame(1, DB::table('parties')->where('company_id', $companyId)->count());
    }
}
