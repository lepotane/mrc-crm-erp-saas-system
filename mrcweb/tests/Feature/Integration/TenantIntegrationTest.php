<?php

namespace Tests\Feature\Integration;

use App\Integration\Enums\Channel;
use App\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Entegrasyon sırları: DB'de şifreli, arayüzde maskeli tutulur.
 */
class TenantIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    public function test_kimlik_bilgileri_sifreli_ve_maskeli_tutulur(): void
    {
        $ctx = $this->register('integration@firma.test', 'Entegrasyon Firmasi');

        $row = TenantIntegration::create([
            'company_id' => $ctx['company_id'],
            'channel' => Channel::Tsoft->value,
            'driver' => 'rest1',
            'credentials' => [
                'api_key' => 'secret-api-key-987654',
                'shop_url' => 'https://magaza.test',
            ],
            'webhook_secret' => 'whsec_1234567890',
            'status' => TenantIntegration::STATUS_ACTIVE,
        ]);

        // Ham DB satırında düz sır görünmez.
        $raw = DB::table('tenant_integrations')->where('id', $row->id)->first();
        $this->assertStringNotContainsString('secret-api-key-987654', (string) $raw->credentials);
        $this->assertStringNotContainsString('whsec_1234567890', (string) $raw->webhook_secret);

        // Model cast ile okunabilir, kullanılabilir.
        $fresh = TenantIntegration::for($ctx['company_id'], Channel::Tsoft);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->isUsable());
        $this->assertSame('secret-api-key-987654', $fresh->credentials['api_key']);

        // masked() hiçbir tam sırrı döndürmez.
        $masked = $fresh->masked();
        $encoded = json_encode($masked, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('secret-api-key-987654', $encoded);
        $this->assertStringNotContainsString('whsec_1234567890', $encoded);
        $this->assertSame('••••7890', $masked['webhook_secret']);
        $this->assertSame('••••7654', $masked['credentials']['api_key']);
    }
}
