<?php

namespace App\Integration\Outbox;

use App\Integration\Contracts\IdempotencyStoreInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotency anahtarları için kısa ömürlü sonuç önbelleği.
 *
 * Üretimde Redis, testte array store kullanılır (cache.default). Sonuç yalnızca
 * güvenli tiplerse (scalar / array / null) yazılır; nesneler önbelleğe alınmaz.
 */
class IdempotencyStore implements IdempotencyStoreInterface
{
    private const PREFIX = 'integration:idem:';

    private const TTL_SECONDS = 86400; // 24 saat

    /** Aynı anahtar işlendiyse sonucu döndürür; callable'ı TEKRAR çalıştırmaz. */
    public function remember(string $key, callable $operation): mixed
    {
        $cacheKey = self::PREFIX.$key;

        // `null` sonucu da ayırt edebilmek için has + get birlikte kullanılır.
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            // Başarısız işlem önbelleğe yazılmaz; hata yukarı fırlatılır.
            throw $e;
        }

        $this->storeResult($key, $result);

        return $result;
    }

    /** Güvenli sonuçları 24 saat önbelleğe yazar; güvenli olmayan tipleri atlar. */
    public function storeResult(string $key, mixed $result): void
    {
        if (! $this->isCacheable($result)) {
            return;
        }

        Cache::put(self::PREFIX.$key, $result, self::TTL_SECONDS);
    }

    public function forget(string $key): void
    {
        Cache::forget(self::PREFIX.$key);
    }

    public function exists(string $key): bool
    {
        return Cache::has(self::PREFIX.$key);
    }

    /** Yalnızca serileştirilmesi güvenli değerler önbelleğe alınır. */
    private function isCacheable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isCacheable($item)) {
                return false;
            }
        }

        return true;
    }
}
