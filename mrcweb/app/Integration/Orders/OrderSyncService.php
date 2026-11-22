<?php

namespace App\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\Channel;
use App\Integration\Enums\SyncDirection;
use App\Integration\Support\CredentialVault;
use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Document;
use App\Models\Party;
use App\Models\Payment;
use App\Services\DocumentSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tsoft ↔ MRC sipariş/ödeme giriş akışı.
 *
 * Kişisel veri (ad, e-posta, telefon, adres) kanonik DTO'da zaten taşınmaz;
 * burada da yalnızca vergi numarası ve opak müşteri referansı kullanılır.
 */
final class OrderSyncService
{
    /**
     * Kiracı eşlemesi (integration_status_map) yoksa kullanılan bilinen Tsoft
     * rest1 durum kimlikleri. Bunlar kanalın durum kodudur, gizli bilgi değildir.
     * Yalnızca sales_order geçişlerine izin verilen değerlere çevrilir.
     */
    private const TSOFT_STATUS_FALLBACK = [
        '6' => 'confirmed',  // Kargoya Verildi
        '7' => 'confirmed',  // Ödeme Yapıldı
        '8' => 'confirmed',  // Teslim Edildi
        '12' => 'confirmed', // Onaylandı
        '13' => 'confirmed', // Kapıda Ödeme Onaylandı
        '9' => 'cancelled',  // İptal Edildi
        '10' => 'cancelled', // İade Edildi
        '11' => 'cancelled', // Kısmi İade Edildi
    ];

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

            $status = $this->resolveStatus($companyId, $order);

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

            // Kanal metadata'sı: yalnızca beyaz listedeki opak teknik alanlar.
            // notes alanı (KVKK notu) bilinçli olarak değiştirilmez.
            $document->forceFill([
                'external_no' => $this->externalNo($order),
                'channel_meta' => $this->channelMeta($order),
            ])->save();

            $lines = array_values($order->lines);

            // Tsoft rest1 order/get satır (ürün) dizisi döndürmez. Kalem gelmediği
            // halde toplam > 0 ise belge toplamının kaybolmaması için tek bir
            // "toplam" satırı oluşturulur; uydurma ürün/SKU bilgisi yazılmaz.
            if ($lines === [] && bccomp(Money::dec($order->grandTotal), '0', Money::SCALE) > 0) {
                $lines = [new CanonicalOrderLine(
                    null,
                    'Tsoft sipariş toplamı',
                    '1',
                    Money::dec($order->grandTotal),
                    '0',
                    '0',
                )];
            }

            foreach ($lines as $index => $line) {
                $subtotal = Money::mul($line->quantity, $line->unitPrice);
                $discount = Money::percent($subtotal, $line->discountRate);
                $net = Money::sub($subtotal, $discount);
                $tax = Money::percent($net, $line->taxRate);
                $lineTotal = Money::add($net, $tax);

                $itemAttributes = [
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
                ];

                // Maliyet (COGS) yeni şemalarda satır bazında tutulur. Eski
                // veritabanlarında `cogs` kolonu olmayabilir; genel bir uyarı
                // loglayıp satırı kolonsuz oluşturarak aktarımı sürdürürüz.
                // Satır içeriği/kişisel veri loga ASLA yazılmaz.
                try {
                    $document->items()->create($itemAttributes + [
                        'cogs' => Money::mul($line->quantity, $line->cost),
                    ]);
                } catch (Throwable) {
                    Log::warning('mrc.order.item_cogs_unavailable', [
                        'company_id' => $companyId,
                        'document_id' => (int) $document->id,
                    ]);
                    $document->items()->create($itemAttributes);
                }

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

    /**
     * Kiracı eşlemesi varsa StatusMapper sonucu (hard sınırla birlikte), yoksa
     * bilinen Tsoft durum kimlikleri için yedek eşleme kullanılır.
     */
    private function resolveStatus(int $companyId, CanonicalOrder $order): string
    {
        $statusId = $this->statusId($order);
        $mapped = $this->statusMapper()->toMrc($companyId, $statusId);

        if ($this->hasStatusMap($companyId, $statusId)) {
            // sales_order durum makinesi yalnızca bu üç durumu kabul eder.
            return in_array($mapped, ['draft', 'confirmed', 'cancelled'], true)
                ? $mapped
                : 'draft';
        }

        return self::TSOFT_STATUS_FALLBACK[$statusId] ?? 'draft';
    }

    /** rest1'de yetkili kaynak OrderStatusId; yoksa kanonik durum alanı. */
    private function statusId(CanonicalOrder $order): string
    {
        $rawId = is_array($order->raw ?? null)
            ? trim((string) ($order->raw['OrderStatusId'] ?? ''))
            : '';

        return $rawId !== '' ? $rawId : trim((string) $order->externalStatus);
    }

    private function hasStatusMap(int $companyId, string $statusId): bool
    {
        if ($statusId === '') {
            return false;
        }

        return StatusMap::query()
            ->where('company_id', $companyId)
            ->where('channel', Channel::Tsoft->value)
            ->where('external_status', $statusId)
            ->exists();
    }

    private function statusMapper(): StatusMapper
    {
        return $this->statusMapper ?? new StatusMapper;
    }

    private function adapter(int $companyId): TsoftAdapter
    {
        return $this->adapter ?? new TsoftAdapter($companyId, app(CredentialVault::class));
    }

    /**
     * Kanalın kendi sipariş numarası (ör. Tsoft `AK221114870`). Doküman serisi
     * MRC'nin kendi numarasıdır; bu alan yalnızca dış sisteme geri bakış içindir.
     */
    private function externalNo(CanonicalOrder $order): ?string
    {
        $value = trim((string) ($order->orderNumber ?? ''));

        if ($value === '' && is_array($order->raw ?? null)) {
            $value = trim((string) ($order->raw['TsoftOrderNo'] ?? ''));
        }

        return $value !== '' ? mb_substr($value, 0, 120) : null;
    }

    /**
     * `raw` içindeki GÜVENLİ alanlardan compact bir JSON üretir.
     *
     * Beyaz liste dışındaki hiçbir anahtar kopyalanmaz; boş değerler atlanır.
     * Modelde `channel_meta` cast'i olmadığı için JSON burada kodlanır ve API
     * katmanında (DocumentController) geri çözülür.
     */
    private function channelMeta(CanonicalOrder $order): ?string
    {
        if (! is_array($order->raw ?? null)) {
            return null;
        }

        $map = [
            'TsoftOrderNo' => 'tsoft_order_no',
            'PaymentMethod' => 'payment_method',
            'PaymentSubMethod' => 'payment_sub_method',
            'PaymentBank' => 'payment_bank',
            'PaymentCardBrand' => 'payment_card_brand',
            'PaymentVendor' => 'payment_vendor',
            'PaymentTransactionId' => 'payment_transaction_id',
            'Installment' => 'installment',
            'CargoCompany' => 'cargo_company',
            'TrackingNumber' => 'tracking_number',
            'InvoiceNo' => 'invoice_no',
            'Platform' => 'platform',
            'DiscountTotal' => 'discount_total',
            'ShippingTotal' => 'shipping_total',
            'TaxTotal' => 'tax_total',
            'SubTotal' => 'subtotal',
            'ProductsCount' => 'products_count',
            'PaymentApproved' => 'payment_approved',
        ];

        $meta = [];
        foreach ($map as $source => $target) {
            $value = trim((string) ($order->raw[$source] ?? ''));
            if ($value !== '') {
                $meta[$target] = $value;
            }
        }

        if ($meta === []) {
            return null;
        }

        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
