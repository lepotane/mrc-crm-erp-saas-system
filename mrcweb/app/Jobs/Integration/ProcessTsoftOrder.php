<?php

namespace App\Jobs\Integration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tsoft siparişi için hafif işçi. Şimdilik konuyu loglar; gerçek işleme
 * (sipariş/stok/fatura) ileride bu sınıfa eklenir. Controller ağır iş yapmaz.
 */
final class ProcessTsoftOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload  CanonicalOrder::toArray() çıktısı
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $externalRef,
        public readonly array $payload = [],
    ) {}

    public function handle(): void
    {
        Log::info('tsoft.order.queued', [
            'company_id' => $this->companyId,
            'external_ref' => $this->externalRef,
        ]);
    }
}
