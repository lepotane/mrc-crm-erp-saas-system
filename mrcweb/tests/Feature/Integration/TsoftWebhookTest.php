<?php

namespace Tests\Feature\Integration;

use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Integration\Support\CredentialVault;
use App\Jobs\Integration\ProcessTsoftWebhook;
use App\Models\TenantIntegration;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

class TsoftWebhookTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    private const SECRET = 'whsec-1234567890abcdef';

    private function integration(int $companyId): TenantIntegration
    {
        return (new CredentialVault)->put(
            $companyId,
            Channel::Tsoft,
            ['clientId' => 'app.test', 'clientSecret' => 'not-a-real-secret'],
            self::SECRET,
            [],
            [],
            Driver::Rest1,
            'shop.test',
            'shop'
        );
    }

    /** @return array<string,string> */
    private function signedHeaders(string $raw, string $eventId, ?string $secret = null): array
    {
        $t = (string) time();
        $secret ??= self::SECRET;
        $v1 = base64_encode(hash_hmac('sha256', $t.'.'.$raw, $secret, true));

        // Symfony istek sunucu parametreleri HTTP_ öneki ister; aksi halde
        // $request->header() bunları göremez.
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TSOFT_STORE' => 'shop.test',
            'HTTP_X_TSOFT_SIGNATURE' => "t={$t},v1={$v1}",
            'HTTP_X_TSOFT_EVENT_ID' => $eventId,
        ];
    }

    public function test_gecerli_imza_webhooku_kuyruga_alar(): void
    {
        Queue::fake();
        $ctx = $this->register('webhook1@firma.test', 'Webhook Firmasi');
        $this->integration($ctx['company_id']);

        $raw = json_encode(['topic' => 'order.created', 'order' => ['id' => '123']], JSON_THROW_ON_ERROR);
        $res = $this->call('POST', '/api/v1/integrations/tsoft/webhook', [], [], [], $this->signedHeaders($raw, 'evt-1'), $raw);

        $res->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertSame(1, WebhookDelivery::where('event_id', 'evt-1')->count());
        Queue::assertPushed(ProcessTsoftWebhook::class);
    }

    public function test_ayni_event_id_ikinci_kez_islenmez(): void
    {
        Queue::fake();
        $ctx = $this->register('webhook2@firma.test', 'Webhook Firmasi 2');
        $this->integration($ctx['company_id']);

        $raw = json_encode(['topic' => 'order.created'], JSON_THROW_ON_ERROR);
        $headers = $this->signedHeaders($raw, 'evt-duplicate');

        $this->call('POST', '/api/v1/integrations/tsoft/webhook', [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', '/api/v1/integrations/tsoft/webhook', [], [], [], $headers, $raw)
            ->assertOk()->assertJsonPath('status', 'duplicate');

        Queue::assertPushed(ProcessTsoftWebhook::class, 1);
    }

    public function test_gecersiz_imza_401_doner_ve_kuyruga_girmez(): void
    {
        Queue::fake();
        $ctx = $this->register('webhook3@firma.test', 'Webhook Firmasi 3');
        $this->integration($ctx['company_id']);

        $raw = '{"topic":"order.created"}';
        $headers = $this->signedHeaders($raw, 'evt-bad', 'wrong-secret-value');
        $headers['HTTP_X_TSOFT_STORE'] = 'shop.test';

        $this->call('POST', '/api/v1/integrations/tsoft/webhook', [], [], [], $headers, $raw)
            ->assertStatus(401);
        $this->assertSame(0, WebhookDelivery::count());
        Queue::assertNothingPushed();
    }
}
