<?php

namespace App\Integration\Tsoft;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tsoft panel / admin API v3 — YALNIZCA OKUMA sürücüsü.
 *
 * Taban: https://<domain>/api/v3/admin/
 * Kimlik doğrulama: önce panel'den oluşturulan API anahtarıyla
 * POST /api/v3/admin/2026-01/auth/client-tokens -> data.attributes.accessToken
 * (Sanctum `id|secret`; expiresIn boş geldiğinde 24 saat varsayılır). Token
 * önbellekte tutulur; 401'de bir kez yenilenir ve istek bir kez tekrarlanır.
 * 403 (kapsam yok) durumunda `$panelToken` geçici geri dönüş olarak kullanılır.
 *
 * Sırlar (clientSecret, accessToken, panelToken) asla günlüğe yazılmaz,
 * hata mesajlarına veya kanonik DTO'lara eklenmez.
 *
 * NOT: Ürün / müşteri / stok / ödeme işlemleri bu sürücüde yalnızca imza
 * uyumluluğu için vardır; hepsi rest1 sürücüsünde kalır.
 */
final class AdminApiDriver
{
    private const TOKEN_CACHE_PREFIX = 'tsoft:panel:token:';

    private const PAGE_SIZE = 100;

    private const CACHE_SAFETY_MARGIN = 60;

    public function __construct(
        private readonly int $companyId,
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $panelToken = null,
    ) {}

    /**
     * Tüm siparişleri ürünleriyle birlikte sayfalayarak okur.
     *
     * @return array<int, CanonicalOrder>
     */
    public function fetchAllOrders(int $limit = 10000): array
    {
        $limit = max(1, $limit);
        $orders = [];
        $page = 1;
        $lastPage = null;

        while (count($orders) < $limit) {
            $json = $this->getJson('orders/order', [
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'include' => 'products,customer,address,fulfillments',
                'sort' => 'id',
            ]);

            $rows = $json['data'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('Tsoft panel API beklenmeyen sipariş yanıtı döndürdü.');
            }

            $meta = $json['meta'] ?? null;
            if (is_array($meta) && isset($meta['last_page']) && is_numeric($meta['last_page'])) {
                $lastPage = (int) $meta['last_page'];
            }

            $fresh = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $orders[] = TsoftMapper::adminOrder($row);
                $fresh++;

                if (count($orders) >= $limit) {
                    break 2;
                }
            }

            // Son sayfa veya boş/short sayfa: sonsuz döngüye asla girilmez.
            if ($fresh === 0 || count($rows) < self::PAGE_SIZE || ($lastPage !== null && $page >= $lastPage)) {
                break;
            }

            $page++;
        }

        return $orders;
    }

    /** @return array<int, CanonicalOrder> */
    public function fetchOrders(?CarbonInterface $since = null, int $limit = 100): array
    {
        $orders = $this->fetchAllOrders($limit);
        if ($since === null) {
            return $orders;
        }

        $from = $since->getTimestamp();

        return array_values(array_filter(
            $orders,
            static fn (CanonicalOrder $order): bool => $order->placedAt !== null
                && $order->placedAt->getTimestamp() >= $from,
        ));
    }

    public function fetchOrder(string $externalRef): ?CanonicalOrder
    {
        $ref = trim($externalRef);
        if ($ref === '') {
            return null;
        }

        $json = $this->getJson('orders/order/'.rawurlencode($ref), [
            'include' => 'address,customer,fulfillments',
        ]);

        $data = $json['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }
        if (! is_array($data)) {
            // Bazı kurulumlar zarf kullanmadan düz gövde döndürür.
            $data = $json;
        }

        return ! array_is_list($data) ? TsoftMapper::adminOrder($data) : null;
    }

    // ── Okuma uyumlulukları: bu sürücüden veri çekilmez (rest1 kullanılır) ──

    /** @param array<int,mixed> $skus */
    public function fetchProducts(array $skus): array
    {
        return [];
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAllProducts(): array
    {
        return [];
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAllCustomers(int $limit = 10000): array
    {
        return [];
    }

    public function fetchStock(string $sku, ?string $warehouseCode = null): ?CanonicalStock
    {
        return null;
    }

    // ── Yazım yasak: hiçbiri HTTP isteği göndermez ────────────────────────────

    public function pushStock(CanonicalStock $stock): SyncResult
    {
        return SyncResult::failure('Tsoft panel API stok yazımı desteklenmiyor.');
    }

    public function pushPrice(CanonicalPrice $price): SyncResult
    {
        return SyncResult::failure('Tsoft panel API fiyat yazımı desteklenmiyor.');
    }

    public function pushPayment(CanonicalPayment $payment): SyncResult
    {
        return SyncResult::failure('Tsoft panel API ödeme yazımı desteklenmiyor.');
    }

    public function pushPaymentNote(CanonicalPayment $payment, ?string $note = null): SyncResult
    {
        return SyncResult::failure('Tsoft panel API ödeme notu yazımı desteklenmiyor.');
    }

    public function pushShipment(CanonicalOrder $order): SyncResult
    {
        return SyncResult::failure('Tsoft panel API kargo yazımı desteklenmiyor.');
    }

    public function pushStatus(string $externalRef, string $mrcStatus): SyncResult
    {
        return SyncResult::failure('Tsoft panel API durum yazımı desteklenmiyor.');
    }

    // ── Kimlik doğrulama ──────────────────────────────────────────────────────

    /**
     * Geçerli erişim tokenını döndürür.
     *
     * Önbellek -> client-tokens uç -> panelToken (geçici geri dönüş) sırası.
     * Token istekleri önbelleklendiği için dakikadaki 10 istek sınırı aşılmaz.
     */
    private function accessToken(bool $forcePanelToken = false): string
    {
        if ($forcePanelToken && $this->panelToken !== null && trim($this->panelToken) !== '') {
            return $this->panelToken;
        }

        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (trim($this->clientId) !== '' && trim($this->clientSecret) !== '') {
            $token = $this->requestClientToken();
            if ($token !== null) {
                return $token;
            }
        }

        // Panel oturum token'ı 24 saatliktir; expiresIn bilgisi olmadığından
        // önbelleğe yazılmaz, yalnızca client credentials başarısızsa kullanılır.
        if ($this->panelToken !== null && trim($this->panelToken) !== '') {
            return $this->panelToken;
        }

        throw new RuntimeException('Tsoft panel API kimlik doğrulama bilgisi bulunamadı.');
    }

    /** Sanitized: sırlar ne mesaja ne de günlüğe eklenir. */
    private function requestClientToken(): ?string
    {
        $url = $this->baseUrl().'/2026-01/auth/client-tokens';
        $payload = [
            'data' => [
                'type' => 'client-tokens',
                'attributes' => [
                    'clientId' => $this->clientId,
                    'clientSecret' => $this->clientSecret,
                ],
            ],
        ];

        $response = Http::timeout(60)
            ->asJson()
            ->withHeaders([
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            return null;
        }

        $accessToken = $response->json('data.attributes.accessToken');
        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $expiresIn = (int) ($response->json('data.attributes.expiresIn') ?? 86400);
        $ttl = max(self::CACHE_SAFETY_MARGIN, $expiresIn - self::CACHE_SAFETY_MARGIN);
        Cache::put($this->cacheKey(), $accessToken, $ttl);

        return $accessToken;
    }

    private function cacheKey(): string
    {
        return self::TOKEN_CACHE_PREFIX.$this->companyId;
    }

    private function apiRequest(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->withHeaders(['Accept' => 'application/vnd.api+json'])
            ->timeout(60);
    }

    private function baseUrl(): string
    {
        $value = trim($this->baseUrl);

        if (stripos($value, '/api/v3/admin') === false) {
            $value = preg_replace('#^https?://#i', '', $value) ?? '';

            return 'https://'.rtrim($value, '/').'/api/v3/admin';
        }

        return rtrim($value, '/');
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function getJson(string $path, array $query = [], bool $authRetried = false, bool $scopeFallback = false): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $response = $this->apiRequest($this->accessToken($scopeFallback))->get($url, $query);

        // 403 = anahtarda kapsam yok (ör. order:read). Panel sürümü yeni
        // "Yönetim API" izin kartını sunmuyorsa oturum token'ına bir kez düşülür.
        if ($response->status() === 403 && ! $scopeFallback
            && $this->panelToken !== null && trim($this->panelToken) !== '') {
            return $this->getJson($path, $query, $authRetried, true);
        }

        // 401 yalnızca bir kez yenilenir; asla tekrar döngüsüne girilmez.
        if ($response->status() === 401 && ! $authRetried) {
            Cache::forget($this->cacheKey());

            return $this->getJson($path, $query, true, $scopeFallback);
        }

        if (! $response->successful()) {
            // Yanıt gövdesi kişisel veri/sır içerebileceğinden yalnızca HTTP
            // kodu taşınır. Başlıklar (Authorization) mesaja ASLA eklenmez.
            throw new RuntimeException('Tsoft panel API isteği başarısız (HTTP '.$response->status().').');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Tsoft panel API geçersiz (JSON olmayan) yanıt döndürdü.');
        }

        return $json;
    }
}
