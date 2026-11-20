<?php

namespace App\Console\Commands\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Enums\SyncDirection;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Orders\Money;
use App\Integration\Support\CredentialVault;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Document;
use App\Models\TenantIntegration;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Son 24 saatin Tsoft siparişlerini MRC kayıtlarıyla karşılaştırır.
 * Salt okunur rapordur: eksik/fazla/tutarsızlığı yazmaz, otomatik düzeltme yapmaz.
 */
class ReconcileOrdersCommand extends Command
{
    protected $signature = 'integration:reconcile-orders {--company= : Yalnızca bu firma (company_id)}';

    protected $description = 'Tsoft siparişlerini MRC belgeleriyle karşılaştırır (son 24 saat)';

    public function handle(): int
    {
        $companyOption = $this->option('company');

        $query = TenantIntegration::query()
            ->where('channel', Channel::Tsoft->value)
            ->where('status', TenantIntegration::STATUS_ACTIVE);

        if ($companyOption !== null && $companyOption !== '') {
            $query->where('company_id', (int) $companyOption);
        }

        $integrations = $query->orderBy('company_id')->get();

        if ($integrations->isEmpty()) {
            $this->warn('Karşılaştırılacak aktif Tsoft kiracısı bulunamadı.');

            return self::SUCCESS;
        }

        $since = now()->subDay();
        $hadMismatch = false;

        foreach ($integrations as $integration) {
            $companyId = (int) $integration->company_id;
            $result = $this->reconcileCompany($companyId, $since);

            $hadMismatch = $hadMismatch || $result['mismatch'];

            $this->line(sprintf(
                'Firma %d: eksik=%d, fazla=%d, tutarsız=%d, eşleşen=%d',
                $companyId,
                $result['missing'],
                $result['extra'],
                $result['mismatched'],
                $result['matched'],
            ));
        }

        return $hadMismatch ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{missing:int,extra:int,mismatched:int,matched:int,mismatch:bool} */
    private function reconcileCompany(int $companyId, CarbonInterface $since): array
    {
        $adapter = new TsoftAdapter($companyId, app(CredentialVault::class));

        try {
            $externalOrders = $adapter->fetchOrders($since, 100);
        } catch (Throwable $e) {
            // Hata sınıfı dışında ayrıntı yazılmaz (domain/credential sızıntısı olmasın).
            $this->error("Firma {$companyId}: siparişler okunamadı (".get_class($e).').');

            IntegrationAudit::log(
                $companyId,
                SyncDirection::In,
                'order_reconcile',
                null,
                'mismatch',
                ['reason' => 'fetch_failed', 'error_class' => get_class($e)],
            );

            return ['missing' => 0, 'extra' => 0, 'mismatched' => 0, 'matched' => 0, 'mismatch' => true];
        }

        $external = [];
        foreach ($externalOrders as $order) {
            $external[$order->externalRef] = [
                'currency' => strtoupper(trim($order->currency)),
                'total' => Money::dec($order->grandTotal),
            ];
        }

        $maps = DB::table('integration_id_map')
            ->where('company_id', $companyId)
            ->where('external_type', IntegrationAudit::TYPE_ORDER)
            ->get();

        $local = Document::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $maps->pluck('mrc_id')->all())
            ->get()
            ->keyBy('id');

        $byRef = [];
        foreach ($maps as $map) {
            $byRef[$map->external_key] = (int) $map->mrc_id;
        }

        $missing = [];
        foreach (array_keys($external) as $ref) {
            if (! isset($byRef[$ref]) || ! $local->has($byRef[$ref])) {
                $missing[] = $ref;
            }
        }

        $extra = [];
        $mismatched = [];
        $matched = 0;

        foreach ($byRef as $ref => $mrcId) {
            if (isset($external[$ref])) {
                $document = $local->get($mrcId);
                if ($document instanceof Document
                    && (strtoupper((string) $document->currency_code) !== $external[$ref]['currency']
                        || Money::dec($document->grand_total) !== $external[$ref]['total'])) {
                    $mismatched[] = $ref;
                } else {
                    $matched++;
                }

                continue;
            }

            // Yalnızca son 24 saatte açılmış yerel kayıtlar "fazla" sayılır;
            // geçmiş eşlemeler kapsam dışıdır.
            $document = $local->get($mrcId);
            if ($document instanceof Document
                && $document->created_at !== null
                && $document->created_at->gte($since)) {
                $extra[] = $ref;
            }
        }

        $counts = [
            'missing' => count($missing),
            'extra' => count($extra),
            'mismatched' => count($mismatched),
            'matched' => $matched,
        ];

        foreach ($missing as $ref) {
            $this->line("  eksik: {$ref}");
        }
        foreach ($extra as $ref) {
            $this->line("  fazla: {$ref}");
        }
        foreach ($mismatched as $ref) {
            $this->line("  tutarsız: {$ref}");
        }

        $isMismatch = $counts['missing'] > 0 || $counts['extra'] > 0 || $counts['mismatched'] > 0;

        IntegrationAudit::log(
            $companyId,
            SyncDirection::In,
            'order_reconcile',
            null,
            $isMismatch ? 'mismatch' : 'reconciled',
            $counts,
        );

        return $counts + ['mismatch' => $isMismatch];
    }
}
