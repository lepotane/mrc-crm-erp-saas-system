<?php

namespace Tests\Feature\Integration\Tsoft;

use App\Integration\Sync\IntegrationIdMap;
use App\Integration\Sync\OrderBuyerSync;
use App\Integration\Tsoft\LegacyRest1Driver;
use App\Integration\Tsoft\TsoftMapper;
use App\Jobs\Integration\ProcessTsoftOrder;
use App\Models\Document;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Misafir siparişlerde gerçek alıcı adı yalnızca rest1'in iç içe adres
 * nesnesindedir (CustomerName boştur). Bu veri kalıcıdır: rest1 → alıcı →
 * cari → sipariş köprüsü panel oturumu olmadan uçtan uca çalışır.
 *
 * Yalnızca dummy kimlik bilgileri kullanılır; gerçek sır yoktur.
 */
final class FullOrdererDetailsAndBridgeTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return array<string,mixed> */
    private function guestOrderRow(): array
    {
        return [
            'OrderId' => 14874,
            'OrderCode' => 'AK221114874',
            'OrderNumber' => 'AK221114874',
            // Gerçek UTC damga; mağaza saati (Europe/Istanbul) için 22 Kas 2026.
            'OrderDateTimeStamp' => 1795356701,
            'OrderTotalPrice' => 24.41,
            'OrderStatusId' => 8,
            'Currency' => 'GBP',
            'PaymentType' => 'Paypal',
            'TransactionId' => 'AK221114874',
            'CustomerId' => 4041,
            'CustomerCode' => 'ts_guest874',
            // Misafir: gerçek ad aşağıdaki fatura adresindedir.
            'CustomerName' => '',
            'CustomerUsername' => 'keith@example.test',
            'CustomerPhone' => '',
            'InvoiceAddress' => [
                'Name' => 'Keith Morgan',
                'Company' => '',
                'Mobile' => '+447967665295',
                'OtherPhone' => '+447967665295',
                'Phone' => '',
                'Address' => '88 bexley road',
                'Town' => 'Erith',
                'City' => 'Kent',
                'Country' => 'İngiltere - GB',
                'CountryCode' => 'GB',
                'Zipcode' => 'Da83sp',
                'Taxno' => '',
            ],
            'DeliveryAddress' => [
                'Name' => 'Keith Morgan',
                'Mobile' => '+447967665295',
                'Address' => '88 bexley road',
                'Town' => 'Erith',
                'City' => 'Kent',
                'Country' => 'İngiltere - GB',
                'Zipcode' => 'Da83sp',
            ],
        ];
    }

    /**
     * rest1 auth + order/get uçlarını taklit eder; order/get isteklerini toplar.
     *
     * @param  array<int,array<string,mixed>>  $orderRequests
     */
    private function fakeRest1(array &$orderRequests): void
    {
        $row = $this->guestOrderRow();

        Http::fake(function (Request $request) use (&$orderRequests, $row) {
            if (str_contains($request->url(), '/auth/login/')) {
                return Http::response([
                    'data' => [[
                        'token' => 'rest1-test-token',
                        'expirationTime' => time() + 3600,
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), '/order/get')) {
                $orderRequests[] = $request->data();

                return Http::response(['Data' => [$row]], 200);
            }

            return Http::response([], 200);
        });
    }

    /** @return array<string,string> */
    private function collectedBuyer(): array
    {
        return [
            'code' => 'ts_guest874',
            'id' => '4041',
            'name' => 'Keith Morgan',
            'email' => 'keith@example.test',
            'phone' => '+447967665295',
            'tax' => '',
            'city' => 'Kent',
            'address' => '88 bexley road, Erith, Kent, İngiltere - GB, Da83sp',
        ];
    }

    public function test_guest_buyer_full_details_come_from_nested_invoice_address(): void
    {
        $requests = [];
        $this->fakeRest1($requests);

        $driver = new LegacyRest1Driver(1, 'https://autokeystore.com', 'rest-user', 'rest-pass');
        $orders = $driver->fetchOrders();
        $buyers = $driver->orderBuyers();

        $this->assertCount(1, $orders);
        $this->assertCount(1, $buyers);
        $this->assertSame('Keith Morgan', $buyers[0]['name']);
        $this->assertSame('88 bexley road, Erith, Kent, İngiltere - GB, Da83sp', $buyers[0]['address']);
        $this->assertSame('+447967665295', $buyers[0]['phone']);
        $this->assertSame('keith@example.test', $buyers[0]['email']);
        $this->assertSame('Kent', $buyers[0]['city']);
        $this->assertSame('ts_guest874', $buyers[0]['code']);

        // Kişisel veri kanonik DTO'ya veya raw'a sızmaz.
        $serialized = (string) json_encode([$orders[0]->toArray(), $orders[0]->raw], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Keith Morgan', $serialized);
        $this->assertStringNotContainsString('88 bexley road', $serialized);
    }

    public function test_all_order_get_builders_send_all_four_fetch_flags(): void
    {
        $requests = [];
        $this->fakeRest1($requests);

        $driver = new LegacyRest1Driver(1, 'https://autokeystore.com', 'rest-user', 'rest-pass');
        $driver->fetchOrders();
        $driver->fetchAllOrders(600);
        $driver->fetchOrder('AK221114874');

        $this->assertGreaterThanOrEqual(3, count($requests));
        foreach ($requests as $data) {
            foreach ([
                'FetchProductData', 'FetchCustomerData', 'FetchInvoiceAddress', 'FetchDeliveryAddress',
            ] as $flag) {
                $this->assertSame(1, (int) ($data[$flag] ?? 0), $flag.' anahtarı eksik.');
            }
        }
    }

    public function test_order_buyer_sync_creates_party_with_full_details_and_id_maps(): void
    {
        $ctx = $this->register('bridge-buyer@firma.test', 'Bridge Buyer');
        $companyId = $ctx['company_id'];

        $sync = new OrderBuyerSync;
        $this->assertSame(1, $sync->sync($companyId, [$this->collectedBuyer()]));
        $this->assertSame([], $sync->errors());

        $party = Party::query()
            ->where('company_id', $companyId)
            ->where('code', 'ts_guest874')
            ->first();
        $this->assertNotNull($party);
        $this->assertSame('Keith Morgan', $party->name);
        $this->assertSame('88 bexley road, Erith, Kent, İngiltere - GB, Da83sp', $party->address);

        // Hem opak code hem sayısal id eşlenir.
        $this->assertSame(2, IntegrationIdMap::query()
            ->where('company_id', $companyId)
            ->where('mrc_type', 'party')
            ->where('external_type', 'customer')
            ->whereIn('external_key', ['ts_guest874', '4041'])
            ->count());
    }

    public function test_process_tsoft_order_job_ingests_document_idempotently(): void
    {
        $ctx = $this->register('bridge-order@firma.test', 'Bridge Order');
        $companyId = $ctx['company_id'];

        // Köprü: alıcı cariyi sipariş ingest'inden önce kurar.
        (new OrderBuyerSync)->sync($companyId, [$this->collectedBuyer()]);

        $order = TsoftMapper::order($this->guestOrderRow());
        $job = new ProcessTsoftOrder(
            $companyId,
            $order->externalRef,
            $order->toArray(),
            $order->raw,
        );

        $job->handle();
        $job->handle();

        $this->assertSame(1, Document::query()->where('company_id', $companyId)->count());
        $document = Document::query()->where('company_id', $companyId)->first();
        $this->assertNotNull($document);
        $this->assertSame('AK221114874', $document->external_no);
    }
}
