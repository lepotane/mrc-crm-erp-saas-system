<?php

namespace App\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\SyncResult;
use App\Integration\Enums\SyncDirection;
use App\Integration\Support\CredentialVault;
use App\Integration\Tsoft\TsoftAdapter;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hazırlık / kargo / iade durumlarının Tsoft tarafına yazılması.
 *
 * Durum kodları kiracının integration_status_map tablosundan gelir; bilinmeyen
 * kod tahmin edilmez, dış yazım atlanır.
 */
final class FulfillmentSyncService
{
    public function __construct(
        private readonly ?TsoftAdapter $adapter = null,
        private readonly ?StatusMapper $statusMapper = null,
        private readonly ?OrderSyncService $orderSync = null,
    ) {}

    /** MRC durumunu kiracı eşlemesinden çevirip Tsoft'a yazar. */
    public function pushStatus(
        int $companyId,
        string $orderExternalRef,
        string $mrcStatus,
    ): SyncResult {
        $started = microtime(true);
        $externalStatus = $this->statusMapper()->toExternal($companyId, $mrcStatus);

        if ($externalStatus === null) {
            // StatusMapper zaten 'warning' kaydı yazdı; burada yazımı atladığımızı ekleriz.
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'order_status',
                $orderExternalRef,
                'skipped',
                ['reason' => 'unmapped_status'],
                $this->elapsedMs($started),
            );

            return SyncResult::skipped('Durum eşlemesi bulunamadı; dış sistem güncellenmedi.');
        }

        if (config('integrations.dry_run') === true) {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'order_status',
                $orderExternalRef,
                'skipped',
                ['reason' => 'dry_run', 'external_status' => $externalStatus],
                $this->elapsedMs($started),
            );

            return SyncResult::skipped('dry_run: durum dış sisteme yazılmadı.');
        }

        try {
            $result = $this->adapter($companyId)->pushStatus($orderExternalRef, $externalStatus);

            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'order_status',
                $orderExternalRef,
                $result->ok ? 'ok' : 'failed',
                ['external_status' => $externalStatus],
                $this->elapsedMs($started),
            );

            return $result;
        } catch (Throwable $e) {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'order_status',
                $orderExternalRef,
                'failed',
                ['error_class' => get_class($e)],
                $this->elapsedMs($started),
            );

            return SyncResult::failure('Durum dış sisteme yazılamadı.');
        }
    }

    /**
     * Kargo bilgisini Tsoft'a yazar.
     *
     * Tam sipariş adapter'dan alınabilirse kanonik DTO korunur ve yalnızca `raw`
     * alanına kargo verisi eklenir. Alınamazsa (ör. webhook yalnızca kargo no
     * taşıyorsa) yalnızca dış sipariş referansı + kargo verisini içeren asgari
     * CanonicalOrder oluşturulur: sürücüler CargoCompany/TrackingNumber değerlerini
     * `raw` içinden okur, kişisel veriye gerek yoktur.
     */
    public function pushShipment(
        int $companyId,
        string $orderExternalRef,
        string $cargoNo,
        ?string $cargoCompany,
    ): SyncResult {
        $started = microtime(true);

        if (config('integrations.dry_run') === true) {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'shipment',
                $orderExternalRef,
                'skipped',
                ['reason' => 'dry_run'],
                $this->elapsedMs($started),
            );

            return SyncResult::skipped('dry_run: kargo dış sisteme yazılmadı.');
        }

        $cargo = [
            'CargoCompany' => $cargoCompany ?? '',
            'TrackingNumber' => $cargoNo,
            'cargoCompany' => $cargoCompany ?? '',
            'cargoNumber' => $cargoNo,
        ];

        try {
            $adapter = $this->adapter($companyId);
            $fetched = $adapter->fetchOrder($orderExternalRef);
            $order = $fetched instanceof CanonicalOrder
                ? $this->withCargo($fetched, $cargo)
                : new CanonicalOrder(
                    $orderExternalRef,
                    null,
                    '',
                    '0',
                    '',
                    null,
                    false,
                    null,
                    null,
                    null,
                    [],
                    $cargo,
                );

            $result = $adapter->pushShipment($order);

            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'shipment',
                $orderExternalRef,
                $result->ok ? 'ok' : 'failed',
                ['from_full_order' => $fetched instanceof CanonicalOrder],
                $this->elapsedMs($started),
            );

            return $result;
        } catch (Throwable $e) {
            IntegrationAudit::log(
                $companyId,
                SyncDirection::Out,
                'shipment',
                $orderExternalRef,
                'failed',
                ['error_class' => get_class($e)],
                $this->elapsedMs($started),
            );

            return SyncResult::failure('Kargo dış sisteme yazılamadı.');
        }
    }

    /**
     * Dış iade siparişini MRC'ye taslak iade belgesi olarak alır.
     *
     * TODO: DocumentController::returnFrom() akışına bağlanarak kaynak fatura
     * seçimi ve stok/cogs tersleme mantığı buraya bağlanacak. Bu metot şimdilik
     * yalnızca taslak belge açar; stok hareketi ÜRETMEZ (sahte tersleme yok).
     */
    public function handleReturn(int $companyId, CanonicalOrder $returnOrder): ?Document
    {
        return DB::transaction(function () use ($companyId, $returnOrder): ?Document {
            $map = IntegrationAudit::findMap(
                $companyId,
                IntegrationAudit::TYPE_RETURN,
                $returnOrder->externalRef,
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

            $issueDate = $returnOrder->placedAt?->toDateString() ?? date('Y-m-d');
            $currency = strtoupper(trim($returnOrder->currency));
            $currency = mb_substr($currency !== '' ? $currency : 'TRY', 0, 3);

            $totals = [
                'subtotal' => '0.0000',
                'discount_total' => '0.0000',
                'tax_total' => '0.0000',
                'grand_total' => Money::dec($returnOrder->grandTotal),
            ];

            $sourceRef = $returnOrder->raw['source_order_ref']
                ?? $returnOrder->raw['sourceExternalRef']
                ?? null;
            $sourceId = null;

            if (is_string($sourceRef) && $sourceRef !== '') {
                $sourceMap = IntegrationAudit::findMap(
                    $companyId,
                    IntegrationAudit::TYPE_ORDER,
                    $sourceRef,
                );
                if ($sourceMap !== null) {
                    $sourceId = (int) $sourceMap->mrc_id;
                }
            }

            $document = Document::create([
                'company_id' => $companyId,
                'type' => Document::TYPE_RETURN,
                'serial' => Document::nextSerial($companyId, Document::TYPE_RETURN, (int) substr($issueDate, 0, 4)),
                'party_id' => $this->orderSync()->resolvePartyId($companyId, $returnOrder),
                'status' => 'draft',
                'issue_date' => $issueDate,
                'currency_code' => $currency,
                'exchange_rate' => 1,
                'rate_date' => $issueDate,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => 'Tsoft iade siparişi (kanonik içe aktarım); stok etkisi uygulanmadı.',
                'source_document_id' => $sourceId,
            ]);

            foreach (array_values($returnOrder->lines) as $index => $line) {
                $subtotal = Money::mul($line->quantity, $line->unitPrice);
                $discount = Money::percent($subtotal, $line->discountRate);
                $net = Money::sub($subtotal, $discount);
                $tax = Money::percent($net, $line->taxRate);

                $document->items()->create([
                    'company_id' => $companyId,
                    'sort_order' => $index,
                    'name' => $line->name !== null && $line->name !== ''
                        ? mb_substr($line->name, 0, 190)
                        : 'Tsoft iade satırı',
                    'quantity' => Money::dec($line->quantity),
                    'unit_price' => Money::dec($line->unitPrice),
                    'price_currency_code' => $currency,
                    'discount_rate' => Money::dec($line->discountRate),
                    'tax_rate' => Money::dec($line->taxRate),
                    'line_subtotal' => $subtotal,
                    'line_discount' => $discount,
                    'line_tax' => $tax,
                    'line_total' => Money::add($net, $tax),
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
                IntegrationAudit::TYPE_RETURN,
                $returnOrder->externalRef,
            );
            IntegrationAudit::log(
                $companyId,
                SyncDirection::In,
                'return',
                $returnOrder->externalRef,
                'created',
                ['document_id' => (int) $document->id],
            );

            return $document->refresh();
        });
    }

    /** @param array<string,mixed> $cargo */
    private function withCargo(CanonicalOrder $order, array $cargo): CanonicalOrder
    {
        return new CanonicalOrder(
            $order->externalRef,
            $order->orderNumber,
            $order->currency,
            $order->grandTotal,
            $order->externalStatus,
            $order->paymentRef,
            $order->isPaid,
            $order->placedAt,
            $order->buyerTaxId,
            $order->buyerRef,
            $order->lines,
            array_merge($order->raw, $cargo),
        );
    }

    private function orderSync(): OrderSyncService
    {
        return $this->orderSync ?? new OrderSyncService;
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
