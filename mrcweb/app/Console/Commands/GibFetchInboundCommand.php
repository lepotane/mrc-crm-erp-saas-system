<?php

namespace App\Console\Commands;

use App\Integration\Gib\GibAdapter;
use App\Integration\Support\CredentialVault;
use App\Models\Company;
use App\Models\GibDocument;
use App\Models\GibInboundFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GibFetchInboundCommand extends Command
{
    protected $signature = 'integration:gib-fetch-inbound';

    protected $description = 'Fetch GIB integrator feedback files and mark matching documents official.';

    public function handle(): int
    {
        $companyIds = DB::table('tenant_integrations')
            ->where('channel', 'gib')
            ->pluck('company_id')
            ->merge(GibDocument::query()->distinct()->pluck('company_id'))
            ->unique()
            ->filter()
            ->values();

        $fetched = 0;
        $marked = 0;

        foreach ($companyIds as $companyId) {
            $companyId = (int) $companyId;
            $adapter = new GibAdapter($companyId, $this->vaultFor($companyId));

            foreach ($adapter->fetchInboundFiles() as $file) {
                $fetched++;

                $document = GibDocument::where('company_id', $companyId)
                    ->where('ettN', $file->ettn)
                    ->first();
                if ($document === null) {
                    continue;
                }

                // Only this inbound feedback job may promote a document to official.
                $document->update([
                    'status' => 'official',
                    'official_at' => $document->official_at ?? now(),
                ]);
                $marked++;

                GibInboundFile::where('company_id', $companyId)
                    ->where('file_path', $file->filePath)
                    ->update([
                        'status' => 'official',
                        'matched_document_id' => $document->id,
                    ]);
            }
        }

        $this->info(sprintf('GIB inbound: %d file(s) fetched, %d document(s) marked official.', $fetched, $marked));

        return self::SUCCESS;
    }

    private function vaultFor(int $companyId): CredentialVault
    {
        try {
            return new CredentialVault($companyId);
        } catch (\TypeError) {
            return new CredentialVault(Company::findOrFail($companyId));
        }
    }
}
