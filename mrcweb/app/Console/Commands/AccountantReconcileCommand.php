<?php

namespace App\Console\Commands;

use App\Integration\Accountant\ReconciliationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccountantReconcileCommand extends Command
{
    protected $signature = 'integration:accountant-reconcile
        {--company= : Firma ID (verilmezse kaydı olan tüm firmalar)}';

    protected $description = 'MRC / GİB / Luca mutabakat raporu üretir (salt okunur, otomatik düzeltme yok).';

    public function handle(ReconciliationReport $report): int
    {
        $option = $this->option('company');
        $companyIds = $option !== null && $option !== ''
            ? [(int) $option]
            : $this->allCompanyIds();

        if ($companyIds === []) {
            $this->warn('Mutabakat için firma bulunamadı.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($companyIds as $companyId) {
            $data = $report->generate($companyId);

            $rows[] = [
                $companyId,
                $data['mrc']['total'],
                $data['gib']['total'],
                $data['difference'] ?? '-',
                $data['gib']['count'],
                $data['luca']['success_count'],
            ];

            // Local audit log only: masked totals preview, no outbound write.
            DB::table('sync_logs')->insert([
                'company_id' => $companyId,
                'channel' => 'accountant',
                'direction' => 'out',
                'entity' => 'reconciliation',
                'status' => 'reconciled',
                'preview' => json_encode([
                    'mrc_total' => $data['mrc']['total'],
                    'gib_total' => $data['gib']['total'],
                    'difference' => $data['difference'],
                    'gib_count' => $data['gib']['count'],
                    'luca_success_count' => $data['luca']['success_count'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }

        $this->table(['Firma', 'MRC', 'GİB', 'Fark', 'GİB kayıt', 'Luca aktarım'], $rows);
        $this->info('Mutabakat tamamlandı ('.count($rows).' firma). Otomatik düzeltme yapılmadı.');

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function allCompanyIds(): array
    {
        return DB::table('documents')->select('company_id')
            ->union(DB::table('gib_documents')->select('company_id'))
            ->union(DB::table('accountant_transfers')->select('company_id'))
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
