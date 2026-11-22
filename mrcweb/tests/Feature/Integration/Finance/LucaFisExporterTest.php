<?php

namespace Tests\Feature\Integration\Finance;

use App\Integration\Luca\LucaFisExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/** Luca resmi fiş dosyası: 11 kolon, ';' ayırıcı, BOM'lu UTF-8, müşteri verisi yok. */
final class LucaFisExporterTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('local');
    }

    public function test_account_map_configten_okunur(): void
    {
        config()->set('integrations.channels.luca.account_map', [
            'invoice' => ['debit' => '120', 'credit' => '600', 'tax' => '391'],
        ]);

        $this->assertSame([
            'invoice' => ['debit' => '120', 'credit' => '600', 'tax' => '391'],
        ], (new LucaFisExporter)->accountMap());
    }

    public function test_resmi_fis_csv_11_kolon_ve_noktalivirgul_ayiricili(): void
    {
        config()->set('integrations.channels.luca.account_map', [
            'invoice' => ['debit' => '120', 'credit' => '600', 'tax' => '391'],
        ]);

        $ctx = $this->register('fin-luca@firma.test', 'Finans Luca');
        $headers = $ctx['headers'];
        $party = $this->apiPost('/api/v1/parties', [
            'name' => 'Gizli Musteri A.S.',
            'tax_number' => '1234567890',
        ], $headers)->assertCreated()->json('party');

        $product = $this->apiPost('/api/v1/products', [
            'name' => 'Fis Urunu',
            'sku' => 'FIS-SKU-1',
            'cost_price' => 60,
            'sale_price' => 100,
            'tax_rate' => 20,
        ], $headers)->assertCreated()->json('product');

        $doc = $this->apiPost('/api/v1/documents', [
            'type' => 'invoice',
            'party_id' => $party['id'],
            'issue_date' => '2026-11-20',
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'unit_price' => 100]],
        ], $headers)->assertCreated()->json('document');
        $this->apiPost("/api/v1/documents/{$doc['id']}/status", ['status' => 'issued'], $headers)->assertOk();

        $result = (new LucaFisExporter)->export($ctx['company_id'], '2026-11-01', '2026-11-30');

        $this->assertSame('integrations/luca/out/luca-fis-2026-11-01-2026-11-30.csv', $result['path']);
        $this->assertSame(3, $result['rows']);
        $this->assertSame(1, $result['documents']);
        $this->assertSame(0, $result['unmapped_rows']);
        $this->assertTrue(Storage::disk('local')->exists($result['path']));

        $raw = (string) Storage::disk('local')->get($result['path']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw);
        $utf8 = substr($raw, 3);

        $lines = array_values(array_filter(
            preg_split('/\r?\n/', $utf8) ?: [],
            static fn (string $line): bool => trim($line) !== '',
        ));
        $this->assertCount(4, $lines, '1 header + 3 detail rows.');

        $header = str_getcsv($lines[0], ';', '"');
        $this->assertCount(11, $header);
        $this->assertSame('Fiş No', $header[0]);
        $this->assertSame('Hesap Kodu', $header[2]);
        $this->assertSame('Borç', $header[6]);
        $this->assertCount(1, str_getcsv($lines[0], ',', '"'));

        $debit = str_getcsv($lines[1], ';', '"');
        $this->assertCount(11, $debit);
        $this->assertSame('120', $debit[2]);
        $this->assertSame('20.11.2026', $debit[1]);
        $this->assertSame('120,00', $debit[6]);
        $this->assertSame('', $debit[7]);

        $credit = str_getcsv($lines[2], ';', '"');
        $this->assertSame('600', $credit[2]);
        $this->assertSame('100,00', $credit[7]);

        $tax = str_getcsv($lines[3], ';', '"');
        $this->assertSame('391', $tax[2]);
        $this->assertSame('20,00', $tax[7]);

        // Müşteri adı / VKN asla dosyaya girmez.
        $this->assertStringNotContainsString('Gizli Musteri', $utf8);
        $this->assertStringNotContainsString('1234567890', $utf8);
    }
}
