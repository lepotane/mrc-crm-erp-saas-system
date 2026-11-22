<?php

namespace App\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\SyncDirection;
use App\Integration\Support\CredentialVault;
use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Document;
use App\Models\Party;
use App\Models\Payment;
use App\Services\DocumentSettlement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tsoft ↔ MRC sipariş/ödeme giriş akışı.
 *
 * Kişisel veri (ad, e-posta, telefon, adres) kanonik DTO'da zaten taşınmaz;
 * burada da yalnızca vergi numarası ve opak müşteri referansı kullanılır.
 */
final class OrderSyncService
{
    public function __construct(
        private readonly ?TsoftAdapter $adapter = null,
        private readonly ?StatusMapper $statusMapper = null,
    ) {}

    /**
     * Dış siparişi MRC'ye alır. Aynı dış anahtar ikinci kez gelirse mevcut
     * belge değiştirilmeden döndürülür (çift kayıt yok).
     */
    public function ingest(int $companyId, CanonicalOrder $order): Document
    {
        return DB::transaction(function () use ($companyId, $order): Document {
            $map = IntegrationAudit::findMap(
                $companyId,
                IntegrationAudit::TYPE_ORDER,
                $order->externalRef,
                true,
            );

            if ($map !== null) {
                $existing = Document::query()
                    ->where('company_id', $companyId)
                    ->where('id', (int) $map->mrc_id)
                    ->first();

                if ($existing instanceof Document) {
                    return $existing;
                }
            }

            $mappedStatus = $this->statusMapper()->toMrc($companyId, $order->externalStatus);
            // sales_order durum makinesi yalnızca bu üç durumu kabul eder.
            $status = in_array($mappedStatus, ['draft', 'confirmed', 'cancelled'], true)
                ? $mappedStatus
                : 'draft';

            $issueDate = $order->placedAt?->toDateString() ?? date('Y-m-d');
            $currency = $this->currency($order->currency);

            $totals = [
                'subtotal' => '0.0000',
                'discount_total' => '0.0000',
                'tax_total' => '0.0000',
                // Toplam dış sistemin verisidir; satırlardan "icat" edilmez.
                'grand_total' => Money::dec($order->grandTotal),
            ];

            $document = Document::create([
                'company_id' => $companyId,
                'type' => Document::TYPE_SALES_ORDER,
                'serial' => Document::nextSerial(
                    $companyId,
                    Document::TYPE_SALES_ORDER,
                    (int) substr($issueDate, 0, 4),
                ),
                'party_id' => $this->resolvePartyId($companyId, $order),
                'status' => $status,
                'issue_date' => $issueDate,
                'currency_code' => $currency,
                'exchange_rate' => 1,
                'rate_date' => $issueDate,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => 'Tsoft siparişi (kanonik içe aktarım); kişisel veri taşınmaz.',
            ]);

            foreach (array_values($order->lines) as $index => $line) {
                $subtotal = Money::mul($line->quantity, $line->unitPrice);
                $discount = Money::percent($subtotal, $line->discountRate);
                $net = Money::sub($subtotal, $discount);
                $tax = Money::percent($net, $line->taxRate);
                $lineTotal = Money::add($net, $tax);

                $document->items()->create([
                    'company_id' => $companyId,
                    'sort_order' => $index,
                    'name' => $line->name !== null && $line->name !== ''
                        ? mb_substr($line->name, 0, 190)
                        : 'Tsoft satır',
                    'quantity' => Money::dec($line->quantity),
                    'unit_price' => Money::dec($line->unitPrice),
                    'price_currency_code' => $currency,
                    'discount_rate' => Money::dec($line->discountRate),
                    'tax_rate' => Money::dec($line->taxRate),
                    'line_subtotal' => $subtotal,
                    'line_discount' => $discount,
                    'line_tax' => $tax,
                    'line_total' => $lineTotal,
                ]);

                $totals['subtotal'] = Money::add($totals['subtotal'], $subtotal);
                $totals['discount_total'] = Money::add($totals['discount_total'], $discount);
                $totals['tax_total'] = Money::add($totals['tax_total'], $tax);
            }

            $document->update($totals);

            IntegrationAudit::remember(
                $companyId,
                IntegrationAudit::MRC_DOCUMENT,
                (int) $document->id,
                IntegrationAudit::TYPE_ORDER,
                $order->externalRef,
            );
            IntegrationAudit::log(
                $companyId,
                SyncDirection::In,
                'order',
                $order->externalRef,
                'created',
                ['document_id' => (int) $document->id],
            );

            return $document->refresh();
        });
    }

    /**
     * Dış ödemeyi MRC'ye alır ve belgenin tahsilat durumunu yeniden hesaplar.
     * Aynı ödeme anahtarı ikinci kez gelirse yeni kayıt oluşmaz.
     */
    public function markPaid(
        int $companyId,
        string $orderExternalRef,
        CanonicalPayment $payment,
    ): Payment {
        return DB::transaction(function () use ($companyId, $orderExternalRef, $payment): Payment {
            $map = IntegrationAudit::findMap(
                $companyId,
                IntegrationAudit::TYPE_PAYMENT,
                $payment->externalRef,
                true,
            );

            if ($map !== null) {
                $existing = Payment::query()
                    ->where('company_id', $companyId)
                    ->where('id', (int) $map->mrc_id)
                    ->first();

                if ($existing instanceof Payment) {
                    return $existing;
                }
            }

            $orderRef = $orderExternalRef !== '' ? $orderExternalRef : $payment->orderRef;
            $orderMap = IntegrationAudit::findMap($companyId, IntegrationAudit::TYPE_ORDER, $orderRef);
            $document = $orderMap === null
                ? null
                : Document::query()
                    ->where('company_id', $companyId)
                    ->where('id', (int) $orderMap->mrc_id)
                    ->first();

            if (! $document instanceof Document) {
                throw new \RuntimeException('Ödeme için eşleşen MRC sipariş belgesi bulunamadı.');
            }

            $record = Payment::create([
                'company_id' => $companyId,
                'party_id' => $document->party_id,
                'document_id' => $document->id,
                'direction' => Payment::DIRECTION_IN,
                'amount' => Money::dec($payment->amount),
                'currency_code' => $this->currency($payment->currency),
                'exchange_rate' => 1,
                'method' => $this->mapMethod($payment->method),
                'paid_at' => $payment->paidAt?->toDateString() ?? date('Y-m-d'),
                'reference' => mb_substr($payment->externalRef, 0, 60),
                'note' => 'Tsoft ödemesi (kanonik içe aktarım)',
            ]);

            IntegrationAudit::remember(
                $companyId,
                IntegrationAudit::MRC_PAYMENT,
                (int) $record->id,
                IntegrationAudit::TYPE_PAYMENT,
                $payment->externalRef,
            );

            // Settlement motoru yalnızca issued/partially_paid/paid durumlarında
            // hesaplar. İçe alınan satış siparişi taslak olduğundan, genel durum
            // makinesini gevşetmeden bu İÇ hesaplama için uygun hale getirilir.
            if (! in_array($document->status, ['issued', 'partially_paid', 'paid'], true)) {
                $document->update(['status' => 'issued']);
            }
            DocumentSettlement::recalculate($document->refresh());

            IntegrationAudit::log(
                $companyId,
                SyncDirection::In,
                'payment',
                $payment->externalRef,
                'created',
                ['document_id' => (int) $document->id, 'payment_id' => (int) $record->id],
            );

            return $record;
        });
    }

    /** MRC ödemesini Tsoft'a yazar. dry_run true ise dış sistem hiç çağrılmaz. */
    public function pushPayment(int $companyId, CanonicalPayment $payment): SyncResult
    {
        $started = microtime(true);

        if (config('integrations.dry_run') === true) {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'payment',
                $payment->externalRef,
                'skipped',
                ['reason' => 'dry_run'],
            );

            return SyncResult::skipped('dry_run: ödeme dış sisteme yazılmadı.');
        }

        try {
            $result = $this->adapter($companyId)->pushPayment($payment);

            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'payment',
                $payment->externalRef,
                $result->ok ? 'ok' : 'failed',
                $result->externalRef === null
                    ? ['message' => $result->message]
                    : ['external_ref' => $result->externalRef],
                $this->elapsedMs($started),
            );

            return $result;
        } catch (Throwable $e) {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'payment',
                $payment->externalRef,
                'failed',
                ['error_class' => get_class($e)],
                $this->elapsedMs($started),
            );

            return SyncResult::failure('Ödeme dış sisteme yazılamadı.');
        }
    }

    /**
     * Önce Tsoft müşteri eşlemesini kullanır; yoksa vergi numarasıyla cari çözer,
     * o da yoksa kişisel veri uydurmadan placeholder açar.
     * FulfillmentSyncService iade belgesi için aynı çözümleyiciyi kullanır.
     */
    public function resolvePartyId(int $companyId, CanonicalOrder $order): int
    {
        // customer/getCustomers eşlemesi, tax-id aramasından önce gelir.
        // Korumalar, eşleme modeli olmayan minimal test çiftlerini de destekler.
        if (class_exists(IntegrationIdMap::class) && method_exists(IntegrationIdMap::class, 'query')) {
            $keys = [];
            if (is_array($order->raw ?? null)) {
                foreach (['CustomerCode', 'CustomerId'] as $field) {
                    $value = trim((string) ($order->raw[$field] ?? ''));
                    if ($value !== '') {
                        $keys[] = $value;
                    }
                }
            }

            if ($keys !== []) {
                try {
                    $map = IntegrationIdMap::query()
                        ->where('company_id', $companyId)
                        ->where('mrc_type', 'party')
                        ->where('external_type', 'customer')
                        ->whereIn('external_key', $keys)
                        ->orderBy('id')
                        ->first();

                    if ($map !== null) {
                        $party = Party::query()
                            ->where('company_id', $companyId)
                            ->where('id', (int) $map->mrc_id)
                            ->first();

                        if ($party instanceof Party) {
                            return (int) $party->id;
                        }
                    }
                } catch (Throwable) {
                    // Eşleme okunamazsa vergi numarası/placeholder akışına devam.
                }
            }
        }

        $taxId = $order->buyerTaxId;

        if ($taxId !== null && trim($taxId) !== '') {
            $taxNumber = mb_substr(trim($taxId), 0, 20);

            $party = Party::query()
                ->where('company_id', $companyId)
                ->where('tax_number', $taxNumber)
                ->orderBy('id')
                ->first();

            if ($party instanceof Party) {
                return (int) $party->id;
            }

            return (int) Party::create([
                'company_id' => $companyId,
                'type' => 'customer',
                'code' => Party::nextCode($companyId),
                'name' => 'Tsoft Müşteri',
                'tax_number' => $taxNumber,
                'notes' => 'Tsoft içe aktarımı: yalnızca vergi numarası güvenilirdir; '
                    .'ad/e-posta/telefon/adres maskelenmiş olarak gelir ve kaydedilmez.',
                'is_active' => true,
            ])->id;
        }

        // Vergi numarası yoksa tüm anonim siparişler tek placeholder cariye bağlanır.
        // buyerRef (opak müşteri no) kişisel veri sayılmamakla birlikte kayda yazılmaz.
        $placeholder = Party::query()
            ->where('company_id', $companyId)
            ->where('code', 'TSOFT-ANON')
            ->first();

        if ($placeholder instanceof Party) {
            return (int) $placeholder->id;
        }

        return (int) Party::create([
            'company_id' => $companyId,
            'type' => 'customer',
            'code' => 'TSOFT-ANON',
            'name' => 'Tsoft Müşteri',
            'notes' => 'Tsoft içe aktarımı: vergi numarası/müşteri kimliği paylaşılmadı; '
                .'kişisel veri uydurulmadı.',
            'is_active' => true,
        ])->id;
    }

    /** Dış ödeme yöntemini elle girilebilir yöntemlere indirger; bilinmeyse cash. */
    private function mapMethod(string $method): string
    {
        $value = strtolower(trim($method));
        $aliases = [
            'nakit' => Payment::METHOD_CASH,
            'cash' => Payment::METHOD_CASH,
            'banka' => Payment::METHOD_BANK,
            'bank' => Payment::METHOD_BANK,
            'kart' => Payment::METHOD_CARD,
            'card' => Payment::METHOD_CARD,
            'credit_card' => Payment::METHOD_CARD,
            'havale' => Payment::METHOD_TRANSFER,
            'eft' => Payment::METHOD_TRANSFER,
            'transfer' => Payment::METHOD_TRANSFER,
        ];

        $mapped = $aliases[$value] ?? $value;

        return in_array($mapped, Payment::MANUAL_METHODS, true)
            ? $mapped
            : Payment::METHOD_CASH;
    }

    private function currency(string $currency): string
    {
        $value = strtoupper(trim($currency));

        return mb_substr($value !== '' ? $value : 'TRY', 0, 3);
    }

    private function statusMapper(): StatusMapper
    {
        return $this->statusMapper ?? new StatusMapper;
    }

    private function adapter(int $companyId): TsoftAdapter
    {
        return $this->adapter ?? new TsoftAdapter($companyId, app(CredentialVault::class));
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
