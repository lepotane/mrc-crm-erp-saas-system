<?php

namespace App\Console\Commands\Integration;

use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Enums\SyncDirection;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Orders\Money;
use App\Integration\Orders\PaymentBridgeService;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\JoinClause;
use Throwable;

/**
 * Son N saatte değişen ve Tsoft siparişine eşlenmiş MRC tahsilatlarını
 * doğrulanmış `order2/setOrderNote` yoluyla dış sisteme not olarak yazar.
 *
 * 4xx yanıtları için otomatik yeniden deneme YOKTUR (driver retry koşulu
 * yalnızca 429 ve 5xx ile sınırlıdır). dry_run köprü tarafında uygulanır.
 */
class TsoftPushPaymentCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-push-payment {--company= : Yalnızca bu firma} {--payment= : Yalnızca bu ödeme id}';

    protected $description = 'MRC tahsilatlarini Tsoft siparis notu olarak yazar';

    public function handle(PaymentBridgeService $bridge): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);

        if ($companyIds === []) {
            $this->info('Tsoft ödeme: aktif firma yok.');

            return self::SUCCESS;
        }

        $hours = max(1, (int) config('integrations.channels.tsoft.payment_push_hours', 24));
        $since = now()->subHours($hours);
        $paymentId = $this->option('payment');
        $scanned = 0;
        $pushed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($companyIds as $companyId) {
            $rows = Payment::query()
                ->join('integration_id_map as iim', function (JoinClause $join) use ($companyId): void {
                    $join->on('iim.mrc_id', '=', 'payments.document_id')
                        ->where('iim.company_id', '=', $companyId)
                        ->where('iim.mrc_type', '=', IntegrationAudit::MRC_DOCUMENT)
                        ->where('iim.external_type', '=', IntegrationAudit::TYPE_ORDER);
                })
                ->where('payments.company_id', $companyId)
                ->whereNotNull('payments.document_id')
                ->where(function ($query) use ($since): void {
                    $query->where('payments.created_at', '>=', $since)
                        ->orWhere('payments.updated_at', '>=', $since);
                })
                ->when(
                    $paymentId !== null && $paymentId !== '',
                    fn ($query) => $query->where('payments.id', (int) $paymentId),
                )
                ->orderBy('payments.id')
                ->select(['payments.*', 'iim.external_key as order_external_ref'])
                ->get();

            foreach ($rows as $row) {
                $scanned++;

                try {
                    $payment = new CanonicalPayment(
                        externalRef: (string) ($row->reference !== null && $row->reference !== ''
                            ? $row->reference
                            : 'PAY-'.$row->id),
                        orderRef: (string) $row->order_external_ref,
                        amount: Money::dec($row->amount),
                        currency: (string) ($row->currency_code !== null && $row->currency_code !== ''
                            ? $row->currency_code
                            : 'TRY'),
                        method: (string) ($row->method !== null && $row->method !== '' ? $row->method : 'cash'),
                        paidAt: $row->paid_at === null ? null : Carbon::parse((string) $row->paid_at),
                    );
                } catch (Throwable) {
                    $failed++;

                    continue;
                }

                $result = $bridge->toTsoft($companyId, $payment);

                if ($result->ok && ! $result->skipped) {
                    $pushed++;
                } elseif ($result->skipped) {
                    $skipped++;
                } else {
                    $failed++;
                }
            }
        }

        IntegrationAudit::log(
            $companyIds[0],
            SyncDirection::Out,
            'payment',
            $paymentId !== null && $paymentId !== '' ? (string) $paymentId : null,
            $failed > 0 ? 'failed' : 'ok',
            [
                'scanned' => $scanned,
                'pushed' => $pushed,
                'skipped' => $skipped,
                'failed' => $failed,
                'hours' => $hours,
            ],
        );

        $this->info("Tsoft ödeme: {$scanned} tarandı, {$pushed} yazıldı, {$skipped} atlandı, {$failed} hata.");

        return self::SUCCESS;
    }
}
