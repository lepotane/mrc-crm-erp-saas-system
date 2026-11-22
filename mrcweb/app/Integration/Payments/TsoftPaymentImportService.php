<?php

namespace App\Integration\Payments;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Orders\IntegrationAudit;
use App\Integration\Orders\Money;
use App\Integration\Orders\OrderSyncService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tsoft'tan gelen ONAYLANMISH ödemeleri MRC tahsilatına çevirir.
 *
 * KVKK: yalnızca opak ödeme/sipariş referansı, tutar, para birimi, yöntem ve
 * tarih taşınır. Müşteri adı/e-posta/telefon/adres bu servise hiç girmez;
 * hata listesine de ham satır veya kişisel veri yazılmaz (yalnızca generic kod).
 *
 * Idempotency: OrderSyncService::markPaid() zaten (company, payment externalRef)
 * anahtarıyla integration_id_map kullanır. Burada da aynı anahtar önceden
 * kontrol edilir; böylece gereksiz transaction açılmaz.
 */
final class TsoftPaymentImportService
{
    /** Kanalın "ödeme onaylandı" değeri (diğerleri asla tahsilat yaratmaz). */
    private const APPROVED_VALUES = ['1', 'true', 'yes', 'evet'];

    public function __construct(private readonly ?OrderSyncService $orderSync = null) {}

    /**
     * Ham Tsoft satırı veya CanonicalOrder dizisi için onaylı ödemeleri içe alır.
     *
     * @param  array<int,mixed>  $orders
     * @return array{total:int,imported:int,skipped_not_approved:int,skipped_mapped:int,skipped_no_ref:int,failed:int,errors:array<int,string>}
     */
    public function import(int $companyId, array $orders): array
    {
        $summary = [
            'total' => 0,
            'imported' => 0,
            'skipped_not_approved' => 0,
            'skipped_mapped' => 0,
            'skipped_no_ref' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($orders as $order) {
            $summary['total']++;

            $raw = $this->raw($order);
            if ($raw === null) {
                $summary['failed']++;
                $summary['errors'][] = 'invalid_order_shape';

                continue;
            }

            if (! $this->approved($raw, $order)) {
                $summary['skipped_not_approved']++;

                continue;
            }

            $orderRef = $this->first($raw, [
                'order_external_ref', 'OrderId', 'OrderNumber', 'TsoftOrderNo',
                'order_ref', 'external_ref', 'externalRef',
            ]);
            if ($orderRef === '') {
                $orderRef = $order instanceof CanonicalOrder ? trim($order->externalRef) : '';
            }

            $externalRef = $this->first($raw, [
                'payment_external_ref', 'PaymentTransactionId', 'payment_transaction_id',
                'transaction_id', 'payment_ref',
            ]);
            if ($externalRef === '') {
                $externalRef = $order instanceof CanonicalOrder ? trim($order->paymentRef ?? '') : '';
            }

            // Tekrar gelen aynı ödeme anahtarı: map varsa markPaid() çağrılmaz.
            if ($externalRef !== '' && IntegrationAudit::findMap($companyId, IntegrationAudit::TYPE_PAYMENT, $externalRef) !== null) {
                $summary['skipped_mapped']++;

                continue;
            }

            if ($orderRef === '') {
                $summary['failed']++;
                $summary['errors'][] = 'missing_order_ref';

                continue;
            }

            if ($externalRef === '') {
                // İşlem kimliği gelmediyse siparişe bağlı sabit, opak anahtar.
                $externalRef = 'TSOFT-PAY-'.$orderRef;
            }
            // markPaid() referansı 60 karaktere kısaltıyor; eşleme anahtarı
            // ile kaydedilen referansın birebir aynı kalması için burada da kısalt.
            $externalRef = mb_substr($externalRef, 0, 60);

            $amountRaw = $this->first($raw, [
                'amount', 'OrderTotalPrice', 'PaymentAmount', 'grand_total', 'Total', 'Amount',
            ]);
            if ($amountRaw === '' && $order instanceof CanonicalOrder) {
                $amountRaw = $order->grandTotal;
            }

            $currency = $this->first($raw, ['currency', 'Currency', 'CurrencyCode', 'currency_code']);
            if ($currency === '' && $order instanceof CanonicalOrder) {
                $currency = $order->currency;
            }

            $method = $this->first($raw, ['method', 'PaymentMethod', 'payment_method']);
            $paidAt = $this->paidAt($raw, $order);

            try {
                $payment = new CanonicalPayment(
                    externalRef: mb_substr($externalRef, 0, 60),
                    orderRef: $orderRef,
                    amount: Money::dec($amountRaw),
                    currency: $currency !== '' ? $currency : 'TRY',
                    method: $method !== '' ? $method : 'cash',
                    paidAt: $paidAt,
                );

                $this->sync()->markPaid($companyId, $orderRef, $payment);
                $summary['imported']++;
            } catch (Throwable $e) {
                // Hata mesajı/veri asla döndürülmez; yalnızca sınıf adı.
                $summary['failed']++;
                $summary['errors'][] = get_class($e);
            }
        }

        return $summary;
    }

    /**
     * `channel_meta.payment_approved = 1` ve Tsoft sipariş eşlemesi olan belgeleri
     * import() için minimal satırlara çevirir.
     *
     * Dönüşte müşteri adı, VKN, e-posta, telefon veya adres YOKTUR; yalnızca
     * belge/sipariş/ödeme kimlikleri, tutar, para birimi, yöntem ve tarih vardır.
     *
     * @return array<int,array<string,string|int>>
     */
    public function scanDocuments(int $companyId, int $limit = 500): array
    {
        $rows = DB::table('documents as d')
            ->join('integration_id_map as iim', function (JoinClause $join) use ($companyId): void {
                $join->on('iim.mrc_id', '=', 'd.id')
                    ->where('iim.company_id', '=', $companyId)
                    ->where('iim.mrc_type', '=', IntegrationAudit::MRC_DOCUMENT)
                    ->where('iim.external_type', '=', IntegrationAudit::TYPE_ORDER);
            })
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')
            ->where('d.channel_meta', 'like', '%"payment_approved":"1"%')
            ->orderBy('d.id')
            ->limit(max(1, $limit))
            ->select([
                'd.id as document_id',
                'd.channel_meta',
                'd.grand_total',
                'd.currency_code',
                'd.issue_date',
                'iim.external_key as order_external_ref',
            ])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->channel_meta, true);
            if (! is_array($meta) || ! $this->isApprovedValue((string) ($meta['payment_approved'] ?? ''))) {
                continue;
            }

            $orderRef = trim((string) $row->order_external_ref);
            if ($orderRef === '') {
                continue;
            }

            $transaction = trim((string) ($meta['payment_transaction_id'] ?? ''));

            $result[] = [
                'payment_approved' => '1',
                'document_id' => (int) $row->document_id,
                'order_external_ref' => $orderRef,
                'payment_external_ref' => $transaction !== ''
                    ? $transaction
                    : 'TSOFT-PAY-'.(int) $row->document_id,
                'amount' => Money::dec($row->grand_total),
                'currency' => trim((string) $row->currency_code) !== ''
                    ? trim((string) $row->currency_code)
                    : 'TRY',
                'method' => trim((string) ($meta['payment_method'] ?? '')) !== ''
                    ? trim((string) $meta['payment_method'])
                    : 'cash',
                'paid_at' => substr((string) $row->issue_date, 0, 10),
            ];
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function raw(mixed $order): ?array
    {
        if (is_array($order)) {
            return $order;
        }

        if ($order instanceof CanonicalOrder) {
            return is_array($order->raw) ? $order->raw : [];
        }

        return null;
    }

    /** @param array<string,mixed> $raw */
    private function approved(array $raw, mixed $order): bool
    {
        foreach (['payment_approved', 'PaymentApproved'] as $key) {
            if (array_key_exists($key, $raw)) {
                return $this->isApprovedValue(trim((string) $raw[$key]));
            }
        }

        return $order instanceof CanonicalOrder && $order->isPaid;
    }

    private function isApprovedValue(string $value): bool
    {
        return in_array(strtolower($value), self::APPROVED_VALUES, true);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<int,string>  $keys
     */
    private function first(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = trim((string) ($raw[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $raw */
    private function paidAt(array $raw, mixed $order): ?CarbonInterface
    {
        $value = $this->first($raw, ['paid_at', 'PaymentDate', 'payment_date', 'OrderDate', 'DateAdded']);

        if ($value === '' && $order instanceof CanonicalOrder) {
            return $order->placedAt;
        }

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function sync(): OrderSyncService
    {
        return $this->orderSync ?? new OrderSyncService;
    }
}
