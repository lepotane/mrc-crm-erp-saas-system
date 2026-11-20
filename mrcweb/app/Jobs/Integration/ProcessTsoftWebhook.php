<?php

namespace App\Jobs\Integration;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Orders\FulfillmentSyncService;
use App\Integration\Orders\Money;
use App\Integration\Orders\OrderSyncService;
use App\Integration\Support\CredentialVault;
use App\Integration\Tsoft\TsoftAdapter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tsoft webhook işçisi.
 *
 * Gövde controller'da TsoftMapper::mask() ile maskelenir; kişisel veri gerektiren
 * işlemler için tam sipariş adapter üzerinden yeniden çekilir. Ham gövde ASLA
 * loglanmaz.
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
        try {
            $this->process();
        } catch (Throwable $e) {
            Log::warning('tsoft.webhook.failed', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
                'topic' => $this->topic,
                'error_class' => get_class($e),
                'message' => 'Webhook işlemi başarısız; ayrıntı ham gövde olmadan bildirildi.',
            ]);

            // Yalnızca geçici hatalarda yeniden deneme; kalıcı hatalarda job
            // sessizce sonuçlanır (kuyruk şişmesi ve tekrar eden kötü veri yok).
            if ($this->isTransient($e)) {
                throw $e;
            }
        }
    }

    private function process(): void
    {
        $topic = strtolower($this->topic);

        if (str_starts_with($topic, 'product.stock_changed') || str_starts_with($topic, 'product.updated')) {
            ProcessTsoftStockWebhook::dispatch(
                $this->companyId,
                $this->eventId,
                $this->topic,
                $this->payload,
            );

            return;
        }

        if (str_starts_with($topic, 'return.') || str_starts_with($topic, 'order.return')) {
            $this->processReturn();

            return;
        }

        if (str_starts_with($topic, 'payment.')) {
            $this->processPaymentTopic();

            return;
        }

        if (str_starts_with($topic, 'order.')) {
            $this->processOrder($topic);

            return;
        }

        Log::info('tsoft.webhook.ignored', [
            'company_id' => $this->companyId,
            'event_id' => $this->eventId,
            'topic' => $this->topic,
        ]);
    }

    /** order.created / order.status_changed / order.updated */
    private function processOrder(string $topic): void
    {
        $adapter = $this->adapter();
        $externalRef = $this->externalRef();

        if ($externalRef === '') {
            Log::warning('tsoft.webhook.missing_order_ref', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        // Webhook gövdesi maskeli: alıcı adı/e-posta/adres için tam sipariş çekilir.
        $order = $adapter->fetchOrder($externalRef);

        if (! $order instanceof CanonicalOrder) {
            Log::warning('tsoft.webhook.order_not_found', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $sync = new OrderSyncService($adapter);
        $document = $sync->ingest($this->companyId, $order);

        if (in_array($topic, ['order.status_changed', 'order.updated'], true)) {
            (new FulfillmentSyncService($adapter))
                ->pushStatus($this->companyId, $order->externalRef, $document->status);
        }

        $paymentRef = $order->paymentRef
            ?? $this->payloadValue(['payment_ref', 'paymentRef', 'payment.reference']);

        if ($paymentRef !== null && $paymentRef !== '') {
            $this->savePayment($adapter, $order, $paymentRef);
        }
    }

    /** payment.* konuları: ödeme referansı, tutar ve sipariş referansı gövdede gelir. */
    private function processPaymentTopic(): void
    {
        $adapter = $this->adapter();
        $paymentRef = $this->payloadValue([
            'payment_ref', 'external_ref', 'externalRef', 'transaction_ref', 'id',
        ]);
        $orderRef = $this->payloadValue(['order_ref', 'orderRef', 'order_external_ref', 'order.external_ref']);
        $amount = $this->payloadValue(['amount', 'total', 'grand_total']);

        if ($paymentRef === null || $paymentRef === ''
            || $orderRef === null || $orderRef === ''
            || $amount === null || ! is_numeric($amount)) {
            Log::warning('tsoft.webhook.incomplete_payment', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $order = $adapter->fetchOrder($orderRef);
        if ($order instanceof CanonicalOrder) {
            (new OrderSyncService($adapter))->ingest($this->companyId, $order);
        }

        $paymentData = is_array($this->payload['payment'] ?? null)
            ? $this->payload['payment']
            : $this->payload;

        $currency = $order?->currency
            ?? $this->payloadValue(['currency'])
            ?? 'TRY';
        $method = $this->payloadValue(['method', 'payment_method', 'paymentMethod']) ?? 'cash';
        $paidAt = $this->dateOrNull($paymentData['paid_at'] ?? $paymentData['paidAt'] ?? null);
        $transactionRef = $this->payloadValue(['transaction_ref', 'transactionRef']);

        $payment = new CanonicalPayment(
            $paymentRef,
            $orderRef,
            Money::dec($amount),
            $currency,
            $method,
            $paidAt,
            $transactionRef,
        );

        (new OrderSyncService($adapter))->markPaid($this->companyId, $orderRef, $payment);
    }

    /** Sipariş gövdesinde payment_ref varsa ödeme kaydı (tutar tam siparişten gelir). */
    private function savePayment(TsoftAdapter $adapter, CanonicalOrder $order, string $paymentRef): void
    {
        $paymentData = is_array($this->payload['payment'] ?? null)
            ? $this->payload['payment']
            : $this->payload;

        $amount = $paymentData['amount'] ?? $paymentData['total'] ?? $order->grandTotal;
        if (! is_scalar($amount) || ! is_numeric((string) $amount)) {
            Log::warning('tsoft.webhook.incomplete_payment', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $payment = new CanonicalPayment(
            $paymentRef,
            $order->externalRef,
            Money::dec((string) $amount),
            $order->currency,
            (string) ($paymentData['method'] ?? $paymentData['payment_method'] ?? 'cash'),
            $this->dateOrNull($paymentData['paid_at'] ?? $paymentData['paidAt'] ?? null),
            isset($paymentData['transaction_ref']) ? (string) $paymentData['transaction_ref'] : null,
        );

        (new OrderSyncService($adapter))->markPaid($this->companyId, $order->externalRef, $payment);
    }

    private function processReturn(): void
    {
        $adapter = $this->adapter();
        $externalRef = $this->externalRef();

        if ($externalRef === '') {
            Log::warning('tsoft.webhook.missing_return_ref', [
                'company_id' => $this->companyId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $order = $adapter->fetchOrder($externalRef);
        if ($order instanceof CanonicalOrder) {
            (new FulfillmentSyncService($adapter))->handleReturn($this->companyId, $order);
        }
    }

    /** @return array<int,string> */
    private function payloadValue(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->payload;

            foreach (explode('.', $key) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function externalRef(): string
    {
        return $this->payloadValue([
            'external_ref',
            'externalRef',
            'order_ref',
            'orderRef',
            'id',
            'order.id',
            'order.orderNumber',
        ]) ?? '';
    }

    private function dateOrNull(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        try {
            $parsed = Carbon::parse((string) $value);

            return $parsed instanceof CarbonInterface ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function adapter(): TsoftAdapter
    {
        return new TsoftAdapter($this->companyId, app(CredentialVault::class));
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof QueryException) {
            return in_array((string) $e->getCode(), ['40001', '40P01', '1213', '1205'], true);
        }

        $message = strtolower($e->getMessage());

        foreach (['timeout', 'temporarily unavailable', 'service unavailable', 'deadlock'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
