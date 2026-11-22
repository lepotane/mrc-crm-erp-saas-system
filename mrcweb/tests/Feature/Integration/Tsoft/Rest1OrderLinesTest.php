<?php

namespace Tests\Feature\Integration\Tsoft;

use App\Integration\Tsoft\LegacyRest1Driver;
use App\Integration\Tsoft\TsoftMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * rest1 `FetchProductData=1` ile gelen OrderDetails gerçek satırlara çevrilir;
 * alıcı kişisel verisi kanonik DTO'ya / raw'a sızmaz. Sipariş okuması kalıcı
 * rest1 yolundan yapılır ve tüm durum kimlikleri taranır.
 */
final class Rest1OrderLinesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return array<string,mixed> */
    private function rest1Order(): array
    {
        return [
            'OrderId' => 14870,
            'OrderCode' => 'AK221114870',
            'OrderNumber' => 'AK221114870',
            // Gerçek UTC: 21 Kas 2026 23:03 -> panel (Europe/Istanbul) 22 Kas.
            'OrderDateTimeStamp' => 1795302236,
            'OrderDate' => '2026-11-22T02:03:56Z',
            'OrderTotalPrice' => 107.1,
            'OrderStatusId' => 8,
            'Currency' => 'EUR',
            'PaymentType' => 'Paypal',
            'TransactionId' => 'AK221114870',
            'CargoId' => 6,
            'CargoTrackingCode' => '7663990633',
            'CustomerId' => 0,
            'CustomerCode' => 'ts_1795356543_6691',
            'CustomerName' => 'Keith Morgan',
            'CustomerUsername' => 'nagrom69@me.com',
            'CustomerPhone' => '+447967665295',
            'OrderDetails' => [
                [
                    'OrderProductId' => 100,
                    'ProductId' => 226,
                    'ProductCode' => 'T1537',
                    'ProductName' => 'For Laguna Espace Card Module Kit 433MHz PCF7936 (non-keyless)',
                    'SubProductCode' => 'Laguna',
                    'Quantity' => 1,
                    'SellingPrice' => 75.0,
                    'BuyingPrice' => 15.0,
                    'Vat' => 0,
                    'DiscountPercent' => 0,
                ],
                [
                    'OrderProductId' => 101,
                    'ProductId' => 227,
                    'ProductCode' => 'T226',
                    'ProductName' => 'Test Ürünü',
                    'Quantity' => 1,
                    'SellingPrice' => 32.1,
                    'BuyingPrice' => 5.0,
                    'Vat' => 0,
                    'DiscountPercent' => 0,
                ],
            ],
        ];
    }

    public function test_order_details_become_real_lines_with_cost_and_istanbul_date(): void
    {
        $order = TsoftMapper::order($this->rest1Order());

        $this->assertSame('AK221114870', $order->externalRef);
        $this->assertSame('107.1000', $order->grandTotal);
        $this->assertCount(2, $order->lines);

        $first = $order->lines[0];
        $this->assertSame('T1537', $first->sku);
        $this->assertSame(
            'For Laguna Espace Card Module Kit 433MHz PCF7936 (non-keyless) - Laguna',
            $first->name,
        );
        $this->assertSame('1.0000', $first->quantity);
        $this->assertSame('75.0000', $first->unitPrice);
        $this->assertSame('15.0000', $first->cost);
        $this->assertSame('100', $first->externalRef);
        $this->assertSame('0.0000', $first->taxRate);
        $this->assertSame('0.0000', $first->discountRate);
        $this->assertSame('15.0000', $first->toArray()['cost']);

        $second = $order->lines[1];
        $this->assertSame('T226', $second->sku);
        $this->assertSame('1.0000', $second->quantity);
        $this->assertSame('32.1000', $second->unitPrice);
        $this->assertSame('5.0000', $second->cost);
        $this->assertSame('101', $second->externalRef);

        $this->assertNotNull($order->placedAt);
        $this->assertSame('2026-11-22', $order->placedAt->toDateString());
        $this->assertSame('Europe/Istanbul', $order->placedAt->timezoneName);

        // Kanal metadata'sı için güvenli ham alanlar.
        $this->assertSame('DHL Express', $order->raw['CargoCompany']);
        $this->assertSame('Paypal', $order->raw['PaymentMethod']);
        $this->assertSame('107.1000', $order->raw['OrderTotalPrice']);
        $this->assertSame('2', $order->raw['ProductsCount']);

        // KVKK: alıcı adı / e-posta / telefon ne DTO'da ne raw'da görünür.
        $serialized = (string) json_encode([$order->toArray(), $order->raw], JSON_UNESCAPED_UNICODE);
        foreach (['Keith Morgan', 'nagrom69@me.com', '+447967665295'] as $leak) {
            $this->assertStringNotContainsString($leak, $serialized);
        }
    }

    public function test_rest1_driver_requests_products_and_every_status_id(): void
    {
        /** @var array<int,array<string,mixed>> $orderRequests */
        $orderRequests = [];

        Http::fake(function (Request $request) use (&$orderRequests) {
            if (str_contains($request->url(), '/auth/login/')) {
                return Http::response([
                    'data' => [[
                        'token' => 'rest1-test-token',
                        'expirationTime' => time() + 3600,
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), '/order/get')) {
                $data = $request->data();
                $orderRequests[] = $data;
                $rows = (string) ($data['OrderStatusId'] ?? '') === '6'
                    ? [$this->rest1Order()]
                    : [];

                return Http::response(['Data' => $rows], 200);
            }

            return Http::response([], 200);
        });

        $driver = new LegacyRest1Driver(1, 'https://autokeystore.com', 'rest-user', 'rest-pass');
        $orders = $driver->fetchAllOrders();
        $buyers = $driver->orderBuyers();

        $this->assertCount(1, $orders);
        $this->assertCount(2, $orders[0]->lines);
        $this->assertCount(1, $buyers);
        $this->assertSame('ts_1795356543_6691', $buyers[0]['code']);
        $this->assertSame('0', $buyers[0]['id']);
        $this->assertSame('Keith Morgan', $buyers[0]['name']);
        $this->assertSame('nagrom69@me.com', $buyers[0]['email']);

        $this->assertGreaterThanOrEqual(2, count($orderRequests));
        $statuses = [];
        foreach ($orderRequests as $data) {
            $this->assertSame(1, (int) ($data['FetchProductData'] ?? 0));
            $statuses[(string) ($data['OrderStatusId'] ?? '')] = true;
        }
        $this->assertArrayHasKey('1', $statuses);
        $this->assertArrayHasKey('6', $statuses);
        $this->assertGreaterThanOrEqual(2, count($statuses));
        $this->assertSame(500, (int) ($orderRequests[0]['limit'] ?? 0));
    }
}
