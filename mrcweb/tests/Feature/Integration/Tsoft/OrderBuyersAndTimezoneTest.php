<?php

namespace Tests\Feature\Integration\Tsoft;

use App\Integration\Tsoft\AdminApiDriver;
use App\Integration\Tsoft\TsoftMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Panel API v3 siparişlerinde (1) createdAt mağaza yerel saatinde yorumlanır,
 * (2) rest1 müşteri listesinde olmayan misafir alıcılar kanonik DTO dışında
 * toplanır. Kişisel veri kanonik DTO'ya / raw'a ASLA sızmaz.
 */
final class OrderBuyersAndTimezoneTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @return array<string,mixed> */
    private function panelOrder(): array
    {
        return [
            'id' => 14870,
            'orderNumber' => 'AK221114870',
            'orderCurrency' => 'EUR',
            'createdAt' => 1795302236,
            'orderStatus' => ['id' => 8],
            'payment' => [
                'method' => 'Paypal', 'approved' => true, 'transactionId' => 'AK221114870',
            ],
            'summary' => ['default' => [
                'grandTotal' => 107.1, 'subTotal' => 107.1,
                'discountTotal' => 0, 'shipping' => 0, 'tax' => 0,
            ]],
            'products' => [
                [
                    'id' => 10, 'productId' => 226, 'code' => 'T226', 'name' => 'Test Ürünü',
                    'quantity' => 1, 'tax' => 0, 'discountPercent' => 0,
                    'priceSummary' => ['default' => ['unitSalePrice' => 107.1, 'salePrice' => 107.1]],
                ],
            ],
            'customer' => [
                'id' => 4041, 'wsCode' => 'T4041', 'name' => 'Volodymyr Zduniuk',
                'email' => 'volodymyr@example.test', 'phone' => '+90 500 000 00 00',
            ],
            'address' => ['billing' => [
                'name' => 'Volodymyr Zduniuk', 'taxNumber' => 'T4041', 'city' => 'Kyiv',
            ]],
        ];
    }

    public function test_admin_order_uses_istanbul_local_time_and_no_personal_data_in_canonical(): void
    {
        $order = TsoftMapper::adminOrder($this->panelOrder());

        $this->assertNotNull($order->placedAt);
        $this->assertSame('2026-11-22', $order->placedAt->toDateString());
        $this->assertSame('Europe/Istanbul', $order->placedAt->timezoneName);

        $serialized = (string) json_encode([$order->toArray(), $order->raw], JSON_UNESCAPED_UNICODE);
        foreach (['Volodymyr Zduniuk', 'volodymyr@example.test', '+90 500 000 00 00'] as $leak) {
            $this->assertStringNotContainsString($leak, $serialized);
        }
    }

    public function test_guest_buyer_is_collected_outside_the_canonical_order(): void
    {
        $row = $this->panelOrder();
        $row['id'] = 14874;
        $row['orderNumber'] = 'AK221114874';
        $row['createdAt'] = 1795356701;
        $row['summary']['default']['grandTotal'] = 24.41;
        $row['customer'] = [
            'id' => 0, 'wsCode' => 'ts_x', 'name' => 'JOHN DOE',
            'email' => 'guest@example.test', 'phone' => '+90 555 000 00 00',
        ];
        $row['address']['billing']['name'] = 'Keith Morgan';

        Http::fake([
            '*' => Http::response([
                'data' => [$row],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => 1],
            ], 200),
        ]);

        $driver = new AdminApiDriver(1, 'https://autokeystore.com', '', '', 'test-token');
        $orders = $driver->fetchAllOrders();
        $buyers = $driver->orderBuyers();

        $this->assertCount(1, $orders);
        $this->assertCount(1, $buyers);
        $this->assertSame('ts_x', $buyers[0]['code']);
        $this->assertSame('0', $buyers[0]['id']);
        $this->assertSame('Keith Morgan', $buyers[0]['name']);

        // Panel token kullanıldığı için ek token isteği yapılmaz.
        Http::assertSentCount(1);

        $serialized = (string) json_encode([$orders[0]->toArray(), $orders[0]->raw], JSON_UNESCAPED_UNICODE);
        foreach (['Keith Morgan', 'JOHN DOE', 'guest@example.test', '+90 555 000 00 00'] as $leak) {
            $this->assertStringNotContainsString($leak, $serialized);
        }
    }
}
