<?php

namespace Tests\Feature\Integration\Orders;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Orders\OrderSyncService;
use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Tsoft siparişinin kanal numarası ve ödeme/kargo bilgileri belgede ve
 * satış ekranının API'sinde görünür olmalı; KİŞİSEL VERİ taşınmamalı.
 */
final class ChannelMetaPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** rest1 order/get karşılığı: satır yok, toplam var, teknik alanlar dolu. */
    private function tsoftOrder(): CanonicalOrder
    {
        return new CanonicalOrder(
            'AK221114870',
            'AK221114870',
            'TRY',
            '107.1000',
            '6',
            null,
            false,
            Carbon::parse('2026-11-22 12:00:00'),
            null,
            null,
            [],
            [
                'OrderStatusId' => '6',
                'OrderTotalPrice' => '107.1000',
                'CustomerCode' => 'T4042',
                'CustomerId' => 'T4042',
                'TsoftOrderNo' => 'AK221114870',
                'CargoCompany' => 'DHL',
                'TrackingNumber' => '7663990633',
                'PaymentMethod' => 'paypal',
                'PaymentTransactionId' => 'TX-1',
            ],
        );
    }

    public function test_external_no_ve_channel_meta_kaydedilir_toplamlar_bozulmaz(): void
    {
        $ctx = $this->register('channelmeta1@firma.test', 'Channel Meta 1');

        $document = (new OrderSyncService)->ingest($ctx['company_id'], $this->tsoftOrder());

        $this->assertSame(Document::TYPE_SALES_ORDER, $document->type);
        $this->assertSame('AK221114870', $document->external_no);

        $meta = json_decode((string) $document->channel_meta, true);
        $this->assertIsArray($meta);
        $this->assertSame('AK221114870', $meta['tsoft_order_no']);
        $this->assertSame('DHL', $meta['cargo_company']);
        $this->assertSame('paypal', $meta['payment_method']);
        $this->assertSame('7663990633', $meta['tracking_number']);
        $this->assertSame('TX-1', $meta['payment_transaction_id']);
        $this->assertArrayNotHasKey('CustomerCode', $meta);
        $this->assertArrayNotHasKey('CustomerId', $meta);

        // Tutarlar ve duyulmayan KVKK notu değişmemeli.
        $this->assertSame('107.1000', $document->subtotal);
        $this->assertSame('107.1000', $document->grand_total);
        $this->assertStringContainsString('kişisel veri taşınmaz', (string) $document->notes);
    }

    public function test_api_liste_ve_detay_yeni_alanlari_dondurur(): void
    {
        $ctx = $this->register('channelmeta2@firma.test', 'Channel Meta 2');
        $headers = $ctx['headers'];

        $document = (new OrderSyncService)->ingest($ctx['company_id'], $this->tsoftOrder());
        $id = (int) $document->id;

        $liste = $this->apiGet('/api/v1/documents?type=sales_order', $headers)
            ->assertOk()->json('data');
        $satir = collect($liste)->firstWhere('id', $id);
        $this->assertNotNull($satir);
        $this->assertSame('AK221114870', $satir['external_no']);
        $this->assertSame('DHL', $satir['channel_meta']['cargo_company']);
        $this->assertSame('paypal', $satir['channel_meta']['payment_method']);

        $detay = $this->apiGet("/api/v1/documents/{$id}", $headers)
            ->assertOk()->json('document');
        $this->assertSame('AK221114870', $detay['external_no']);
        $this->assertSame('paypal', $detay['channel_meta']['payment_method']);
        $this->assertSame('7663990633', $detay['channel_meta']['tracking_number']);
        $this->assertSame('107.1000', $detay['grand_total']);
    }
}
