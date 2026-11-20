<?php

namespace App\Integration\Outbox;

use Illuminate\Support\Facades\Log;

/**
 * Outbox olaylarının kanal işleyicilerine dağıtımı.
 *
 * Handler kaydı statiktir: kanal adapter'ları kendi event türlerini buraya
 * kaydeder. Kayıtlı handler yoksa olay düşürülmez, yalnızca loglanır (no-op).
 */
class IntegrationDispatcher
{
    /** @var array<string, callable> */
    private static array $handlers = [];

    public static function register(string $eventType, callable $handler): void
    {
        self::$handlers[$eventType] = $handler;
    }

    public static function dispatch(OutboxMessage $m): void
    {
        $handler = self::$handlers[$m->event_type] ?? null;

        if ($handler === null) {
            Log::info('integration.outbox.unhandled', [
                'id' => $m->id,
                'event_type' => $m->event_type,
                'company_id' => $m->company_id,
            ]);

            return;
        }

        $handler($m);
    }
}
