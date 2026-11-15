<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Rapor önbelleği — sürüm sayaçlı .
 *
 * Neden sürüm sayaçlı: raporlar firma-bazlı ve yazma sonrası ANINDA taze olmalı
 * („faturayı kestim, raporda yok" şikâyeti kabul edilmez). TTL-tek başına önbellek
 * yazıdan sonra bayat veri gösterirdi; tam temizlik (flush) ise firmanın değil
 * herkesin önbelleğini uçururdu. Sayaç firma-bazlıdır: yazan firmanın sürümü artar,
 * diğer firmalar etkilenmez.
 *
 * Sürücüden bağımsız: file/database/redis/array hepsinde çalışır (etiket/tag yok;
 * `Cache::increment` her sürücüde vardır). Önbellek tamamen uçarsa sürüm de
 * sıfırlanır — ikisi birlikte gider, tutarsızlık doğmaz.
 *
 * Kapsam: SADECE rapor okumaları (`ReportCacheMiddleware`). İşlem uçları
 * önbelleğe alınmaz; denetim (`audit`) ve bakiye (`balance`) uçları da alınmaz —
 * onlar „şu anki gerçek" demelidir.
 */
class ReportCache
{
    /** Önbellek ömrü (sn): yazma sonrası sürüm artar, yazmasız 120 sn eski veri gösterilir. */
    public const TTL_SECONDS = 120;

    public static function version(int $companyId): int
    {
        return (int) (Cache::get(static::versionKey($companyId), 1));
    }

    /** Yazma sonrası çağrılır: bu firmanın rapor önbelleğini geçersiz kılar. */
    public static function bump(int $companyId): void
    {
        if (! Cache::has(static::versionKey($companyId))) {
            Cache::put(static::versionKey($companyId), 1);
        }

        Cache::increment(static::versionKey($companyId));
    }

    public static function key(int $companyId, string $path, string $queryHash, string $locale): string
    {
        return sprintf('reports:v%d:%d:%s:%s:%s', static::version($companyId), $companyId, $locale, $path, $queryHash);
    }

    protected static function versionKey(int $companyId): string
    {
        return "reports:version:{$companyId}";
    }
}
