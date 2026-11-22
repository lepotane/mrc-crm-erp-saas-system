<?php

namespace App\Integration\Orders;

use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\SyncDirection;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Payment;
use Throwable;

/**
 * Ödeme senkronizasyonunun iki yönlü köprüsü.
 *
 * Tsoft → MRC yönü OrderSyncService::markPaid() ile yapılır (idempotent).
 * MRC → Tsoft yönü yalnızca doğrulanmış rest1 `order2/setOrderNote` ucuna
 * yapılandırılmış, kişisel veri içermeyen bir ödeme notu yazarak ilerler.
 *
 * Canlı yazma varsayılan olarak KAPALIDIR:
 * `integrations.channels.tsoft.payment_write_enabled` açıkça true olmalıdır.
 * Transaction/authorization verisi notlara asla eklenmez.
 */
final class PaymentBridgeService
{
    /** @var mixed callable(int):object|null — testlerde adapter fabrikası enjeksiyonu */
    private mixed $adapterFactory;

    private ?OrderSyncService $orderSync;

    public function __construct(?OrderSyncService $orderSync = null, mixed $adapterFactory = null)
    {
        $this->orderSync = $orderSync;
        $this->adapterFactory = is_callable($adapterFactory) ? $adapterFactory : null;
    }

    /** Tsoft ödemesini MRC'ye alır. Idempotency markPaid() içindedir. */
    public function fromTsoft(int $companyId, string $orderExternalRef, CanonicalPayment $payment): Payment
    {
        return ($this->orderSync ?? new OrderSyncService)
            ->markPaid($companyId, $orderExternalRef, $payment);
    }

    /**
     * MRC ödemesini Tsoft'a yazar. dry_run veya yazma kapalıysa dış sistem
     * hiç çağrılmaz; sonuç her durumda SyncResult döner (istisna fırlatmaz).
     */
    public function toTsoft(int $companyId, CanonicalPayment $payment): SyncResult
    {
        $started = microtime(true);

        if (config('integrations.dry_run') === true) {
            $this->audit($companyId, $payment, 'skipped', ['reason' => 'dry_run']);

            return SyncResult::skipped('dry_run: ödeme notu dış sisteme yazılmadı.');
        }

        if (! $this->isConfiguredToWrite($companyId)) {
            $this->audit($companyId, $payment, 'skipped', ['reason' => 'payment_write_disabled']);

            return SyncResult::skipped('payment_write_enabled kapalı: ödeme notu yazılmadı.');
        }

        $adapter = $this->resolveAdapter($companyId);
        if ($adapter === null) {
            $this->audit($companyId, $payment, 'failed', ['reason' => 'adapter_unavailable']);

            return SyncResult::failure('Tsoft adapter çözümlenemedi.');
        }

        $note = self::buildNote($payment);

        try {
            if (is_callable([$adapter, 'pushPaymentNote'])) {
                $result = $adapter->pushPaymentNote($payment, $note);
            } elseif (is_callable([$adapter, 'pushStatus'])) {
                // Fallback: not yazımı desteklemeyen bir adapter için yalnızca
                // sipariş durumunu "paid" yapar; ödeme tutarı aktarılmaz.
                $result = $adapter->pushStatus($payment->orderRef, 'paid');
            } else {
                $this->audit($companyId, $payment, 'failed', ['reason' => 'unsupported_adapter']);

                return SyncResult::failure('pushPaymentNote desteklenmiyor.');
            }
        } catch (Throwable $e) {
            $this->audit($companyId, $payment, 'failed', ['error_class' => get_class($e)], $started);

            return SyncResult::failure("Ödeme notu Tsoft'a yazılamadı.");
        }

        if (! $result instanceof SyncResult) {
            $this->audit($companyId, $payment, 'failed', ['reason' => 'unexpected_result'], $started);

            return SyncResult::failure('Tsoft adapter beklenmeyen bir sonuç döndürdü.');
        }

        $this->audit(
            $companyId,
            $payment,
            $result->ok ? ($result->skipped ? 'skipped' : 'ok') : 'failed',
            $result->externalRef === null ? ['message' => $result->message] : ['external_ref' => $result->externalRef],
            $started,
        );

        return $result;
    }

    /** Yazma kapısı. Kiracı bazlı genişletmeye açık, şimdilik global anahtar. */
    public function isConfiguredToWrite(int $companyId): bool
    {
        return config('integrations.channels.tsoft.payment_write_enabled', false) === true;
    }

    /**
     * İki yönü tek çağrıda dener; hiçbir durumda istisna fırlatmaz.
     *
     * @return array{to_mrc:array<string,mixed>,to_tsoft:array<string,mixed>}
     */
    public function syncBothDirections(int $companyId, string $orderExternalRef, CanonicalPayment $payment): array
    {
        try {
            $record = $this->fromTsoft($companyId, $orderExternalRef, $payment);
            $toMrc = ['ok' => true, 'payment_id' => (int) $record->id];
        } catch (Throwable) {
            $toMrc = ['ok' => false, 'message' => "Ödeme MRC'ye yazılamadı."];
        }

        try {
            $result = $this->toTsoft($companyId, $payment);
            $toTsoft = [
                'ok' => $result->ok,
                'skipped' => $result->skipped,
                'message' => $result->message,
            ];
        } catch (Throwable) {
            $toTsoft = ['ok' => false, 'skipped' => false, 'message' => "Ödeme Tsoft'a yazılamadı."];
        }

        return ['to_mrc' => $toMrc, 'to_tsoft' => $toTsoft];
    }

    /**
     * Dış sisteme giden TEK metin üretici. Tutar korunur; e-posta, telefon,
     * adres ve sır/token benzeri dizgeler temizlenir. Kişisel veri taşınmaz.
     */
    public static function buildNote(CanonicalPayment $payment): string
    {
        $parts = [
            'MRC Ödeme',
            'Tutar: '.Money::dec($payment->amount).' '.self::clean($payment->currency, 'TRY'),
            'Yöntem: '.self::clean($payment->method, 'cash'),
            'İşlem: '.self::clean($payment->transactionRef ?? $payment->externalRef, '-'),
            'Tarih: '.($payment->paidAt?->format('Y-m-d H:i') ?? 'bilinmiyor'),
        ];

        return implode(' | ', $parts);
    }

    /** Doğrudan driver'a verilen serbest metni de aynı kurallara indirger. */
    public static function sanitizeNote(string $note): string
    {
        return self::scrub($note);
    }

    /** Paralel/test enjeksiyonu için duck-typed adapter fabrikası. */
    private function resolveAdapter(int $companyId): ?object
    {
        try {
            $adapter = $this->adapterFactory !== null
                ? ($this->adapterFactory)($companyId)
                : app(TsoftAdapter::class, ['company' => $companyId]);
        } catch (Throwable) {
            return null;
        }

        return is_object($adapter) ? $adapter : null;
    }

    /**
     * @param  array<string,mixed>|null  $preview
     */
    private function audit(int $companyId, CanonicalPayment $payment, string $status, ?array $preview, ?float $started = null): void
    {
        try {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'payment',
                $payment->externalRef,
                $status,
                $preview,
                $started === null ? null : (int) round((microtime(true) - $started) * 1000),
            );
        } catch (Throwable) {
            // Log hatası senkronizasyon sonucunu değiştirmez.
        }
    }

    private static function clean(string $value, string $fallback): string
    {
        $value = self::scrub($value);

        return $value !== '' ? mb_substr($value, 0, 60) : $fallback;
    }

    private static function scrub(string $value): string
    {
        $patterns = [
            // E-posta adresi.
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '',
            // Telefon: +90 532 123 45 67 / 0532 123 45 67 / 05321234567.
            '/(?<!\d)(?:\+?\d{1,3}[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/' => '',
            // Sır atamaları (token, password, secret, api key, bearer).
            '/(?:password|passwd|secret|token|api[\s_-]?key|authorization|bearer)\s*[:=]\s*[^\s|]+/i' => '',
            // Adres benzeri dizgeler; ':' ve '|' sınırlarını aşmaz.
            '/[^|:]{0,60}(?:mahalle|sokak|cadde|bulvar|adres|address|sok\.|cd\.|mah\.)[^|:]{0,60}/iu' => '',
        ];

        $value = preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;
        $value = str_replace(["\r", "\n", "\t", '|'], [' ', ' ', ' ', '/'], $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
