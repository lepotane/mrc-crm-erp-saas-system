<?php

namespace App\Jobs\Integration;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Orders\OrderSyncService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tsoft siparişini kanonik DTO'ya geri kurup MRC'ye işler (senkron).
 *
 * Controller/job ağır iş yapmaz; gerçek yazma OrderSyncService::ingest'te ve
 * idempotenttir. Kişisel veri payload'a/raw'a yazılmaz, hata günlüğüne de
 * yalnızca genel bilgi düşülür.
 */
final class ProcessTsoftOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $payload  CanonicalOrder::toArray() çıktısı
     * @param  array<string,mixed>  $raw  DTO'nun maskelenmiş ham kanal verisi
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $externalRef,
        public readonly array $payload = [],
        public readonly array $raw = [],
    ) {}

    public function handle(): void
    {
        try {
            $order = $this->rebuildOrder();

            app(OrderSyncService::class)->ingest($this->companyId, $order);
        } catch (Throwable) {
            // Satır içeriği, kişisel veri veya payload asla loglanmaz.
            Log::error('tsoft.order.ingest_failed', [
                'company_id' => $this->companyId,
            ]);
        }
    }

    /** Payload + raw'dan kanonik siparişi yeniden kurar. */
    private function rebuildOrder(): CanonicalOrder
    {
        $lines = [];
        $payloadLines = is_array($this->payload['lines'] ?? null)
            ? $this->payload['lines']
            : [];
        foreach ($payloadLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = new CanonicalOrderLine(
                $line['sku'] ?? null,
                $line['name'] ?? null,
                (string) ($line['quantity'] ?? '0'),
                (string) ($line['unit_price'] ?? '0'),
                (string) ($line['tax_rate'] ?? '0'),
                (string) ($line['discount_rate'] ?? '0'),
                $line['external_ref'] ?? null,
                (string) ($line['cost'] ?? '0'),
            );
        }

        return new CanonicalOrder(
            (string) ($this->payload['external_ref'] ?? $this->externalRef),
            $this->payload['order_number'] ?? null,
            (string) ($this->payload['currency'] ?? 'TRY'),
            (string) ($this->payload['grand_total'] ?? '0'),
            (string) ($this->payload['external_status'] ?? ''),
            $this->payload['payment_ref'] ?? null,
            (bool) ($this->payload['is_paid'] ?? false),
            $this->parsePlacedAt($this->payload['placed_at'] ?? null),
            $this->payload['buyer_tax_id'] ?? null,
            $this->payload['buyer_ref'] ?? null,
            $lines,
            $this->raw,
        );
    }

    private function parsePlacedAt(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);

            return $parsed instanceof CarbonInterface ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }
}
