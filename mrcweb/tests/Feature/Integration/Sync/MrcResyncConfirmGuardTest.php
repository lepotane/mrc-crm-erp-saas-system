<?php

namespace Tests\Feature\Integration\Sync;

use App\Integration\Sync\MrcResyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class MrcResyncConfirmGuardTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_confirm_with_dry_run_does_not_delete_and_does_not_backup(): void
    {
        $ctx = $this->register('mrc-resync-guard1@firma.test', 'Mrc Resync Guard 1');
        $companyId = $ctx['company_id'];

        $this->seedProduct($companyId, 'RESYNC-GUARD-1');

        $backupCalls = 0;
        $adapterCalls = 0;
        $service = $this->service($backupCalls, $adapterCalls);

        $report = $service->run($companyId, true, true, true);

        $this->assertSame([], $report['deleted']);
        $this->assertFalse($report['executed']);
        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['confirmed']);
        $this->assertSame(0, $backupCalls, 'Dry-run sirasinda yedek komutu cagirilmamali.');
        $this->assertSame(0, $adapterCalls);
        $this->assertSame(1, DB::table('products')->where('company_id', $companyId)->count());
    }

    public function test_confirm_false_does_not_delete_even_when_dry_run_flag_is_false(): void
    {
        $ctx = $this->register('mrc-resync-guard2@firma.test', 'Mrc Resync Guard 2');
        $companyId = $ctx['company_id'];

        $this->seedProduct($companyId, 'RESYNC-GUARD-2');

        $backupCalls = 0;
        $adapterCalls = 0;
        $service = $this->service($backupCalls, $adapterCalls);

        $report = $service->run($companyId, false, false, true);

        $this->assertSame([], $report['deleted']);
        $this->assertFalse($report['executed']);
        $this->assertTrue($report['dry_run'], 'Onay yoksa etkili mod dry-run olmalidir.');
        $this->assertSame(0, $backupCalls, 'skip-backup verildiginde yedek komutu cagirilmamali.');
        $this->assertSame(0, $adapterCalls);
        $this->assertSame(1, DB::table('products')->where('company_id', $companyId)->count());
    }

    private function seedProduct(int $companyId, string $sku): void
    {
        DB::table('products')->insert([
            'company_id' => $companyId,
            'name' => 'Guard Urunu',
            'sku' => $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function service(int &$backupCalls, int &$adapterCalls): MrcResyncService
    {
        return new MrcResyncService(
            adapterFactory: function () use (&$adapterCalls) {
                $adapterCalls++;

                return new \stdClass;
            },
            backupRunner: function () use (&$backupCalls) {
                $backupCalls++;

                return 0;
            },
        );
    }
}
