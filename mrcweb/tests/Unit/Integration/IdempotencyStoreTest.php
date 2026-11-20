<?php

namespace Tests\Unit\Integration;

use App\Integration\Outbox\IdempotencyStore;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * Idempotency: aynı anahtar bir kez işlenir; başarısız işlem önbelleğe yazılmaz.
 */
class IdempotencyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::store('array')->flush();
    }

    public function test_ayni_anahtar_callable_i_bir_kez_calistirir(): void
    {
        $store = new IdempotencyStore;
        $calls = 0;

        $operation = function () use (&$calls) {
            $calls++;

            return ['ok' => true];
        };

        $this->assertSame(['ok' => true], $store->remember('islem-1', $operation));
        $this->assertSame(['ok' => true], $store->remember('islem-1', $operation));
        $this->assertSame(1, $calls);
        $this->assertTrue($store->exists('islem-1'));
    }

    public function test_farkli_anahtarlar_callable_i_iki_kez_calistirir(): void
    {
        $store = new IdempotencyStore;
        $calls = 0;

        $operation = function () use (&$calls) {
            $calls++;

            return $calls;
        };

        $this->assertSame(1, $store->remember('islem-a', $operation));
        $this->assertSame(2, $store->remember('islem-b', $operation));
        $this->assertSame(2, $calls);
    }

    public function test_istisna_önbellege_yazilmaz_ve_yeniden_firlatilir(): void
    {
        $store = new IdempotencyStore;

        try {
            $store->remember('hata', function () {
                throw new RuntimeException('bozuk islem');
            });
            $this->fail('İstisna fırlatılmalıydı.');
        } catch (RuntimeException $e) {
            $this->assertSame('bozuk islem', $e->getMessage());
        }

        $this->assertFalse($store->exists('hata'));

        $calls = 0;
        $operation = function () use (&$calls) {
            $calls++;

            return 'tamam';
        };

        $this->assertSame('tamam', $store->remember('hata', $operation));
        $this->assertSame(1, $calls);
    }
}
