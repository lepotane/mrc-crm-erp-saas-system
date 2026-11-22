<?php

namespace Tests\Feature\Integration\Tsoft;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Tsoft\AdminApiDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class AdminApiOrderImportTest extends TestCase
{
    private const CLIENT_ID = 'app.test';

    private const CLIENT_SECRET = 'client-secret-value';

    private const ACCESS_TOKEN = '2171|access-token-value';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return array<string,mixed> */
    private function ordersPayload(): array
    {
        $summary = [
            'grandTotal' => 174.1, 'total' => 174.1, 'subTotal' => 174.1,
            'productSubTotal' => 158.4, 'productTotal' => 174.1,
            'discountTotal' => 0, 'shipping' => 15.7, 'cargoTotal' => 15.7,
            'cargoSubtotal' => 15.7, 'tax' => 0, 'currency' => 'EUR', 'discountPercent' => 0,
        ];

        return [
            'data' => [
                [
                    'id' => 1,
                    'orderNumber' => 'TS11111',
                    'orderStatus' => ['id' => 8, 'name' => 'Teslim Edildi'],
                    'fulfillmentStatus' => null,
                    'fulfillmentStatusId' => null,
                    'orderCurrency' => 'EUR',
                    'currencyRate' => '1.00000000',
                    'createdAt' => 1573480353,
                    'updatedAt' => 1574162692,
                    'platform' => 'website',
                    'summary' => ['default' => $summary, 'customer' => $summary],
                    'products' => [
                        [
                            'id' => 10, 'productId' => 226, 'code' => 'T226',
                            'name' => 'Renault Megane 2 Smart Card 434MHz', 'variantName' => '',
                            'brand' => 'Renault', 'quantity' => 2, 'tax' => 0, 'discountPercent' => 0,
                            'priceSummary' => ['default' => [
                                'unitSalePrice' => 9.9, 'salePrice' => 9.9,
                                'totalSalePrice' => 19.8, 'currency' => 'EUR',
                            ]],
                        ],
                        [
                            'id' => 11, 'productId' => 227, 'code' => 'T227',
                            'name' => 'Renault Clio 3 Key Blade', 'variantName' => '3 Buton',
                            'brand' => 'Renault', 'quantity' => 1, 'tax' => 0, 'discountPercent' => 0,
                            'priceSummary' => ['default' => [
                                'unitSalePrice' => 154.6, 'salePrice' => 154.6,
                                'totalSalePrice' => 154.6, 'currency' => 'EUR',
                            ]],
                        ],
                    ],
                    'customer' => [
                        'id' => 3, 'wsCode' => 'T3',
                        'firstName' => 'FREDERICK', 'lastName' => 'COPPENOLLE',
                        'email' => 'frederick.coppenolle@example.test', 'phone' => '+32 500 00 00 00',
                    ],
                    'payment' => [
                        'id' => -13, 'method' => 'iyzico', 'subMethod' => '', 'bankName' => '',
                        'ccBank' => '', 'ccBrand' => '', 'onlineTransactionVendor' => 'Iyzico',
                        'transactionId' => 'TS11111', 'installment' => 0, 'approved' => true,
                    ],
                    'shipping' => [
                        'trackingCompany' => 'DHL', 'trackingCompanyId' => 6,
                        'trackingNumber' => '7663990633', 'cargoTotal' => '15.7000', 'shipByAt' => null,
                    ],
                    'invoice' => ['no' => '', 'status' => 0, 'type' => 0, 'date' => 0, 'einvoiceLink' => ''],
                    'address' => [
                        'billing' => ['taxNumber' => 'BE0123456789', 'city' => 'Poperinge', 'country' => 'Belçika'],
                        'shipping' => ['city' => 'Poperinge', 'country' => 'Belçika'],
                    ],
                    'shippingAddress' => [
                        'city' => 'Poperinge', 'country' => '',
                        'name' => 'FREDERICK COPENNOLLE', 'town' => '',
                    ],
                    'store' => null, 'wsOrderNumber' => null, 'generalNote' => '', 'productsCount' => 13,
                ],
                [
                    'id' => 2,
                    'orderNumber' => 'TS22222',
                    'orderStatus' => ['id' => 3, 'name' => 'Hazırlanıyor'],
                    'orderCurrency' => 'EUR',
                    'createdAt' => 1573480354,
                    'platform' => 'website',
                    'summary' => ['default' => [
                        'grandTotal' => 50.0, 'total' => 50.0, 'subTotal' => 50.0,
                        'discountTotal' => 0, 'shipping' => 0, 'tax' => 0, 'currency' => 'EUR',
                    ]],
                    'products' => [],
                    'customer' => ['id' => 4, 'wsCode' => 'T4', 'email' => 'other.person@example.test'],
                    'payment' => [
                        'method' => 'havale', 'transactionId' => 'TS22222',
                        'installment' => 0, 'approved' => false,
                    ],
                    'address' => ['billing' => ['taxNumber' => '']],
                ],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => 2],
        ];
    }

    public function test_fetches_all_orders_with_durable_client_credentials_token(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/client-tokens')) {
                return Http::response([
                    'data' => ['attributes' => [
                        'accessToken' => self::ACCESS_TOKEN,
                        'expiresIn' => 86400,
                    ]],
                ], 201);
            }

            if (str_contains($request->url(), '/orders/order')) {
                return Http::response($this->ordersPayload(), 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });

        // Sondaki eğik çizgi normalize edilmelidir.
        $driver = new AdminApiDriver(101, 'https://autokeystore.com/', self::CLIENT_ID, self::CLIENT_SECRET);
        $orders = $driver->fetchAllOrders();

        $this->assertCount(2, $orders);
        $first = $orders[0];
        $this->assertInstanceOf(CanonicalOrder::class, $first);
        $this->assertSame('1', $first->externalRef);
        $this->assertSame('TS11111', $first->orderNumber);
        $this->assertSame('EUR', $first->currency);
        $this->assertSame('174.1000', $first->grandTotal);
        $this->assertSame('8', $first->externalStatus);
        $this->assertTrue($first->isPaid);
        $this->assertSame('T3', $first->buyerRef);
        $this->assertSame('BE0123456789', $first->buyerTaxId);
        $this->assertCount(2, $first->lines);

        $this->assertSame('T226', $first->lines[0]->sku);
        $this->assertSame('10', $first->lines[0]->externalRef);
        $this->assertSame('2.0000', $first->lines[0]->quantity);
        $this->assertSame('9.9000', $first->lines[0]->unitPrice);
        $this->assertSame('0.0000', $first->lines[0]->taxRate);
        $this->assertSame('0.0000', $first->lines[0]->discountRate);
        $this->assertSame('T227', $first->lines[1]->sku);
        $this->assertSame('11', $first->lines[1]->externalRef);
        $this->assertSame('1.0000', $first->lines[1]->quantity);
        $this->assertSame('154.6000', $first->lines[1]->unitPrice);
        $this->assertSame('Renault Clio 3 Key Blade - 3 Buton', $first->lines[1]->name);

        $this->assertSame('DHL', $first->raw['CargoCompany']);
        $this->assertSame('7663990633', $first->raw['TrackingNumber']);
        $this->assertSame('T3', $first->raw['CustomerCode']);
        $this->assertSame('1', $first->raw['PaymentApproved']);

        // İkinci siparişte ürün yoksa kalem uydurulmaz.
        $this->assertSame([], $orders[1]->lines);
        $this->assertFalse($orders[1]->isPaid);

        // KVKK: kişisel veriler DTO'ya veya raw'a sızmaz.
        $serialized = (string) json_encode([
            $first->toArray(), $first->raw,
            $orders[1]->toArray(), $orders[1]->raw,
        ], JSON_UNESCAPED_UNICODE);
        foreach ([
            'frederick.coppenolle@example.test', 'FREDERICK COPENNOLLE',
            '+32 500 00 00 00', 'Poperinge', 'other.person@example.test',
            self::CLIENT_SECRET, self::ACCESS_TOKEN,
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $serialized);
        }

        // Token uç: JSON:API başlıkları ve doğru clientId gönderildi.
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/auth/client-tokens')
                && $request->hasHeader('Content-Type', 'application/vnd.api+json')
                && ($data['data']['attributes']['clientId'] ?? null) === self::CLIENT_ID;
        });

        // Sipariş isteği: erişim token'ı Bearer olarak iletildi.
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/orders/order')
                && $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
                && $request->hasHeader('Accept', 'application/vnd.api+json')
                && str_contains($request->url(), 'sort=id')
                && str_contains($request->url(), 'include=');
        });

        Http::assertSentCount(2); // 1 token + 1 sipariş sayfası
    }

    public function test_panel_token_fallback_is_used_when_no_api_key_configured(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/client-tokens')) {
                return Http::response(['message' => 'Unexpected token request'], 500);
            }

            return Http::response($this->ordersPayload(), 200);
        });

        $driver = new AdminApiDriver(102, 'https://autokeystore.com', '', '', 'test-token');
        $orders = $driver->fetchAllOrders();

        $this->assertCount(2, $orders);
        $this->assertSame('TS11111', $orders[0]->orderNumber);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/orders/order')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/auth/client-tokens'));
        Http::assertSentCount(1); // token ucuna hiç gidilmedi
    }

    public function test_401_refreshes_token_once_and_retries_once(): void
    {
        $tokenCalls = 0;
        $orderCalls = [];

        Http::fake(function (Request $request) use (&$tokenCalls, &$orderCalls) {
            if (str_contains($request->url(), '/auth/client-tokens')) {
                $tokenCalls++;

                return Http::response([
                    'data' => ['attributes' => [
                        'accessToken' => $tokenCalls === 1 ? self::ACCESS_TOKEN : '2171|fresh-token',
                        'expiresIn' => 86400,
                    ]],
                ], 201);
            }

            if (str_contains($request->url(), '/orders/order')) {
                $orderCalls[] = $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
                    ? 'initial'
                    : 'fresh';

                return count($orderCalls) === 1
                    ? Http::response(['message' => 'Unauthorized'], 401)
                    : Http::response($this->ordersPayload(), 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });

        $orders = (new AdminApiDriver(103, 'https://autokeystore.com', self::CLIENT_ID, self::CLIENT_SECRET))
            ->fetchAllOrders();

        $this->assertCount(2, $orders);
        $this->assertSame(2, $tokenCalls);
        $this->assertCount(2, $orderCalls);
        $this->assertSame('initial', $orderCalls[0]);
        $this->assertSame('fresh', $orderCalls[1]);
    }

    public function test_persistent_401_throws_sanitized_exception(): void
    {
        Http::fake(fn (Request $request) => Http::response(['message' => 'Unauthorized'], 401));

        try {
            (new AdminApiDriver(
                104,
                'https://autokeystore.com',
                self::CLIENT_ID,
                self::CLIENT_SECRET,
                'test-token',
            ))->fetchAllOrders();
            $this->fail('Beklenen RuntimeException fırlatılmadı.');
        } catch (RuntimeException $exception) {
            // Hata çıktısı ne sırrı ne token'ı içerir.
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $exception->getMessage());
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, $exception->getMessage());
            $this->assertStringNotContainsString('test-token', $exception->getMessage());
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        }
    }
}
