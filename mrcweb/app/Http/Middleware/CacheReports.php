<?php

namespace App\Http\Middleware;

use App\Support\ReportCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapor yanıtı önbelleği .
 *
 * Yalnız GET + 200 yanıtlar saklanır; anahtar `sürüm:firma:yol:sorgu` olduğu için
 * aynı rapor aynı filtreyle ikinci kez DB'ye gitmez (`X-Report-Cache: HIT`).
 * Sürüm her yazmada artar (`ReportCache::bump`), yani önbellek „yazıyı" görür.
 *
 * Güvenlik: anahtarda firma id'si vardır — A'nın raporu B'ye ASLA dönmez
 * (anahtarlar çakışmaz; yine de testte kiracı yönü kilitli).
 */
class CacheReports
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $companyId = (int) ($request->user()?->current_company_id ?? 0);

        if ($companyId === 0) {
            return $next($request);
        }

        // Dil de anahtarın parçasıdır: aynı URL'nin TR ve EN gövdeleri ayrı saklanır
        // (SetLocale grup ara yazılımında çalıştığı için locale burada çözülmüştür).
        $key = ReportCache::key($companyId, $request->path(), sha1($request->getQueryString() ?? ''), app()->getLocale());

        $hit = Cache::get($key);

        if (is_array($hit) && isset($hit['body'])) {
            return response($hit['body'], $hit['status'] ?? 200, ['X-Report-Cache' => 'HIT'])
                ->header('Content-Type', 'application/json');
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() === 200 && $response->headers->get('Content-Type') !== null) {
            Cache::put($key, [
                'body' => $response->getContent(),
                'status' => 200,
            ], ReportCache::TTL_SECONDS);
            $response->headers->set('X-Report-Cache', 'MISS');
        }

        return $response;
    }
}
