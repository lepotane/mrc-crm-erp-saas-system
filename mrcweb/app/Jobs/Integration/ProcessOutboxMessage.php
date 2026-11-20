<?php

namespace App\Jobs\Integration;

use App\Integration\Outbox\IntegrationDispatcher;
use App\Integration\Outbox\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Outbox mesajını kanala ileten hafif işçi.
 *
 * Gerçek iş IntegrationDispatcher kayıtlı handler'ında yapılır; bu sınıf yalnızca
 * mesajı bulur, sonucu durum olarak yazar. Ağır iş controller'da yapılmaz.
 */
final class ProcessOutboxMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $uuid) {}

    public function handle(): void
    {
        $message = OutboxMessage::find($this->uuid);

        if ($message === null) {
            return;
        }

        // Aynı iş iki kez kuyruğa girerse ikinci geçişte tekrar gönderim olmaz.
        if (in_array($message->status, [OutboxMessage::STATUS_PUBLISHED, OutboxMessage::STATUS_DEAD], true)) {
            return;
        }

        try {
            IntegrationDispatcher::dispatch($message);
            $message->markPublished();
        } catch (Throwable $e) {
            $message->markFailed($e->getMessage());
        }
    }
}
