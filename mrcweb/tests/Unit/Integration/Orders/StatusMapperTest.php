<?php

namespace Tests\Unit\Integration\Orders;

use App\Integration\Orders\StatusMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusMapperTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = 991;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('integration_status_map')->insert([
            'company_id' => self::COMPANY_ID,
            'channel' => 'tsoft',
            'external_status' => '42',
            'mrc_status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_tenant_eslemesi_ift_yonde_calisir(): void
    {
        $mapper = new StatusMapper;

        $this->assertSame('confirmed', $mapper->toMrc(self::COMPANY_ID, '42'));
        $this->assertSame('42', $mapper->toExternal(self::COMPANY_ID, 'confirmed'));
    }

    public function test_esleme_yoksa_guvenli_yedek_donulur_ve_uyari_yazilir(): void
    {
        $mapper = new StatusMapper;

        $this->assertSame('draft', $mapper->toMrc(self::COMPANY_ID, 'bilinmeyen'));
        $this->assertNull($mapper->toExternal(self::COMPANY_ID, 'bilinmeyen'));
        $this->assertSame(2, DB::table('sync_logs')->where('status', 'warning')->count());
    }

    public function test_baska_kiracinin_eslemesi_kullanilmaz(): void
    {
        $mapper = new StatusMapper;

        $this->assertSame('draft', $mapper->toMrc(self::COMPANY_ID + 1, '42'));
        $this->assertNull($mapper->toExternal(self::COMPANY_ID + 1, 'confirmed'));
    }
}
