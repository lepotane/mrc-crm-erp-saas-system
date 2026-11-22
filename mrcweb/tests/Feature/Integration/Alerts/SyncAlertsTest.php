<?php

namespace Tests\Feature\Integration\Alerts;

use App\Dashboard\WidgetRegistry;
use App\Integration\Alerts\SyncAlertService;
use App\Integration\Sync\SyncLog;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * Telegram hata bildirimleri (kapalıyken sessiz) ve pano senkron sağlığı verisi.
 * Gerçek token/chat kullanılmaz; yalnızca sahte değerler.
 */
final class SyncAlertsTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'alerts.enabled' => false,
            'alerts.token' => 'dummy-token',
            'alerts.chat' => 'dummy-chat',
            'alerts.min_interval_minutes' => 15,
        ]);

        Http::fake(['*' => Http::response([], 200)]);
    }

    public function test_notify_gondermez_when_disabled(): void
    {
        config(['alerts.enabled' => false]);

        app(SyncAlertService::class)->notify('catalog-pull:1', ['errors' => 2], 'hata');

        Http::assertNothingSent();
    }

    public function test_notify_gonderir_bir_post_when_enabled(): void
    {
        config(['alerts.enabled' => true]);

        app(SyncAlertService::class)->notify('catalog-pull:1', [
            'products' => 0,
            'errors' => 2,
        ], 'cek basarisiz');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            // Form gövdesi URL-kodlu olduğu için çözülmüş veriyi kullan.
            $text = (string) ($request['text'] ?? '');

            return $request->method() === 'POST'
                && $request->url() === 'https://tg.ortaklarmotors.com/telegram-bridge.php'
                && isset($request['token'], $request['chat'])
                && str_contains($text, 'catalog-pull:1')
                && str_contains($text, 'products:0')
                && str_contains($text, 'errors:2')
                && str_contains($text, 'Hata:');
        });
    }

    public function test_notify_ikinci_cagriyı_ayni_aralikta_gondermez(): void
    {
        config(['alerts.enabled' => true]);

        $service = app(SyncAlertService::class);
        $service->notify('catalog-pull:1', ['errors' => 1]);
        $service->notify('catalog-pull:1', ['errors' => 1]);

        Http::assertSentCount(1);
    }

    public function test_widget_son_sync_log_kayitlarini_dondurur(): void
    {
        $ctx = $this->register('sync-alert@firma.test', 'Sync Alert Firma');
        $companyId = (int) $ctx['company_id'];

        // Aynı varlığın eski kaydı — "son kayıt" seçimini doğrular.
        SyncLog::create([
            'company_id' => $companyId,
            'channel' => 'tsoft',
            'direction' => 'in',
            'entity' => 'catalog',
            'status' => 'success',
            'duration_ms' => 120,
            'preview' => json_encode(['products' => 9, 'errors' => 0]),
            'created_at' => now()->subHours(2),
        ]);

        SyncLog::create([
            'company_id' => $companyId,
            'channel' => 'tsoft',
            'direction' => 'in',
            'entity' => 'catalog',
            'status' => 'failed',
            'duration_ms' => 340,
            'preview' => json_encode(['products' => 0, 'errors' => 2]),
            'created_at' => now()->subMinutes(5),
        ]);

        SyncLog::create([
            'company_id' => $companyId,
            'channel' => 'tsoft',
            'direction' => 'in',
            'entity' => 'mrc_resync',
            'status' => 'success',
            'duration_ms' => 900,
            'preview' => json_encode(['imported' => ['orders' => 3], 'errors' => 0]),
            'created_at' => now()->subMinutes(15),
        ]);

        $entry = WidgetRegistry::catalog()['sync_health'];
        $this->assertSame(['Admin'], $entry['roles']);
        $this->assertSame('half', $entry['size']);
        $this->assertSame([WidgetRegistry::class, 'syncHealth'], $entry['data']);

        $data = WidgetRegistry::data('sync_health', Company::findOrFail($companyId));

        $this->assertSame('error', $data['overall']);
        $this->assertCount(5, $data['items']);

        $byEntity = collect($data['items'])->keyBy('entity');

        $this->assertSame('failed', $byEntity['catalog']['status']);
        $this->assertSame(340, $byEntity['catalog']['duration_ms']);
        $this->assertNotNull($byEntity['catalog']['created_at']);
        $this->assertStringContainsString('errors:2', (string) $byEntity['catalog']['summary']);

        $this->assertSame('success', $byEntity['resync']['status']);
        $this->assertSame('none', $byEntity['orders']['status']);
    }
}
