<?php

namespace Tests\Feature\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalOrderLine;
use App\Integration\Orders\OrderSyncService;
use App\Models\Document;
use App\Models\DocumentItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class OrderSyncServiceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    private function order(string $externalRef, string $unitPrice = '10.5000'): CanonicalOrder
    {
        return new CanonicalOrder(
            $externalRef,
            'SIP-'.$externalRef,
            'TRY',
            '21.0000',
            '',
            null,
            false,
            Carbon::parse('2026-11-20 10:00:00'),
            null,
            null,
            [new CanonicalOrderLine('SKU-1', 'Ürün 1', '2.0000', $unitPrice, '0', '0')],
        );
    }

    public function test_ayni_siparis_iki_kez_gelirse_tek_belge_olur(): void
    {
        $ctx = $this->register('ordersync1@firma.test', 'Order Sync 1');
        $companyId = $ctx['company_id'];
        $service = new OrderSyncService;

        $order = $this->order('ORD-100');
        $first = $service->ingest($companyId, $order);
        $second = $service->ingest($companyId, $order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::where('company_id', $companyId)
            ->where('type', Document::TYPE_SALES_ORDER)->count());
        $this->assertSame(1, DocumentItem::where('document_id', $first->id)->count());

        $item = DocumentItem::where('document_id', $first->id)->firstOrFail();
        $this->assertIsString($item->unit_price);
        $this->assertSame('10.5000', $item->unit_price);
    }

    public function test_ikinci_siparis_yeni_belge_acar(): void
    {
        $ctx = $this->register('ordersync2@firma.test', 'Order Sync 2');
        $companyId = $ctx['company_id'];
        $service = new OrderSyncService;

        $service->ingest($companyId, $this->order('ORD-200'));
        $service->ingest($companyId, $this->order('ORD-201', '123.4567'));

        $this->assertSame(2, Document::where('company_id', $companyId)
            ->where('type', Document::TYPE_SALES_ORDER)->count());

        $second = Document::where('company_id', $companyId)
            ->where('type', Document::TYPE_SALES_ORDER)
            ->orderBy('id')->get()[1];
        $item = DocumentItem::where('document_id', $second->id)->firstOrFail();
        $this->assertSame('123.4567', $item->unit_price);
    }

    public function test_satir_toplamlari_bcmath_ile_string_tutulur(): void
    {
        $ctx = $this->register('ordersync3@firma.test', 'Order Sync 3');
        $companyId = $ctx['company_id'];

        $order = new CanonicalOrder(
            'ORD-300',
            'SIP-300',
            'TRY',
            '37.1700',
            '',
            null,
            false,
            Carbon::parse('2026-11-20 10:00:00'),
            null,
            null,
            [new CanonicalOrderLine('SKU-2', 'Ürün 2', '3.0000', '10.5000', '18', '0')],
        );

        $document = (new OrderSyncService)->ingest($companyId, $order);
        $item = DocumentItem::where('document_id', $document->id)->firstOrFail();

        $this->assertSame('31.5000', $item->line_subtotal);
        $this->assertSame('5.6700', $item->line_tax);
        $this->assertSame('37.1700', $item->line_total);
        $this->assertSame('37.1700', $document->fresh()->grand_total);
    }
}
