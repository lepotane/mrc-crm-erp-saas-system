<?php

namespace App\Integration\Tsoft;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tsoft yönetim API'si (2026-01).
 *
 * Taban: https://<domain>/api/v3/admin/2026-01
 * Başlıklar: Authorization: Bearer <token>, Accept: application/vnd.api+json
 * JSON:API: data.attributes, id alanları string.
 * Hız sınırı: 120 istek/dakika.
 */
final class Admin2026Driver
{
    private const TOKEN_CACHE_PREFIX = 'tsoft:token:admin:';

    public function __construct(
        private readonly int $companyId,
        private readonly string $domain,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    /** @return array<int, CanonicalOrder> */
    public function fetchOrders(?CarbonInterface $since = null, int $limit = 100): array
    {
        $limit = max(1, min($limit, 10000));
        $orders = [];
        $page = 1;

        do {
            $params = [
                'include' => 'items',
                'page[size]' => 100,
                'page[number]' => $page,
            ];

            if ($since !== null) {
                // placedBetween: başlangıç ve bitiş ISO-8601 değerleri virgülle ayrılır.
                $params['filter[placedBetween]'] = $since->toIso8601String().','.now()->toIso8601String();
            }

            $response = $this->send('GET', '/order/orders', $params);
            if (! $response->successful()) {
                break;
            }

            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $included = is_array($json['included'] ?? null) ? $json['included'] : [];

            foreach ($data as $resource) {
                if (! is_array($resource)) {
                    continue;
                }

                $flat = $this->flatten($resource, $included);
                $orders[] = TsoftMapper::order($flat);

                if (count($orders) >= $limit) {
                    break 2;
                }
            }

            $next = $json['links']['next'] ?? null;
            $page++;
        } while (is_string($next) && $next !== '' && count($orders) < $limit);

        return $orders;
    }

    public function fetchOrder(string $externalRef): ?CanonicalOrder
    {
        $response = $this->send('GET', '/order/orders/'.rawurlencode($externalRef), ['include' => 'items']);

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        $resource = $json['data'] ?? null;
        if (! is_array($resource)) {
            return null;
        }

        return TsoftMapper::order($this->flatten($resource, is_array($json['included'] ?? null) ? $json['included'] : []));
    }

    public function fetchStock(string $sku, ?string $warehouseCode = null): ?CanonicalStock
    {
        $ids = $this->resolveVariant($sku);
        $params = ['filter[productId]' => $ids['productId'] ?? $sku];

        if ($warehouseCode !== null) {
            $params['filter[warehouseId]'] = $warehouseCode;
        }

        $response = $this->send('GET', '/catalog/warehouse-stocks', $params);
        if (! $response->successful()) {
            return null;
        }

        $rows = $response->json('data');
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (! is_array($row)) {
            return null;
        }

        $attrs = is_array($row['attributes'] ?? null) ? $row['attributes'] : $row;
        $attrs['id'] ??= $row['id'] ?? null;
        $attrs['productId'] ??= $ids['productId'] ?? null;
        $attrs['variantId'] ??= $ids['variantId'] ?? null;
        if ($warehouseCode !== null) {
            $attrs['warehouseId'] ??= $warehouseCode;
        }

        return TsoftMapper::stock(
            $attrs,
            $warehouseCode ?? 'default',
            'tsoft',
            (int) ($attrs['version'] ?? 0),
        );
    }

    /**
     * `expected` alanına CanonicalStock::version değeri yazılır (iyimser kilitleme).
     * MRC stokun kaynağıdır; Tsoft bu yazıyla aynalanır.
     */
    public function pushStock(CanonicalStock $stock): SyncResult
    {
        try {
            $ids = $this->resolveVariant($stock->sku);
            if (! isset($ids['productId'], $ids['variantId'])) {
                return SyncResult::failure('Tsoft varyantı bulunamadı: '.$stock->sku);
            }

            // Bu uç JSON:API değil, düz JSON bekler.
            $response = $this->send('POST', '/catalog/warehouse-stocks/-actions/set', [
                'productId' => (string) $ids['productId'],
                'variantId' => (string) $ids['variantId'],
                'warehouseId' => (string) $stock->warehouseCode,
                // DTO'da string, API'de sayısal quantity beklenir.
                'quantity' => is_numeric($stock->quantity) ? $stock->quantity + 0 : 0,
                'expected' => $stock->version,
            ], true);

            return $this->writeResult($response, $stock->externalRef ?? $stock->sku, 'Stok aynalandı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Stok yazılamadı'));
        }
    }

    public function pushPrice(CanonicalPrice $price): SyncResult
    {
        try {
            $ids = $this->resolveVariant($price->sku);
            $attributes = [
                'sku' => $price->sku,
                'currency' => $price->currency,
                // Para tutarları string olarak gönderilir (float dönüşümü yok).
                'salePrice' => $price->salePrice,
                'costPrice' => $price->costPrice,
                'isActive' => $price->isActive,
            ];
            if (isset($ids['productId'], $ids['variantId'])) {
                $attributes['productId'] = (string) $ids['productId'];
                $attributes['variantId'] = (string) $ids['variantId'];
            }

            $response = $this->send(
                'POST',
                '/catalog/prices/-actions/set',
                $this->envelope('prices', $attributes),
            );

            return $this->writeResult($response, $price->sku, 'Fiyat yazıldı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Fiyat yazılamadı'));
        }
    }

    public function pushPayment(CanonicalPayment $payment): SyncResult
    {
        try {
            $attributes = [
                'externalRef' => $payment->externalRef,
                'orderRef' => $payment->orderRef,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'paidAt' => $payment->paidAt?->toIso8601String(),
                'transactionRef' => $payment->transactionRef,
            ];

            $response = $this->send(
                'POST',
                '/order/payments',
                $this->envelope('payments', $attributes, $payment->externalRef),
            );

            return $this->writeResult($response, $payment->externalRef, 'Ödeme yazıldı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Ödeme yazılamadı'));
        }
    }

    public function pushShipment(CanonicalOrder $order): SyncResult
    {
        try {
            $raw = $order->raw;
            $attributes = [
                'orderRef' => $order->externalRef,
                'cargoCompany' => (string) ($raw['CargoCompany'] ?? $raw['cargoCompany'] ?? ''),
                'trackingNumber' => (string) ($raw['TrackingNumber'] ?? $raw['cargoNumber'] ?? ''),
            ];

            $response = $this->send(
                'POST',
                '/order/shipments',
                $this->envelope('shipments', $attributes),
            );

            return $this->writeResult($response, $order->externalRef, 'Kargo yazıldı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Kargo yazılamadı'));
        }
    }

    public function pushStatus(string $externalRef, string $mrcStatus): SyncResult
    {
        try {
            $response = $this->send(
                'POST',
                '/order/orders/'.rawurlencode($externalRef).'/status',
                $this->envelope('order-statuses', ['status' => $mrcStatus]),
            );

            return $this->writeResult($response, $externalRef, 'Durum yazıldı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Durum yazılamadı'));
        }
    }

    /** @return array{productId?:string,variantId?:string} */
    private function resolveVariant(string $sku): array
    {
        $response = $this->send('GET', '/catalog/variants', ['filter[wsSubProductCode]' => $sku]);
        if (! $response->successful()) {
            return [];
        }

        $rows = $response->json('data');
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (! is_array($row)) {
            return [];
        }

        $ids = [];
        if (isset($row['id']) && is_scalar($row['id'])) {
            $ids['variantId'] = (string) $row['id'];
        }

        $productRef = $row['relationships']['product']['data']['id'] ?? ($row['attributes']['productId'] ?? null);
        if (is_scalar($productRef) && (string) $productRef !== '') {
            $ids['productId'] = (string) $productRef;
        }

        return $ids;
    }

    /** JSON:API veri zarfı. */
    private function envelope(string $type, array $attributes, ?string $id = null): array
    {
        $data = ['type' => $type, 'attributes' => $attributes];

        if ($id !== null && $id !== '') {
            $data['id'] = $id;
        }

        return ['data' => $data];
    }

    /** JSON:API kaynağını ve `included` kalemlerini düz bir diziye indirger. */
    private function flatten(array $resource, array $included): array
    {
        $flat = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        $flat['id'] ??= $resource['id'] ?? null;

        $relationships = is_array($resource['relationships'] ?? null) ? $resource['relationships'] : [];
        $items = [];

        foreach (['items', 'orderItems'] as $relation) {
            $links = $relationships[$relation]['data'] ?? null;
            if (! is_array($links)) {
                continue;
            }

            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }

                foreach ($included as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }

                    if (($entry['type'] ?? null) === ($link['type'] ?? null)
                        && (string) ($entry['id'] ?? '') === (string) ($link['id'] ?? '')) {
                        $item = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : $entry;
                        $item['id'] ??= $entry['id'] ?? null;
                        $items[] = $item;
                        break;
                    }
                }
            }
        }

        if ($items !== []) {
            $flat['items'] = $items;
        }

        return $flat;
    }

    /** 24 saatlik token, son 5 dakika hariç önbellekte tutulur. */
    private function token(bool $forceRefresh = false): string
    {
        $key = self::TOKEN_CACHE_PREFIX.$this->companyId;

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = $this->http()
            ->withHeaders([
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ])
            ->post($this->baseUrl().'/auth/client-tokens', $this->envelope('client-tokens', [
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
            ], null));

        if (! $response->successful()) {
            // Sır (client secret) mesaja asla eklenmez.
            throw new \RuntimeException('Tsoft admin kimlik doğrulaması başarısız (HTTP '.$response->status().').');
        }

        $bearerToken = $response->json('data.attributes.accessToken') ?? $response->json('accessToken');
        if (! is_string($bearerToken) || $bearerToken === '') {
            throw new \RuntimeException('Tsoft admin yanıtında erişim tokenı bulunamadı.');
        }

        $expiresIn = (int) ($response->json('data.attributes.expiresIn') ?? $response->json('expiresIn') ?? 86400);
        Cache::put($key, $bearerToken, max(60, $expiresIn - 300));

        return $bearerToken;
    }

    /**
     * 401 bir kez yenilenir; 403 olduğu gibi döner; 429 ve 5xx retry edilir;
     * diğer 4xx asla retry edilmez.
     */
    private function send(
        string $method,
        string $path,
        array $payload = [],
        bool $plainJson = false,
        bool $authRetried = false,
    ): Response {
        $headers = [
            'Authorization' => 'Bearer '.$this->token(),
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => $plainJson ? 'application/json' : 'application/vnd.api+json',
        ];

        $request = $this->http()->withHeaders($headers);
        $url = $this->baseUrl().$path;

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $payload),
            'PATCH' => $request->patch($url, $payload),
            'PUT' => $request->put($url, $payload),
            default => $request->post($url, $payload),
        };

        if ($response->status() === 401 && ! $authRetried) {
            Cache::forget(self::TOKEN_CACHE_PREFIX.$this->companyId);
            $this->token(true);

            return $this->send($method, $path, $payload, $plainJson, true);
        }

        return $response;
    }

    /**
     * 429 / 5xx dışındaki hatalar tekrar denenmez. `$throw = false` ile
     * tüm denemeler tükendiğinde Response döner; istisna yalnızca bağlantı
     * hatasında fırlatılır ve çağıran tarafça yakalanır.
     */
    private function http(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(3)
            ->retry(2, 250, function (Throwable $e): bool {
                if (! $e instanceof RequestException || $e->response === null) {
                    return false;
                }

                $status = $e->response->status();

                return $status === 429 || $status >= 500;
            }, false);
    }

    private function baseUrl(): string
    {
        $domain = preg_replace('#^https?://#i', '', trim($this->domain));

        return 'https://'.rtrim((string) $domain, '/').'/api/v3/admin/2026-01';
    }

    private function writeResult(Response $response, ?string $fallbackRef, string $success): SyncResult
    {
        $detail = $this->errorDetail($response);

        if ($detail !== '') {
            return SyncResult::failure($detail);
        }

        $ref = $response->json('data.id') ?? $fallbackRef;

        return SyncResult::success(is_scalar($ref) ? (string) $ref : $fallbackRef, $success);
    }

    /** JSON:API errors[] içindeki detail/title değerini güvenli biçimde döner. */
    private function errorDetail(Response $response): string
    {
        if ($response->successful()) {
            return '';
        }

        $errors = $response->json('errors');
        $parts = [];

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $detail = $error['detail'] ?? $error['title'] ?? null;
                if (is_scalar($detail) && (string) $detail !== '') {
                    $parts[] = (string) $detail;
                }
            }
        }

        $detail = implode('; ', $parts);
        $detail = (string) (TsoftMapper::mask(['detail' => $detail])['detail'] ?? '');

        return $detail !== ''
            ? mb_strcut($detail, 0, 500)
            : 'Tsoft isteği başarısız (HTTP '.$response->status().').';
    }

    private function safeException(Throwable $e, string $context): string
    {
        if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Tsoft')) {
            return $e->getMessage();
        }

        return $context.' (HTTP isteği başarısız).';
    }
}
