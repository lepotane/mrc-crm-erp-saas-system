<?php

namespace App\Integration\Health;

use App\Integration\Enums\Channel;
use App\Integration\Outbox\OutboxMessage;
use App\Models\TenantIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entegrasyon sağlığı için salt-okunur, firma-agnostik özet.
 *
 * Yönetici görünümüdür: sır içermez, yalnızca durum/zaman/sayı döndürür.
 */
class HealthService
{
    /**
     * @return array<string, array{channel:string,configured:bool,last_success_at:?string,last_error:?string,outbox_pending:int,failed_count:int,webhook_last_received_at:?string}>
     */
    public function snapshot(): array
    {
        $integrationReady = Schema::hasTable('tenant_integrations');
        $outboxReady = Schema::hasTable('outbox_messages');
        $failedReady = Schema::hasTable('failed_syncs');
        $webhookReady = Schema::hasTable('webhook_deliveries');

        $snapshot = [];

        foreach (Channel::cases() as $channel) {
            $value = $channel->value;

            $configured = false;
            $lastSuccessAt = null;
            $lastError = null;

            if ($integrationReady) {
                $base = TenantIntegration::query()->where('channel', $value);
                $configured = (clone $base)->exists();
                $lastSuccessAt = (clone $base)
                    ->whereNotNull('last_success_at')
                    ->max('last_success_at');
                $lastError = (clone $base)
                    ->whereNotNull('last_error')
                    ->orderByDesc('updated_at')
                    ->value('last_error');
            }

            $outboxPending = $outboxReady
                ? DB::table('outbox_messages')
                    ->whereIn('status', [
                        OutboxMessage::STATUS_NEW,
                        OutboxMessage::STATUS_RELAYING,
                        OutboxMessage::STATUS_FAILED,
                    ])
                    ->count()
                : 0;

            $failedCount = $failedReady
                ? DB::table('failed_syncs')
                    ->where('channel', $value)
                    ->whereNull('resolved_at')
                    ->count()
                : 0;

            $webhookLastReceivedAt = $webhookReady
                ? DB::table('webhook_deliveries')
                    ->where('channel', $value)
                    ->max('received_at')
                : null;

            $snapshot[$value] = [
                'channel' => $value,
                'configured' => (bool) $configured,
                'last_success_at' => $lastSuccessAt === null ? null : (string) $lastSuccessAt,
                'last_error' => $lastError === null ? null : (string) $lastError,
                'outbox_pending' => (int) $outboxPending,
                'failed_count' => (int) $failedCount,
                'webhook_last_received_at' => $webhookLastReceivedAt === null
                    ? null
                    : (string) $webhookLastReceivedAt,
            ];
        }

        return $snapshot;
    }
}
