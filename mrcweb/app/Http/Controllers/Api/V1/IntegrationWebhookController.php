<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integration\Enums\Channel;
use App\Integration\Support\SignatureVerifier;
use App\Integration\Tsoft\TsoftMapper;
use App\Jobs\Integration\ProcessTsoftWebhook;
use App\Models\TenantIntegration;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tsoft webhook uç noktası (public).
 *
 * Entegrasyon satırı çözümü (basit tutulmuştur):
 *   1) X-Tsoft-Store başlığı,
 *   2) `store` sorgu parametresi,
 *   3) yoksa config('app.url') host'u.
 * Bu değer tenant_integrations.domain (veya store) ile eşleştirilir.
 *
 * controller yalnızca doğrular, tekrarı ayıklar ve job kuyruğa koyar;
 * ağır iş (sipariş/stok işleme) asla burada yapılmaz.
 */
class IntegrationWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $integration = $this->resolveIntegration($request);
        $secret = $integration === null ? null : $this->resolveSecret($integration);

        if ($integration === null || $secret === null || $secret === '') {
            return $this->invalidSignature();
        }

        if (! SignatureVerifier::verifyTsoft($rawBody, $request->header('X-Tsoft-Signature'), $secret)) {
            return $this->invalidSignature();
        }

        $eventId = trim((string) $request->header('X-Tsoft-Event-Id', ''));
        if ($eventId !== '' && WebhookDelivery::alreadyProcessed($eventId)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $decoded = json_decode($rawBody, true);
        $payload = is_array($decoded) ? TsoftMapper::mask($decoded) : [];
        $topic = $this->resolveTopic($request, $payload);

        if ($eventId !== '') {
            WebhookDelivery::recordProcessed($eventId, Channel::Tsoft->value, 'queued');
        }

        ProcessTsoftWebhook::dispatch(
            (int) $integration->getAttribute('company_id'),
            $eventId,
            $topic,
            $payload,
        );

        return response()->json(['status' => 'accepted'], 200);
    }

    private function resolveIntegration(Request $request): ?TenantIntegration
    {
        $query = TenantIntegration::query()->where('channel', Channel::Tsoft->value);

        $store = $request->header('X-Tsoft-Store') ?: $request->query('store');

        if (is_string($store) && $store !== '') {
            $query->where(function ($builder) use ($store): void {
                $builder->where('domain', $store)->orWhere('store', $store);
            });
        } else {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                return null;
            }

            $query->where('domain', $host);
        }

        return $query->first();
    }

    /** Webhook sırrı entegrasyon satırındadır (credentials şifreli array cast'i ile çözülür). */
    private function resolveSecret(TenantIntegration $integration): ?string
    {
        foreach (['webhook_secret', 'webhookSecret', 'signing_secret', 'secret'] as $key) {
            $value = $integration->getAttribute($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $credentials = $integration->getAttribute('credentials');
        if (is_array($credentials)) {
            foreach (['webhook_secret', 'webhookSecret', 'signing_secret', 'secret'] as $key) {
                $value = $credentials[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function resolveTopic(Request $request, array $payload): string
    {
        $topic = $request->header('X-Tsoft-Topic')
            ?: $request->header('X-Tsoft-Event')
            ?: ($payload['topic'] ?? $payload['event'] ?? null);

        return is_scalar($topic) && (string) $topic !== '' ? (string) $topic : 'unknown';
    }

    /** Ayrıntı sızdırmayan tek hata yanıtı. */
    private function invalidSignature(): JsonResponse
    {
        return response()->json(['message' => 'Invalid signature'], 401);
    }
}
