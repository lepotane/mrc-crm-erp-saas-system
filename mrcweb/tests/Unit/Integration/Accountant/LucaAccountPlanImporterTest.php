<?php

namespace Tests\Unit\Integration\Accountant;

use App\Integration\Accountant\LucaAccountPlanImporter;
use Tests\TestCase;

class LucaAccountPlanImporterTest extends TestCase
{
    public function test_it_parses_semicolon_csv_with_turkish_header_in_windows_1254(): void
    {
        $csv = "Kod;Hesap Adı;Üst Hesap;Aktif\n"
            ."CODE-CUST;Müşteriler;;1\n"
            ."CODE-VAT;Hesaplanan KDV;CODE-CUST;evet\n";

        // Gerçek dosyalardaki Windows-1254 içeriğini taklit et.
        $encoded = mb_convert_encoding($csv, 'Windows-1254', 'UTF-8');
        $this->assertFalse(mb_check_encoding($encoded, 'UTF-8'));

        $parsed = (new LucaAccountPlanImporter)->parseCsv($encoded);

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('CODE-CUST', $parsed['rows'][0]['code']);
        $this->assertSame('Müşteriler', $parsed['rows'][0]['name']);
        $this->assertNull($parsed['rows'][0]['parent_code']);
        $this->assertTrue($parsed['rows'][0]['is_active']);
        $this->assertSame('CODE-VAT', $parsed['rows'][1]['code']);
        $this->assertSame('Hesaplanan KDV', $parsed['rows'][1]['name']);
        $this->assertSame('CODE-CUST', $parsed['rows'][1]['parent_code']);
        $this->assertTrue($parsed['rows'][1]['is_active']);
    }

    public function test_it_parses_english_comma_header_and_boolean_flags(): void
    {
        $csv = "code,name,parent_code,is_active\nCODE-EXP,Giderler,,0\n";

        $parsed = (new LucaAccountPlanImporter)->parse($csv, 'csv');

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('CODE-EXP', $parsed['rows'][0]['code']);
        $this->assertSame('Giderler', $parsed['rows'][0]['name']);
        $this->assertFalse($parsed['rows'][0]['is_active']);
    }

    public function test_it_parses_hesap_elements_from_xml_dispatch(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Hesaplar><Hesap><Kod>CODE-SALE</Kod><Ad>Satışlar</Ad>'
            .'<ÜstHesap>CODE-CUST</ÜstHesap><Aktif>1</Aktif></Hesap></Hesaplar>';

        $parsed = (new LucaAccountPlanImporter)->parse($xml, 'xml');

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('CODE-SALE', $parsed['rows'][0]['code']);
        $this->assertSame('Satışlar', $parsed['rows'][0]['name']);
        $this->assertSame('CODE-CUST', $parsed['rows'][0]['parent_code']);
    }

    public function test_malformed_xml_returns_errors_instead_of_throwing(): void
    {
        $parsed = (new LucaAccountPlanImporter)->parseXml('<Hesap><Kod>CODE-X</Kod>');

        $this->assertSame([], $parsed['rows']);
        $this->assertNotSame([], $parsed['errors']);
    }
}
