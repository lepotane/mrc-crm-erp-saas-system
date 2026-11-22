<?php

namespace App\Integration\Translate;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gerçek çeviri: MyMemory herkese açık API (yalnızca OKUMA, Tsoft'a yazmaz).
 *
 * Model/kod numaraları (433MHz, ID47, 95440-N9520 ...) sağlayıcı tarafından
 * korunur. Kaynak metin veya kimlik bilgileri (de e-postası) loglanmaz.
 */
final class MyMemoryTranslator
{
    private const ENDPOINT = 'https://api.mymemory.translated.net/get';

    /** Ardışık HTTP çağrıları arasında beklenen süre (kullanıcıyı yormamak için). */
    private static bool $hasCalled = false;

    public function translate(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $email = (string) config('integrations.translation.email', 'info@mrc.local');
        $delayMs = max(0, (int) config('integrations.translation.delay_ms', 120));
        $maxAttempts = 3; // ilk deneme + en fazla 2 yeniden deneme

        $response = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->politePause($delayMs);

            try {
                $response = Http::timeout(20)->get(self::ENDPOINT, [
                    'q' => $text,
                    'langpair' => 'en|tr',
                    'de' => $email,
                ]);
            } catch (Throwable) {
                // Bağlantı hatası 5xx/429 değildir; yeniden deneme yapılmaz.
                return null;
            }

            $status = $response->status();
            if ($status === 429 || $status >= 500) {
                if ($attempt < $maxAttempts) {
                    continue;
                }

                return null;
            }

            break;
        }

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        // Kota bittiyse veya servis 200 dışında bir durum döndürdüyse çeviri yok.
        if (($payload['quotaFinished'] ?? false) === true) {
            return null;
        }

        if ((int) ($payload['responseStatus'] ?? 0) !== 200) {
            return null;
        }

        $translated = $payload['responseData']['translatedText'] ?? null;
        if (! is_string($translated)) {
            return null;
        }

        $translated = trim($translated);

        return $translated !== '' ? $translated : null;
    }

    /** Her gerçek HTTP çağrısından önce (ilk çağrı hariç) kısa bir mola. */
    private function politePause(int $delayMs): void
    {
        if (self::$hasCalled && $delayMs > 0) {
            usleep($delayMs * 1000);
        }

        self::$hasCalled = true;
    }
}
