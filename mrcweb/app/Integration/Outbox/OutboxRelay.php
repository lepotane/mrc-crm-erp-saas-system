<?php

namespace App\Integration\Outbox;

use App\Jobs\Integration\ProcessOutboxMessage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outbox kuyruğunu tarar ve bekleyen mesajları kuyruk işçilerine verir.
 *
 * MySQL 8 / MariaDB'de SKIP LOCKED ile çok worker güvenli çalışır; desteklemeyen
 * sürücülerde (SQLite gibi) basit sorguya düşülür.
 */
class OutboxRelay
{
    /**
     * @return array{processed:int,failed:int}
     */
    public function run(int $limit = 100): array
    {
        $rows = $this->claim(max(1, $limit));

        $processed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                ProcessOutboxMessage::dispatch($row->id);

                // Sync kuyrukta iş hemen çalışıp durumu değiştirebilir; yalnızca
                // hâlâ bekleyen satırları `relaying` olarak işaretle.
                OutboxMessage::query()
                    ->whereKey($row->id)
                    ->whereIn('status', [OutboxMessage::STATUS_NEW, OutboxMessage::STATUS_FAILED])
                    ->update(['status' => OutboxMessage::STATUS_RELAYING]);

                $processed++;
            } catch (Throwable $e) {
                $message = OutboxMessage::find($row->id);
                $message?->markFailed($e->getMessage());
                $failed++;

                Log::warning('integration.outbox.dispatch_failed', [
                    'id' => $row->id,
                    'company_id' => $row->company_id,
                ]);
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    /** Kilitli sorguyu dener; desteklenmiyorsa kilit olmadan tekrar okur. */
    private function claim(int $limit): Collection
    {
        try {
            return DB::transaction(fn () => $this->claimQuery($limit)
                ->lockForUpdate()
                ->skipLocked()
                ->get());
        } catch (Throwable $e) {
            return $this->claimQuery($limit)->get();
        }
    }

    private function claimQuery(int $limit): Builder
    {
        return DB::table('outbox_messages')
            ->where(function ($query) {
                $query->where('status', OutboxMessage::STATUS_NEW)
                    ->orWhere(function ($query) {
                        $query->where('status', OutboxMessage::STATUS_FAILED)
                            ->where('next_retry_at', '<=', now());
                    });
            })
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->limit($limit);
    }
}
