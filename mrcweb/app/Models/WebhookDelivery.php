<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gelen webhook teslim kaydı (idempotency).
 *
 * `event_id` üzerindeki benzersizlik VERİTABANI seviyesinde (unique index)
 * sağlanır; modelde ekstra bir kural yoktur — yarış durumunda DB karar verir.
 * İşlem zamanları açıkça received_at/processed_at alanlarına yazılır;
 * created_at/updated_at Eloquent tarafından yönetilir.
 */
class WebhookDelivery extends Model
{
    protected $fillable = [
        'company_id',
        'event_id',
        'channel',
        'topic',
        'result',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /** Aynı olay daha önce işlendiyse true (tekrar job kuyruğa konmaz). */
    public static function alreadyProcessed(string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }

        return static::query()
            ->where('event_id', $eventId)
            ->whereNotNull('processed_at')
            ->exists();
    }

    /** Teslimi "işlendi" olarak kaydeder ve kaydı döner. */
    public static function recordProcessed(string $eventId, string $channel, string $result): self
    {
        $now = now();

        return static::query()->create([
            'event_id' => $eventId,
            'channel' => $channel,
            'result' => $result,
            'received_at' => $now,
            'processed_at' => $now,
        ]);
    }
}
