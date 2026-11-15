<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 5 — hız sınırları (throttle). Sayılar config/api.php'dedir; testte
 * bilerek küçültülüyor. Sözleşme: 429 + JSON {message} + X-RateLimit-* ve
 * Retry-After başlıkları (istemci beklemeyi bu başlıktan hesaplar).
 *
 * Kilitlenen davranış: kimlik DOĞRULANMAMIŞ istekler de sayaca girer
 * (limiter içinde guard çalıştırılmadığı için AuthenticationException sayacı
 * düşürmüyor — aksi hâlde brute-force throttle dışı kalırdı).
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_giris_denemeleri_sinirlanir_429_json_ve_basliklar(): void
    {
        config(['api.rate_limit.auth' => 2]);

        // Not: test ortamında Symfony isteği varsayılan olarak Accept-Language: en-us
        // gönderir; Türkçe davranışı X-Locale ile sabitliyoruz (gerçek tarayıcıda
        // Accept-Language: tr-TR gelir).
        $headers = ['X-Locale' => 'tr'];
        $payload = ['email' => 'yok@firma-a.test', 'password' => 'parola1234'];

        $this->apiPost('/api/v1/auth/login', $payload, $headers)->assertStatus(422);
        $this->apiPost('/api/v1/auth/login', $payload, $headers)->assertStatus(422);

        $res = $this->apiPost('/api/v1/auth/login', $payload, $headers)
            ->assertStatus(429)
            ->assertJsonStructure(['message']);

        // başlık yok → ürünün ana dili Türkçe
        $this->assertSame('Çok fazla istek gönderdiniz. Lütfen kısa bir süre bekleyip tekrar deneyin.', $res->json('message'));
        $this->assertNotSame('', (string) $res->headers->get('Retry-After'));
        $this->assertSame('2', $res->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $res->headers->get('X-RateLimit-Remaining'));
    }

    public function test_429_mesaji_aktif_dile_gore_cevrilir(): void
    {
        config(['api.rate_limit.auth' => 1]);

        // Türkçe (X-Locale) — uyarı Türkçe okunur
        $this->apiPost('/api/v1/auth/login', ['email' => 'tr@firma-a.test', 'password' => 'parola1234'], ['X-Locale' => 'tr'])
            ->assertStatus(422);
        $this->apiPost('/api/v1/auth/login', ['email' => 'tr@firma-a.test', 'password' => 'parola1234'], ['X-Locale' => 'tr'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Çok fazla istek gönderdiniz. Lütfen kısa bir süre bekleyip tekrar deneyin.');

        // İngilizce (Accept-Language) — aynı uyarı İngilizce
        $this->apiPost('/api/v1/auth/login', ['email' => 'en@firma-a.test', 'password' => 'parola1234'], ['Accept-Language' => 'en'])
            ->assertStatus(422);
        $this->apiPost('/api/v1/auth/login', ['email' => 'en@firma-a.test', 'password' => 'parola1234'], ['Accept-Language' => 'en'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests. Please wait a moment and try again.');
    }

    public function test_kimlik_dogrulanmamis_istekler_de_sayaca_girer(): void
    {
        config(['api.rate_limit.api' => 2]);

        // uç var ama kimlik yok → 401; üçüncü denemede throttle devreye girer
        $this->apiGet('/api/v1/me')->assertStatus(401);
        $this->apiGet('/api/v1/me')->assertStatus(401);
        $this->apiGet('/api/v1/me')->assertStatus(429);
    }

    public function test_erisim_anahtari_basina_butceler_ayridir(): void
    {
        config(['api.rate_limit.api' => 3]);

        $token = $this->apiPost('/api/v1/auth/register', [
            'name' => 'Sahip',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ])->assertCreated()->json('token');

        $headers = ['Authorization' => "Bearer {$token}"];

        // SPA oturumu aynı anahtarı kullandığı için bütçesi ortak sayılır
        $this->apiGet('/api/v1/me', $headers)->assertOk()
            ->assertHeader('X-RateLimit-Limit', 3);
        $this->apiGet('/api/v1/me', $headers)->assertOk();

        // 3. istek: ayrı bir erişim anahtarı üret (eski anahtarın bütçesinden sayılır)
        $plain = $this->apiPost('/api/v1/me/tokens', ['name' => 'Masaüstü'], $headers)
            ->assertCreated()
            ->json('plain_text_token');

        // yeni anahtar KENDİ bütçesiyle gelir → hemen kullanılabilir
        $this->apiGet('/api/v1/me', ['Authorization' => "Bearer {$plain}"])
            ->assertOk()
            ->assertJsonPath('user.email', 'sahip@firma-a.test');

        // eski anahtarın bütçesi tükenmiş durumda
        $this->apiGet('/api/v1/me', $headers)->assertStatus(429)
            ->assertHeader('X-RateLimit-Remaining', 0);
    }
}
