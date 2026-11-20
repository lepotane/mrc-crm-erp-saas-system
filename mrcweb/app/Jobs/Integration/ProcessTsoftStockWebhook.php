<?php

namespace App\Jobs\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Sync\ParallelStockEngine;
use App\Integration\Tsoft\TsoftMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Throwable;

/**
 * Tsoft stok webhook'u: kanonik stok DTO'sunu ParallelStockEngine'e verir.
 *
 * ParallelStockEngine henüz yoksa (veya imzası değişirse) job sessizce atlanır;
 * webhook kuyruğu bu yüzden kırılmaz.
 */
final class ProcessTsoftStockWebhook implements ShouldQueue
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
        try {
            if (! class_exists(ParallelStockEngine::class)) {
                Log::info('tsoft.stock.engine_missing', [
                    'company_id' => $this->companyId,
                    'event_id' => $this->eventId,
                ]);

                return;
            }

            $engine = app(ParallelStockEngine::class);

            if (! method_exists($engine, 'applyInbound')) {
                Log::warning('tsoft.stock.method_missing', [
                    'company_id' => $this->companyId,
                    'event_id' => $this->eventId,
                ]);

                return;
            }

            $method = new ReflectionMethod($engine, 'applyInbound');
            if (! $method->isPublic()) {
                Log::warning('tsoft.stock.method_unavailable', [
                    'company_id' => $this->companyId,
                    'event_id' => $this->eventId,
                ]);

                return;
            }

            $method->invokeArgs(
                $method->isStatic() ? null : $engine,
                $this->argumentsFor($method),
            );
        } catch (Throwable $e) {
            Log::warning('tsoft.stock.failed', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
                'topic' => $this->topic,
                'error_class' => get_class($e),
            ]);
        }
    }

    /** @return array<int,mixed> */
    private function argumentsFor(ReflectionMethod $method): array
    {
        $raw = $this->payload['product'] ?? $this->payload['data'] ?? $this->payload;
        $raw = is_array($raw) ? $raw : $this->payload;
        $stock = TsoftMapper::stock($raw);

        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = strtolower($parameter->getName());

            $arguments[] = match (true) {
                str_contains($name, 'company') => $this->companyId,
                str_contains($name, 'stock'), str_contains($name, 'canonical') => $stock,
                str_contains($name, 'sku') => $stock->sku,
                str_contains($name, 'channel') => Channel::Tsoft->value,
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                default => null,
            };
        }

        return $arguments;
    }
}
