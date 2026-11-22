<?php

namespace Tests\Feature\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Orders\OrderSyncService;
use App\Models\Document;
use App\Models\DocumentItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

final class OrderAmountAndStatusTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** Tsoft rest1 order/get karşılığı: tutar var, satır (ürün) dizisi yok. */
    private function tsoftOrder(string $externalRef, string $statusId): CanonicalOrder
    {
        return new CanonicalOrder(
            $externalRef,
            $externalRef,
            'EUR',
            '107.1000',
            $statusId,
            null,
            false,
            Carbon::parse('2026-11-22 12:00:00'),
            null,
            'T4042',
            [],
            [
                'OrderStatusId' => $statusId,
                'OrderTotalPrice' => '107.1000',
                'CustomerCode' => 'T4042',
            ],
        );
    }

    public function test_tsoft_toplam_ve_kargo_durumu_confirmed_olarak_ice_alinir(): void
    {
        $ctx = $this->register('orderamount1@firma.test', 'Order Amount 1');
        $companyId = $ctx['company_id'];

        $document = (new OrderSyncService)->ingest(
            $companyId,
            $this->tsoftOrder('TSOFT-107', '6'),
        );

        $this->assertSame('107.1000', $document->grand_total);
        $this->assertSame('confirmed', $document->status);
        $this->assertSame(Document::TYPE_SALES_ORDER, $document->type);
        $this->assertSame(1, DocumentItem::where('document_id', $document->id)->count());

        $item = DocumentItem::where('document_id', $document->id)->firstOrFail();
        $this->assertSame('Tsoft sipariş toplamı', $item->name);
        $this->assertSame('1.0000', $item->quantity);
        $this->assertSame('107.1000', $item->unit_price);
        $this->assertSame('107.1000', $item->line_total);
    }

    public function test_odeme_bekleyen_siparis_taslak_kalir_ve_toplami_dogru_olur(): void
    {
        $ctx = $this->register('orderamount2@firma.test', 'Order Amount 2');
        $companyId = $ctx['company_id'];

        $document = (new OrderSyncService)->ingest(
            $companyId,
            $this->tsoftOrder('TSOFT-108', '2'),
        );

        $this->assertSame('draft', $document->status);
        $this->assertSame('107.1000', $document->grand_total);
        $this->assertSame(1, DocumentItem::where('document_id', $document->id)->count());

        $item = DocumentItem::where('document_id', $document->id)->firstOrFail();
        $this->assertSame('107.1000', $item->unit_price);
    }
}
