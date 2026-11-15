<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * API hata mesajlarının DİLİ isteğin diline göre üretilmesi.
 *
 * Kapatılan hata: rota eşleşmediğinde locale middleware'i çalışmıyor, hata
 * mesajı process'teki son dile göre üretiliyordu (bir önceki isteğin dili
 * sızıyordu). Artık hata işleyicisi dili istekten çözüyor.
 */
class ApiLocaleTest extends TestCase
{
    public function test_eslesmeyen_api_yolu_istek_dilinde_mesaj_dondurur(): void
    {
        $tr = $this->getJson('/api/v1/olmayan-uc-tr', ['X-Locale' => 'tr']);
        $tr->assertStatus(404);
        $this->assertSame('Bu uç nokta mevcut değil.', $tr->json('message'));

        $en = $this->getJson('/api/v1/olmayan-uc-en', ['X-Locale' => 'en']);
        $en->assertStatus(404);
        $this->assertSame('This endpoint does not exist.', $en->json('message'));
    }

    public function test_dil_onceki_istekten_sizmaz(): void
    {
        // Önce İngilizce istek (locale middleware'i çalışır, locale 'en' olur),
        // sonra eşleşmeyen Türkçe istek: mesaj Türkçe olmalı.
        $this->getJson('/api/v1/auth/login', ['X-Locale' => 'en'])->assertStatus(405);

        $tr = $this->getJson('/api/v1/olmayan-uc-arka', ['X-Locale' => 'tr']);
        $tr->assertStatus(404);
        $this->assertSame('Bu uç nokta mevcut değil.', $tr->json('message'));
    }

    public function test_yonlendirme_bazli_405_mesaji_da_istek_dilinde(): void
    {
        $tr = $this->getJson('/api/v1/auth/login', ['X-Locale' => 'tr']);
        $tr->assertStatus(405);
        $this->assertStringContainsString('Bu uç nokta', $tr->json('message'));

        $en = $this->getJson('/api/v1/auth/login', ['X-Locale' => 'en']);
        $en->assertStatus(405);
        $this->assertStringContainsString('This endpoint', $en->json('message'));
    }
}
