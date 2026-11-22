<?php

namespace App\Console\Commands\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Sync\InteractsWithActiveTsoftTenants;
use App\Integration\Sync\OrderBuyerSync;
use App\Integration\Tsoft\TsoftAdapter;
use App\Jobs\Integration\ProcessTsoftOrder;
use App\Models\TenantIntegration;
use Illuminate\Console\Command;
use Throwable;

/** Tsoft siparişlerini çeker ve senkron olarak işler; rota kaydetmez. */
class TsoftPullOrdersCommand extends Command
{
    use InteractsWithActiveTsoftTenants;

    protected $signature = 'integration:tsoft-pull-orders {--company= : Yalnızca bu firma}';

    protected $description = 'Tsoft siparişlerini çeker ve islem joblarına verir';

    public function handle(): int
    {
        $companyOption = $this->option('company');
        $companyIds = $this->activeTsoftCompanyIds($companyOption !== null ? (int) $companyOption : null);
        $dispatched = 0;

        foreach ($companyIds as $companyId) {
            $integration = null;

            try {
                $integration = TenantIntegration::query()
                    ->where('company_id', $companyId)
                    ->where('channel', Channel::Tsoft->value)
                    ->where('status', TenantIntegration::STATUS_ACTIVE)
                    ->first();

                if ($integration === null) {
                    continue;
                }

                $overlap = max(0, (int) config('integrations.channels.tsoft.overlap_minutes', 5));
                $since = $integration->last_success_at?->copy()->subMinutes($overlap);

                /** @var TsoftAdapter $adapter */
                $adapter = app(TsoftAdapter::class, ['company' => $companyId]);

                // Aynı sürücü örneğinde siparişler okunurken alıcılar da toplanır.
                $orders = [];
                foreach ($adapter->fetchOrders($since) as $order) {
                    $orders[] = $order;
                }

                // Misafir cariler ingest'ten ÖNCE kurulur ki sipariş onları bulsun.
                $buyers = $adapter->fetchAllOrderBuyers();
                app(OrderBuyerSync::class)->sync($companyId, $buyers);

                // Paylaşımlı hosting'de queue worker gerekmez; iş satır içinde runs.
                foreach ($orders as $order) {
                    ProcessTsoftOrder::dispatchSync(
                        $companyId,
                        $order->externalRef,
                        $order->toArray(),
                        $order->raw,
                    );
                    $dispatched++;
                }

                $integration->update(['last_success_at' => now(), 'last_error' => null]);
            } catch (Throwable) {
                $integration?->update(['last_error' => 'Siparis cekimi basarisiz.']);
                $this->error("Firma {$companyId}: siparis cekimi basarisiz.");
            }
        }

        $this->info("Tsoft siparisleri: {$dispatched} siparis islendi (senkron).");

        return self::SUCCESS;
    }
}
