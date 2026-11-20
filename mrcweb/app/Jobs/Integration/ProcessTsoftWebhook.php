<?php

namespace App\Jobs\Integration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tsoft webhook'u için hafif işçi.
 *
 * Şimdilik yalnızca konuyu loglar; gerçek işleme (sipariş/stok senkronu)
 * ileride bu sınıfın içine eklenir. Controller asla ağır iş yapmaz.
 */
final class ProcessTsoftWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload  TsoftMapper::mask() ile maskelenmiş gövde
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $eventId,
        public readonly string $topic,
        public readonly array $payload = [],
    ) {}

    public function handle(): void
    {
        Log::info('tsoft.webhook.queued', [
            'company_id' => $this->companyId,
            'event_id' => $this->eventId,
            'topic' => $this->topic,
        ]);
    }
}
