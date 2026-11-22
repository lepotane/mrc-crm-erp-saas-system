<?php

namespace App\Integration\Translate;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gerçek çeviri: Google Translate herkese açık uç (yalnızca OKUMA).
 *
 * MyMemory kotası dolduğunda kullanılır. Model/kod numaraları (433MHz, ID47,
 * 95440-N9520 ...) servis tarafından korunur. Kaynak metin loglanmaz.
 */
final class GoogleTranslator
{
    private const ENDPOINT = 'https://translate.googleapis.com/translate_a/single';

    public function translate(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            $response = Http::timeout(20)->get(self::ENDPOINT, [
                'client' => 'gtx',
                'sl' => 'en',
                'tl' => 'tr',
                'dt' => 't',
                'q' => $text,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload[0]) || ! is_array($payload[0])) {
            return null;
        }

        $parts = [];
        foreach ($payload[0] as $segment) {
            if (is_array($segment) && is_string($segment[0] ?? null)) {
                $parts[] = $segment[0];
            }
        }

        $translated = trim(implode('', $parts));

        return $translated !== '' ? $translated : null;
    }
}
