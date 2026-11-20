<?php

namespace Tests\Unit\Integration\Sync;

use App\Integration\Sync\FieldOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class FieldOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_external_name_is_not_applied_when_name_source_is_mrc(): void
    {
        $ctx = $this->register('sahiplik-mrc@firma.test', 'Sahiplik Mrc Firma');
        $ownership = new FieldOwnership;
        $ownership->set($ctx['company_id'], 'product', 'name', 'mrc');

        $result = $ownership->resolveProduct(
            $ctx['company_id'],
            [
                'sku' => 'SKU-1', 'barcode' => 'BARKOD-1',
                'name' => 'MRC Ad', 'description' => 'MRC Aciklama', 'image' => 'mrc.png',
            ],
            [
                'sku' => 'SKU-X', 'barcode' => 'BARKOD-X',
                'name' => 'Tsoft Ad', 'description' => 'Tsoft Aciklama', 'image' => 'tsoft.png',
            ],
        );

        $this->assertSame('MRC Ad', $result['name']);
        $this->assertSame('MRC Aciklama', $result['description']);
        $this->assertSame('mrc', $result['name_source']);
        // Kimlik alanlari sahip bayragindan etkilenmez.
        $this->assertSame('SKU-1', $result['sku']);
        $this->assertSame('BARKOD-1', $result['barcode']);
    }

    public function test_external_name_is_applied_when_name_source_is_tsoft(): void
    {
        $ctx = $this->register('sahiplik-tsoft@firma.test', 'Sahiplik Tsoft Firma');
        $ownership = new FieldOwnership;
        $ownership->set($ctx['company_id'], 'product', 'name', 'tsoft');

        $result = $ownership->resolveProduct(
            $ctx['company_id'],
            ['sku' => 'SKU-2', 'barcode' => 'BARKOD-2', 'name' => 'MRC Ad', 'image' => 'mrc.png'],
            ['sku' => 'SKU-Y', 'barcode' => 'BARKOD-Y', 'name' => 'Tsoft Ad', 'image_url' => 'tsoft.png'],
        );

        $this->assertSame('Tsoft Ad', $result['name']);
        $this->assertSame('tsoft', $result['name_source']);
        $this->assertSame('SKU-2', $result['sku']);
        $this->assertSame('BARKOD-2', $result['barcode']);
    }
}
