<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return ['response' => $response, 'token' => $response->json('token')];
    }

    protected function headersFrom(array $registration): array
    {
        return ['Authorization' => "Bearer {$registration['token']}"];
    }

    protected function createParty(array $headers, string $name, array $overrides = []): array
    {
        return $this->apiPost('/api/v1/parties', array_merge(['name' => $name], $overrides), $headers)
            ->assertCreated()->json('party');
    }

    protected function createProduct(array $headers, array $overrides = []): array
    {
        return $this->apiPost('/api/v1/products', array_merge([
            'name' => 'Test Ürünü',
            'cost_price' => 50,
            'sale_price' => 100,
            'tax_rate' => 20,
        ], $overrides), $headers)->assertCreated()->json('product');
    }

    protected function createDocument(array $headers, int $partyId, string $type, array $items, array $overrides = []): array
    {
        return $this->apiPost('/api/v1/documents', array_merge([
            'type' => $type,
            'party_id' => $partyId,
            'items' => $items,
        ], $overrides), $headers)->assertCreated()->json('document');
    }

    protected function createIssuedDocument(array $headers, int $partyId, string $type, array $items, array $overrides = []): array
    {
        $doc = $this->createDocument($headers, $partyId, $type, $items, $overrides);
        $this->apiPost("/api/v1/documents/{$doc['id']}/status", ['status' => 'issued'], $headers)->assertOk();

        return $doc;
    }

    protected function defaultWarehouseId(array $headers): int
    {
        return (int) $this->apiGet('/api/v1/warehouses', $headers)->assertOk()->json('warehouses.0.id');
    }

    public function test_satis_ozeti_ve_kar_hesabi(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers, 'Müşteri Ltd')['id'];

        $pa = $this->createProduct($headers, ['name' => 'Ürün A', 'cost_price' => 60, 'sale_price' => 100, 'tax_rate' => 20]);
        $pb = $this->createProduct($headers, ['name' => 'Ürün B', 'cost_price' => 30, 'sale_price' => 50, 'tax_rate' => 10]);

        // (a) 10×100 −%10 +KDV%20 → net 900, KDV 180, brüt 1080 ; maliyet 10 × 60 = 600
        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['product_id' => $pa['id'], 'quantity' => 10, 'unit_price' => 100, 'discount_rate' => 10],
        ]);
        // (b) 2×50 +KDV%10 → net 100, KDV 10, brüt 110 ; maliyet 2 × 30 = 60
        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['product_id' => $pb['id'], 'quantity' => 2, 'unit_price' => 50],
        ]);
        // taslak dev fatura: hiçbir rapora girmemeli
        $this->createDocument($headers, $partyId, 'invoice', [
            ['name' => 'Taslak Kalem', 'quantity' => 100, 'unit_price' => 1000],
        ]);

        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');
        $res = $this->apiGet(
            "/api/v1/reports/sales-summary?group_by=month&type=invoice&from={$monthStart}&to={$today}",
            $headers
        )->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $row = $data[0];
        $this->assertSame(substr($today, 0, 7), $row['key']);
        $this->assertSame($row['key'], $row['label']);
        $this->assertArrayHasKey('month_name', $row);
        $this->assertSame(2, $row['document_count']);
        $this->assertSame(1190.0, round((float) $row['gross_total'], 4));
        $this->assertSame(1000.0, round((float) $row['net_total'], 4));
        $this->assertSame(100.0, round((float) $row['discount_total'], 4));
        $this->assertSame(190.0, round((float) $row['tax_total'], 4));
        // cogs = birim maliyet × miktar: 10×60 + 2×30 = 660
        $this->assertSame(660.0, round((float) $row['cogs_total'], 4));
        $this->assertSame(340.0, round((float) $row['profit'], 4));
        $this->assertSame('TRY', $row['currency_code']);

        $totals = $res->json('totals');
        $this->assertSame(1190.0, round((float) $totals['gross_total'], 4));
        $this->assertSame(1000.0, round((float) $totals['net_total'], 4));
        $this->assertSame(340.0, round((float) $totals['profit'], 4));
        $this->assertSame(0, $totals['cogs_missing_rows']);
        $this->assertSame(1000.0, round((float) $res->json('by_currency.TRY.net_total'), 4));
        $this->assertSame(
            'Tutarlar para birimi bazında ayrıdır; para birimi çevirimi sonraki bir sürümde eklenecektir.',
            $res->json('meta.currency_note')
        );

        // Ürün bağlantısız, issued serbest satır (önceki ay): cogs NULL → eksik sayılır.
        $prevMonthDay = date('Y-m-d', strtotime($monthStart.' -1 day'));
        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['name' => 'Nakliye', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0],
        ], ['issue_date' => $prevMonthDay]);

        $wide = $this->apiGet(
            "/api/v1/reports/sales-summary?group_by=month&type=invoice&from={$prevMonthDay}&to={$today}",
            $headers
        )->assertOk();

        $this->assertSame(1, $wide->json('meta.cogs_missing_rows'));
        $this->assertSame(1, $wide->json('totals.cogs_missing_rows'));
        // net 1000+200, cogs yine 660 (bağlantısız satırın maliyeti NULL → 0 sayılır) → kâr 540
        $this->assertSame(1200.0, round((float) $wide->json('totals.net_total'), 4));
        $this->assertSame(660.0, round((float) $wide->json('totals.cogs_total'), 4));
        $this->assertSame(540.0, round((float) $wide->json('totals.profit'), 4));
        $this->assertSame(1390.0, round((float) $wide->json('totals.gross_total'), 4));
    }

    public function test_kdv_ozeti_oranlara_gore_agrue(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers, 'Müşteri Ltd')['id'];

        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['name' => 'Kalem A', 'quantity' => 10, 'unit_price' => 100, 'discount_rate' => 10, 'tax_rate' => 20],
            ['name' => 'Kalem B', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 10],
        ]);

        $res = $this->apiGet('/api/v1/reports/tax-summary?type=invoice', $headers)->assertOk();
        $data = $res->json('data');
        $this->assertCount(2, $data);

        $byRate = [];
        foreach ($data as $row) {
            $byRate[(string) $row['tax_rate']] = $row;
        }

        $this->assertSame(900.0, round((float) $byRate['20']['base_total'], 4));
        $this->assertSame(180.0, round((float) $byRate['20']['tax_total'], 4));
        $this->assertSame(1080.0, round((float) $byRate['20']['gross_total'], 4));
        $this->assertSame(1, $byRate['20']['line_count']);

        $this->assertSame(100.0, round((float) $byRate['10']['base_total'], 4));
        $this->assertSame(10.0, round((float) $byRate['10']['tax_total'], 4));
        $this->assertSame(110.0, round((float) $byRate['10']['gross_total'], 4));

        $this->assertSame(1000.0, round((float) $res->json('totals.base_total'), 4));
        $this->assertSame(190.0, round((float) $res->json('totals.tax_total'), 4));
        $this->assertSame(1190.0, round((float) $res->json('totals.gross_total'), 4));
    }

    public function test_cari_bakiye_listesi_sirali_ve_tasinabilir(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        $custA = $this->createParty($headers, 'Müşteri A');
        $future = date('Y-m-d', strtotime('+5 days'));
        $this->createIssuedDocument($headers, (int) $custA['id'], 'invoice', [
            ['name' => 'Hizmet', 'quantity' => 1, 'unit_price' => 700, 'tax_rate' => 0],
        ], ['due_date' => $future]);

        $supplier = $this->createParty($headers, 'Tedarikçi S', ['type' => 'supplier']);
        $purchase = $this->createIssuedDocument($headers, (int) $supplier['id'], 'purchase', [
            ['name' => 'Hammadde', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 0],
        ]);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $supplier['id'], 'direction' => 'out', 'amount' => 200,
            'document_id' => $purchase['id'],
        ], $headers)->assertCreated();

        $res = $this->apiGet('/api/v1/reports/party-balances?sort=balance_desc', $headers)->assertOk();
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('Müşteri A', $data[0]['name']);
        $this->assertSame(700.0, round((float) $data[0]['balance'], 4));
        $this->assertSame(700.0, round((float) $data[0]['receivable'], 4));
        $this->assertSame(0.0, round((float) $data[0]['payable'], 4));
        $this->assertSame(1, $data[0]['open_document_count']);
        $this->assertSame($future, $data[0]['oldest_due_date']);
        $this->assertSame(700.0, round((float) $data[0]['aging']['not_due'], 4));

        $this->assertSame('Tedarikçi S', $data[1]['name']);
        $this->assertSame(-300.0, round((float) $data[1]['balance'], 4));
        $this->assertSame(300.0, round((float) $data[1]['payable'], 4));

        $this->assertSame(700.0, round((float) $res->json('totals.receivable_sum'), 4));
        $this->assertSame(300.0, round((float) $res->json('totals.payable_sum'), 4));
        $this->assertSame(400.0, round((float) $res->json('totals.net_balance'), 4));

        // paginateJson sözleşmesi
        $this->assertSame(2, $res->json('meta.total'));
        $paged = $this->apiGet('/api/v1/reports/party-balances?sort=balance_desc&per_page=1&page=2', $headers)->assertOk();
        $this->assertCount(1, $paged->json('data'));
        $this->assertSame(2, $paged->json('meta.total'));
        $this->assertSame('Tedarikçi S', $paged->json('data.0.name'));

        // arama
        $searched = $this->apiGet('/api/v1/reports/party-balances?search=Müşteri', $headers)->assertOk();
        $this->assertSame(1, $searched->json('meta.total'));

        // servis tutarlılığı: satır bakiyesi tekil uçla aynı
        $single = $this->apiGet("/api/v1/parties/{$custA['id']}/balance", $headers)->assertOk();
        $this->assertSame(
            round((float) $single->json('totals.balance'), 4),
            round((float) $data[0]['balance'], 4)
        );
    }

    public function test_alacak_yaslandirma_kutulari(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $party = $this->createParty($headers, 'Müşteri V');

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $future = date('Y-m-d', strtotime('+10 days'));
        $old = date('Y-m-d', strtotime('-45 days'));

        $this->createIssuedDocument($headers, (int) $party['id'], 'invoice', [
            ['name' => 'V1', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 0],
        ], ['due_date' => $yesterday]);
        $this->createIssuedDocument($headers, (int) $party['id'], 'invoice', [
            ['name' => 'V2', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0],
        ], ['due_date' => $future]);
        $inv3 = $this->createIssuedDocument($headers, (int) $party['id'], 'invoice', [
            ['name' => 'V3', 'quantity' => 1, 'unit_price' => 400, 'tax_rate' => 0],
        ], ['due_date' => $old]);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $party['id'], 'direction' => 'in', 'amount' => 100,
            'document_id' => $inv3['id'],
        ], $headers)->assertCreated();

        $res = $this->apiGet('/api/v1/reports/receivables-aging', $headers)->assertOk();
        $buckets = $res->json('buckets');
        $this->assertSame(500.0, round((float) $buckets['days_1_30'], 4));
        $this->assertSame(300.0, round((float) $buckets['not_due'], 4));
        $this->assertSame(300.0, round((float) $buckets['days_31_60'], 4));
        $this->assertSame(0.0, round((float) $buckets['days_61_90'], 4));
        $this->assertSame(0.0, round((float) $buckets['days_over_90'], 4));
        $this->assertSame(1100.0, round((float) $res->json('total_open'), 4));
        $this->assertSame(3, $res->json('document_count'));
        $this->assertSame(1100.0, round((float) $res->json('by_currency.TRY'), 4));

        $top = $res->json('top_parties');
        $this->assertCount(1, $top);
        $this->assertSame(1100.0, round((float) $top[0]['open_total'], 4));
        $this->assertSame($old, $top[0]['oldest_due_date']);

        // PartyBalance servisiyle tutarlılık (aynı cari, varsayılan as_of=bugün)
        $single = $this->apiGet("/api/v1/parties/{$party['id']}/balance", $headers)->assertOk();
        foreach (['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'] as $k) {
            $this->assertSame(
                round((float) $single->json("aging.{$k}"), 4),
                round((float) $buckets[$k], 4),
                "kova {$k} uyuşmuyor"
            );
        }

        // Borç tarafı: tedarikçi alım faturası + kısmi ödeme
        $supplier = $this->createParty($headers, 'Tedarikçi B', ['type' => 'supplier']);
        $purchase = $this->createIssuedDocument($headers, (int) $supplier['id'], 'purchase', [
            ['name' => 'Mal', 'quantity' => 1, 'unit_price' => 600, 'tax_rate' => 0],
        ], ['due_date' => $yesterday]);
        $this->apiPost('/api/v1/payments', [
            'party_id' => $supplier['id'], 'direction' => 'out', 'amount' => 100,
            'document_id' => $purchase['id'],
        ], $headers)->assertCreated();

        $pay = $this->apiGet('/api/v1/reports/payables-aging', $headers)->assertOk();
        $this->assertSame(500.0, round((float) $pay->json('buckets.days_1_30'), 4));
        $this->assertSame(500.0, round((float) $pay->json('total_open'), 4));
        $this->assertSame(1, $pay->json('document_count'));
    }

    public function test_stok_degeri_raporu(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $warehouseId = $this->defaultWarehouseId($headers);

        $tracked = $this->createProduct($headers, [
            'name' => 'Stoklu Ürün', 'cost_price' => 30, 'sale_price' => 50, 'min_stock' => 10,
        ]);
        $this->apiPost('/api/v1/stock-movements', [
            'product_id' => $tracked['id'], 'warehouse_id' => $warehouseId, 'type' => 'in', 'quantity' => 7,
        ], $headers)->assertCreated();

        $untracked = $this->createProduct($headers, ['name' => 'Hizmet Ürünü', 'track_inventory' => false]);

        $res = $this->apiGet('/api/v1/reports/stock-valuation', $headers)->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);

        $row = $data[0];
        $this->assertSame((int) $tracked['id'], $row['product_id']);
        $this->assertSame(7.0, round((float) $row['quantity'], 4));
        $this->assertSame(30.0, round((float) $row['avg_cost'], 4));
        $this->assertSame(210.0, round((float) $row['stock_value'], 4));
        $this->assertSame(350.0, round((float) $row['sale_value'], 4));
        $this->assertTrue($row['critical']);

        // arayüz „satış fiyatı / min. stok / durum” sütunlarını bu iki alandan kurar
        $this->assertSame(50.0, round((float) $row['sale_price'], 4));
        $this->assertSame(10.0, round((float) $row['min_stock'], 4));

        $this->assertSame(1, $res->json('totals.product_count'));
        $this->assertSame(210.0, round((float) $res->json('totals.stock_value'), 4));
        $this->assertSame(350.0, round((float) $res->json('totals.sale_value'), 4));
        $this->assertSame(1, $res->json('totals.critical_count'));

        // takipsiz ürün raporda yok
        foreach ($data as $r) {
            $this->assertNotSame((int) $untracked['id'], $r['product_id']);
        }

        // depo filtresi aynı sonucu verir
        $filtered = $this->apiGet("/api/v1/reports/stock-valuation?warehouse_id={$warehouseId}", $headers)->assertOk();
        $this->assertCount(1, $filtered->json('data'));
    }

    public function test_stok_hareket_ozeti(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $warehouseId = $this->defaultWarehouseId($headers);
        $product = $this->createProduct($headers, ['name' => 'Hareket Ürünü']);

        $moves = [
            ['type' => 'in', 'quantity' => 5, 'unit_cost' => 10],
            ['type' => 'in', 'quantity' => 3, 'unit_cost' => 10],
            ['type' => 'out', 'quantity' => 4, 'unit_cost' => 10],
            ['type' => 'adjust', 'quantity' => -1],
        ];
        foreach ($moves as $m) {
            $this->apiPost('/api/v1/stock-movements', array_merge([
                'product_id' => $product['id'], 'warehouse_id' => $warehouseId,
            ], $m), $headers)->assertCreated();
        }

        $res = $this->apiGet('/api/v1/reports/stock-movements-summary?group_by=type', $headers)->assertOk();
        $byKey = [];
        foreach ($res->json('data') as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertSame(8.0, round((float) $byKey['in']['in_qty'], 4));
        $this->assertSame(4.0, round((float) $byKey['out']['out_qty'], 4));
        $this->assertSame(-1.0, round((float) $byKey['adjust']['adjust_qty'], 4));
        $this->assertSame(80.0, round((float) $byKey['in']['in_value'], 4));
        $this->assertSame(40.0, round((float) $byKey['out']['out_value'], 4));

        $totals = $res->json('totals');
        $this->assertSame(8.0, round((float) $totals['in_qty'], 4));
        $this->assertSame(4.0, round((float) $totals['out_qty'], 4));
        $this->assertSame(-1.0, round((float) $totals['adjust_qty'], 4));
        $this->assertSame(3.0, round((float) $totals['net_qty'], 4));
        $this->assertSame(4, $totals['movement_count']);

        // para birimi kırılımı: hareketin kendi para birimi kolonu yok → ürünün para birimi.
        // (Çıkış tutarı maliyet temellidir; çıkışın birim maliyeti ihraç anı ortalamasıdır.)
        $this->assertSame(80.0, round((float) $res->json('by_currency.TRY.in_value'), 4));
        $this->assertSame(40.0, round((float) $res->json('by_currency.TRY.out_value'), 4));
        $this->assertSame(4, $res->json('by_currency.TRY.movement_count'));

        // ürün kırılımı tek satır, net 3
        $byProduct = $this->apiGet(
            "/api/v1/reports/stock-movements-summary?group_by=product&product_id={$product['id']}",
            $headers
        )->assertOk();
        $this->assertCount(1, $byProduct->json('data'));
        $this->assertSame(3.0, round((float) $byProduct->json('data.0.net_qty'), 4));
    }

    public function test_stok_hareket_ozeti_gruplamalar_arasi_toplamlar_tutarli(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $warehouseId = $this->defaultWarehouseId($headers);
        $other = $this->apiPost('/api/v1/warehouses', ['name' => 'Şube Depo'], $headers)
            ->assertCreated()->json('warehouse.id');

        $first = $this->createProduct($headers, ['name' => 'Ürün Bir']);
        $second = $this->createProduct($headers, ['name' => 'Ürün Iki']);

        $this->apiPost('/api/v1/stock-movements', ['product_id' => $first['id'], 'warehouse_id' => $warehouseId, 'type' => 'in', 'quantity' => 7, 'unit_cost' => 4], $headers)->assertCreated();
        $this->apiPost('/api/v1/stock-movements', ['product_id' => $second['id'], 'warehouse_id' => $other, 'type' => 'in', 'quantity' => 2, 'unit_cost' => 5], $headers)->assertCreated();
        $this->apiPost('/api/v1/stock-movements', ['product_id' => $first['id'], 'warehouse_id' => $other, 'type' => 'out', 'quantity' => 3, 'unit_cost' => 4], $headers)->assertCreated();

        $expected = ['in_qty' => 9.0, 'out_qty' => 3.0, 'net_qty' => 6.0, 'movement_count' => 3];

        // Rapor ekranları hangi kırılımla gösterirse göstersin aynı toplamı görmeli:
        // ürün/depo kırılımları inner join ile satır kaybederse bu değişmez kırılır.
        foreach (['type', 'product', 'warehouse', 'day'] as $groupBy) {
            $res = $this->apiGet("/api/v1/reports/stock-movements-summary?group_by={$groupBy}", $headers)->assertOk();
            $totals = $res->json('totals');

            $this->assertSame($expected['in_qty'], round((float) $totals['in_qty'], 4), "group_by={$groupBy} in_qty");
            $this->assertSame($expected['out_qty'], round((float) $totals['out_qty'], 4), "group_by={$groupBy} out_qty");
            $this->assertSame($expected['net_qty'], round((float) $totals['net_qty'], 4), "group_by={$groupBy} net_qty");
            $this->assertSame($expected['movement_count'], $totals['movement_count'], "group_by={$groupBy} hareket sayısı");

            $rowSum = array_sum(array_column($res->json('data'), 'net_qty'));
            $this->assertSame($expected['net_qty'], round((float) $rowSum, 4), "group_by={$groupBy} satır toplamı");
        }

        $byProduct = $this->apiGet('/api/v1/reports/stock-movements-summary?group_by=product', $headers)->assertOk();
        $this->assertCount(2, $byProduct->json('data'));
        // ürün id'leri tamsayı gibi sıralanır ("10" < "2" sözel sıralaması olmaz)
        $keys = array_column($byProduct->json('data'), 'key');
        $this->assertSame([$first['id'], $second['id']], $keys);
        $this->assertContains($first['name'], array_column($byProduct->json('data'), 'label'));

        $byWarehouse = $this->apiGet('/api/v1/reports/stock-movements-summary?group_by=warehouse', $headers)->assertOk();
        $this->assertCount(2, $byWarehouse->json('data'));
        $this->assertSame('Şube Depo', $byWarehouse->json('data.1.label'));
    }

    public function test_bos_haritalar_json_nesnesi_olarak_donur(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);

        // veri yoksa 200 + boş listeler, ancak by_currency DİZİ değil NESNE olmalı:
        // SPA tek tip okuma yapabilsin (dolu hâli {"TRY":{...}}).
        $listEndpoints = [
            '/api/v1/reports/sales-summary',
            '/api/v1/reports/tax-summary',
            '/api/v1/reports/stock-valuation',
            '/api/v1/reports/stock-movements-summary',
        ];
        $bucketEndpoints = [
            '/api/v1/reports/receivables-aging',
            '/api/v1/reports/payables-aging',
        ];

        foreach ($listEndpoints as $uri) {
            $res = $this->apiGet($uri, $headers)->assertOk();
            $this->assertSame([], $res->json('data'), $uri);
            $this->assertStringContainsString('"by_currency":{}', $res->getContent(), "{$uri} boş harita {} olmalı");
        }

        foreach ($bucketEndpoints as $uri) {
            $res = $this->apiGet($uri, $headers)->assertOk();
            $this->assertSame(0.0, (float) $res->json('total_open'), $uri);
            $this->assertSame([], $res->json('top_parties'), $uri);
            $this->assertSame(0, $res->json('buckets.not_due'), $uri);
            $this->assertStringContainsString('"by_currency":{}', $res->getContent(), "{$uri} boş harita {} olmalı");
        }

        // cari bakiye ucu da aynı sözleşmeyi verir (bu uç TRY kovasını sıfırla hazırlar)
        $partyId = $this->apiPost('/api/v1/parties', ['name' => 'Boş Cari'], $headers)
            ->assertCreated()->json('party.id');
        $balance = $this->apiGet("/api/v1/parties/{$partyId}/balance", $headers)->assertOk();
        $this->assertSame(0.0, (float) $balance->json('totals.balance'));
        $this->assertSame(0.0, (float) $balance->json('by_currency.TRY.balance'));
        $this->assertStringContainsString('"by_currency":{', $balance->getContent());
        $this->assertStringNotContainsString('"by_currency":[', $balance->getContent());
    }

    public function test_rapor_tenant_izolasyonu(): void
    {
        $a = $this->registerUser(['company_name' => 'A']);
        $headersA = $this->headersFrom($a);
        $partyId = $this->createParty($headersA, 'Müşteri')['id'];
        $this->createIssuedDocument($headersA, $partyId, 'invoice', [
            ['name' => 'Hizmet', 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => 0],
        ]);

        $b = $this->registerUser(['email' => 'sahip-b@firma-b.test', 'company_name' => 'B']);
        $headersB = $this->headersFrom($b);

        $sales = $this->apiGet('/api/v1/reports/sales-summary', $headersB)->assertOk();
        $this->assertSame([], $sales->json('data'));
        $this->assertSame(0.0, round((float) $sales->json('totals.gross_total'), 4));

        $tax = $this->apiGet('/api/v1/reports/tax-summary', $headersB)->assertOk();
        $this->assertSame([], $tax->json('data'));
        $this->assertSame(0.0, round((float) $tax->json('totals.gross_total'), 4));

        $balances = $this->apiGet('/api/v1/reports/party-balances', $headersB)->assertOk();
        $this->assertSame([], $balances->json('data'));
        $this->assertSame(0, $balances->json('meta.total'));
        $this->assertSame(0.0, round((float) $balances->json('totals.net_balance'), 4));

        foreach (['receivables-aging', 'payables-aging'] as $endpoint) {
            $aging = $this->apiGet("/api/v1/reports/{$endpoint}", $headersB)->assertOk();
            $this->assertSame(0.0, round((float) $aging->json('total_open'), 4));
            $this->assertSame(0, $aging->json('document_count'));
            $this->assertSame([], $aging->json('top_parties'));
            foreach ($aging->json('buckets') as $v) {
                $this->assertSame(0.0, round((float) $v, 4));
            }
        }

        $valuation = $this->apiGet('/api/v1/reports/stock-valuation', $headersB)->assertOk();
        $this->assertSame([], $valuation->json('data'));
        $this->assertSame(0, $valuation->json('totals.product_count'));

        $moves = $this->apiGet('/api/v1/reports/stock-movements-summary', $headersB)->assertOk();
        $this->assertSame([], $moves->json('data'));
        $this->assertSame(0.0, round((float) $moves->json('totals.net_qty'), 4));
        $this->assertSame(0, $moves->json('totals.movement_count'));
    }

    public function test_rapor_tarih_araligi_dahil_ve_haric(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers, 'Müşteri Ltd')['id'];

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['name' => 'Bugün', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
        ], ['issue_date' => $today]);
        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['name' => 'Yarın', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0],
        ], ['issue_date' => $tomorrow]);

        // to sınırı dahil: bugünkü fatura sayılır
        $inRange = $this->apiGet("/api/v1/reports/sales-summary?type=invoice&from={$today}&to={$today}", $headers)->assertOk();
        $this->assertSame(100.0, round((float) $inRange->json('totals.gross_total'), 4));

        // yarın varsayılan aralık dışında
        $default = $this->apiGet('/api/v1/reports/sales-summary?type=invoice', $headers)->assertOk();
        $this->assertSame(100.0, round((float) $default->json('totals.gross_total'), 4));

        // to genişletilince ikisi de sayılır
        $wide = $this->apiGet("/api/v1/reports/sales-summary?type=invoice&from={$today}&to={$tomorrow}", $headers)->assertOk();
        $this->assertSame(300.0, round((float) $wide->json('totals.gross_total'), 4));

        // tamamen boş aralık: 200 + boş veri + sıfır toplamlar
        $empty = $this->apiGet('/api/v1/reports/sales-summary?type=invoice&from=2100-01-01&to=2100-01-31', $headers)->assertOk();
        $this->assertSame([], $empty->json('data'));
        $this->assertSame(0.0, round((float) $empty->json('totals.gross_total'), 4));
        $this->assertSame(0, $empty->json('totals.document_count'));
    }

    public function test_rapor_ingilizce_ceviri(): void
    {
        $owner = $this->registerUser();
        $headers = $this->headersFrom($owner);
        $partyId = $this->createParty($headers, 'Müşteri Ltd')['id'];
        $product = $this->createProduct($headers, ['name' => 'Ürün X']);
        $category = $this->apiPost('/api/v1/categories', ['name' => 'Elektronik'], $headers)
            ->assertCreated()->json('category');
        $this->apiPatch("/api/v1/products/{$product['id']}", ['category_id' => $category['id']], $headers)->assertOk();

        $this->createIssuedDocument($headers, $partyId, 'invoice', [
            ['product_id' => $product['id'], 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
            ['name' => 'Serbest Satır', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0],
        ]);

        $tr = $this->apiGet('/api/v1/reports/sales-summary?group_by=category', $headers)->assertOk();
        $trLabels = collect($tr->json('data'))->pluck('label')->all();
        $this->assertContains('Bağlantısız', $trLabels);
        $this->assertContains('Elektronik', $trLabels);

        $this->apiPatch('/api/v1/me/locale', ['locale' => 'en'], $headers)->assertOk();

        $en = $this->apiGet('/api/v1/reports/sales-summary?group_by=category', $headers)->assertOk();
        $enLabels = collect($en->json('data'))->pluck('label')->all();
        $this->assertContains('Unlinked', $enLabels);
        $this->assertNotContains('Bağlantısız', $enLabels);
        $this->assertSame(
            'Amounts are reported per currency; currency conversion will be added in a later release.',
            $en->json('meta.currency_note')
        );
    }
}
