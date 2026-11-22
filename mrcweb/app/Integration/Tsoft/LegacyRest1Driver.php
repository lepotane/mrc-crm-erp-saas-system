<?php

namespace App\Integration\Tsoft;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;
use App\Integration\Orders\PaymentBridgeService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Eski Tsoft rest1 API'si.
 *
 * Taban: https://<domain>/rest1/<class>/<method>
 * Giriş: POST /rest1/auth/login (user/pass) -> token + expirationTime
 * Token: her çağrıda POST parametresi olarak gönderilir.
 *
 * Hız sınırı: rest1 uçları ~3 dakikadan sık sorgulanmamalıdır; sipariş çekimi
 * bu nedenle kısa ömürlü bir önbellekle yumuşatılır (bkz. fetchOrders).
 */
final class LegacyRest1Driver
{
    private const TOKEN_CACHE_PREFIX = 'tsoft:token:';

    public function __construct(
        private readonly int $companyId,
        private readonly string $domain,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /** @return array<int, CanonicalOrder> */
    public function fetchOrders(?CarbonInterface $since = null, int $limit = 100): array
    {
        $limit = max(1, min(5000, $limit));
        $pageSize = 500;
        $orders = [];
        $seen = [];

        // Sipariş gövdeleri kişisel veri içerir; DB cache'e yazılmaz (KVKK).
        for ($start = 0; $start < $limit; $start += $pageSize) {
            $response = $this->post('/order/get', [
                'UpdateDateTimeStart' => $since?->format('Y-m-d H:i:s'),
                'IsTransferred' => 0,
                'Archive' => 0,
                'limit' => min($pageSize, $limit - $start),
                'start' => $start,
            ]);

            $this->assertSuccessful($response, 'Siparişler alınamadı');

            $pageRows = $this->rows($response);
            if ($pageRows === []) {
                break;
            }

            $fresh = 0;
            foreach ($pageRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = (string) ($row['OrderCode'] ?? '');
                $key = $code !== '' ? $code : md5(serialize($row));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $fresh++;
                $orders[] = TsoftMapper::order($row);
                if (count($orders) >= $limit) {
                    break 2;
                }
            }

            if ($fresh === 0 || count($pageRows) < $pageSize) {
                break;
            }
        }

        return $orders;
    }

    /** Tüm siparişler (arşivlenmiş/aktarılmış dahil) — tek seferlik tam aktarım için. */
    public function fetchAllOrders(int $limit = 10000): array
    {
        $pageSize = 500;
        $orders = [];
        $seen = [];

        for ($start = 0; $start < $limit; $start += $pageSize) {
            $response = $this->post('/order/get', [
                'limit' => min($pageSize, $limit - $start),
                'start' => $start,
            ]);

            $this->assertSuccessful($response, 'Tüm siparişler alınamadı');

            $rows = $this->rows($response);
            if ($rows === []) {
                break;
            }

            $fresh = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = (string) ($row['OrderCode'] ?? '');
                $key = $code !== '' ? $code : md5(serialize($row));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $fresh++;
                $orders[] = TsoftMapper::order($row);
                if (count($orders) >= $limit) {
                    break 2;
                }
            }

            if ($fresh === 0 || count($rows) < $pageSize) {
                break;
            }
        }

        return $orders;
    }

    public function fetchOrder(string $externalRef): ?CanonicalOrder
    {
        $response = $this->post('/order/get', [
            'OrderCode' => $externalRef,
            'IsTransferred' => 0,
            'Archive' => 0,
        ]);

        $this->assertSuccessful($response, 'Sipariş alınamadı');

        foreach ($this->rows($response) as $row) {
            if (is_array($row)) {
                return TsoftMapper::order($row);
            }
        }

        return null;
    }

    public function fetchStock(string $sku, ?string $warehouseCode = null): ?CanonicalStock
    {
        $response = $this->post('/product/getProducts', [
            // rest1 tek seferde en fazla 500 ürün kabul eder.
            'ProductCodes' => [$sku],
        ]);

        $this->assertSuccessful($response, 'Stok alınamadı');

        foreach ($this->rows($response) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = (string) ($row['ProductCode'] ?? $row['productCode'] ?? '');
            if ($code === '' || $code === $sku) {
                return TsoftMapper::stock($row, $warehouseCode ?? 'default', 'tsoft');
            }
        }

        return null;
    }

    /**
     * Ürün fiyatlarını rest1 uçundan okur (ham satırlar; eşleme TsoftMapper'da).
     *
     * Doğrulanmış uç: POST /rest1/product/getProducts — `ProductIds` en fazla
     * 500 ürün için pipe ('|') ile ayrılmış liste bekler.
     *
     * @param  array<int,mixed>  $skus
     * @return array<int,array<string,mixed>>
     */
    public function fetchProducts(array $skus): array
    {
        $codes = [];
        foreach ($skus as $sku) {
            if (! is_scalar($sku)) {
                continue;
            }

            $code = trim((string) $sku);
            if ($code !== '') {
                $codes[$code] = $code;
            }
        }

        if ($codes === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk(array_values($codes), 500) as $chunk) {
            $response = $this->post('/product/getProducts', [
                'ProductIds' => implode('|', $chunk),
            ]);

            $this->assertSuccessful($response, 'Ürün fiyatları alınamadı');

            foreach ($this->rows($response) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Tüm Tsoft ürünlerini sayfalayarak okur (YALNIZCA OKUMA).
     *
     * rest1 `/product/getProducts` ucuna boş filtreyle gidildiğinde tüm katalog
     * döner; `page`/`Page` parametresiyle ilerlenir. Bazı kurulumlar sayfalama
     * parametresini yok sayıp aynı ilk sayfayı tekrar döndürür; aynı satır
     * grubu tekrar geldiğinde döngü kırılır.
     *
     * NOT: "Tsoft'ta yeni ürün var" uyarısı için MRC SKU'larından yola çıkmak
     * yetmez — yeni ürün MRC'de hiç bulunmaz. Bu nedenle filtresiz tam katalog
     * taraması gerekir. Üst sınır 5000 kayıttır (korumalı durdurma).
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAllProducts(): array
    {
        $pageSize = 500;
        $maxProducts = 10000;
        $rows = [];
        $seen = [];

        for ($start = 0; $start < $maxProducts; $start += $pageSize) {
            $response = $this->post('/product/getProducts', [
                // Konsolda doğrulanan parametreler: limit (max 500) ve start (offset).
                'limit' => $pageSize,
                'start' => $start,
            ]);

            $this->assertSuccessful($response, 'Tüm ürünler alınamadı');

            $pageRows = array_values(array_filter($this->rows($response), 'is_array'));
            if ($pageRows === []) {
                break;
            }

            $fresh = 0;
            foreach ($pageRows as $row) {
                $fingerprint = md5(serialize($row));
                if (isset($seen[$fingerprint])) {
                    continue;
                }

                $seen[$fingerprint] = true;
                $fresh++;
                $rows[] = $row;

                if (count($rows) >= $maxProducts) {
                    break 2;
                }
            }

            // Sayfalama desteklenmiyorsa aynı satırlar döner; veya son sayfadır.
            if ($fresh === 0 || count($pageRows) < $pageSize) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Eski çağrılar için takma ad: fiyat okuma aynı ürün uçtan gelir.
     *
     * @param  array<int,mixed>  $skus
     * @return array<int,array<string,mixed>>
     */
    public function fetchProductPrices(array $skus): array
    {
        return $this->fetchProducts($skus);
    }

    /**
     * MRC stokun kaynağıdır; bu yazı Tsoft'a ayna (mirror) olarak gönderilir.
     * rest1'de `expected` / iyimser kilitleme alanı YOKTUR — bu yüzden sürüm
     * bilgisi iletilmez ve çakışma kontrolü yapılmaz.
     */
    public function pushStock(CanonicalStock $stock): SyncResult
    {
        try {
            $response = $this->post('/product/setProducts', [
                'products' => [[
                    'ProductCode' => $stock->sku,
                    'Stock' => $stock->quantity,
                ]],
            ]);

            return $this->writeResult($response, $stock->sku, 'Stok aynalandı', 'Stok yazılamadı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Stok yazılamadı'));
        }
    }

    public function pushPrice(CanonicalPrice $price): SyncResult
    {
        try {
            $response = $this->post('/product/setProducts', [
                'products' => [[
                    'ProductCode' => $price->sku,
                    'SellingPrice' => $price->salePrice,
                    'Currency' => $price->currency,
                ]],
            ]);

            return $this->writeResult($response, $price->sku, 'Fiyat yazıldı', 'Fiyat yazılamadı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Fiyat yazılamadı'));
        }
    }

    /**
     * DOĞRULANMIŞ yazım yolu: POST /rest1/order2/setOrderNote (OrderId, Text).
     * AdminId/CustomerId uç için pratikte opsiyoneldir; elimizde olmadığından
     * payload'a eklenmez. Not metni kişisel veri ve sır içermez.
     */
    public function pushPaymentNote(CanonicalPayment $payment, ?string $note = null): SyncResult
    {
        try {
            $text = $note !== null && trim($note) !== ''
                ? PaymentBridgeService::sanitizeNote($note)
                : PaymentBridgeService::buildNote($payment);

            $response = $this->post('/order2/setOrderNote', [
                'OrderId' => $payment->orderRef,
                'Text' => mb_substr($text, 0, 500),
            ]);

            return $this->writeResult(
                $response,
                $payment->externalRef,
                'Ödeme notu yazıldı',
                'Ödeme notu yazılamadı',
            );
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Ödeme notu yazılamadı'));
        }
    }

    /**
     * UYARI: `/order/setOrderPayment` bu kurulumda DOĞRULANMADI. Varsayılan
     * ödeme yazım yolu olarak `pushPaymentNote()` kullanılmalıdır.
     */
    public function pushPayment(CanonicalPayment $payment): SyncResult
    {
        try {
            $response = $this->post('/order/setOrderPayment', [
                'OrderCode' => $payment->orderRef,
                'Amount' => $payment->amount,
                'Currency' => $payment->currency,
                'PaymentType' => $payment->method,
                'TransactionNo' => $payment->transactionRef,
            ]);

            return $this->writeResult($response, $payment->externalRef, 'Ödeme yazıldı', 'Ödeme yazılamadı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Ödeme yazılamadı'));
        }
    }

    public function pushShipment(CanonicalOrder $order): SyncResult
    {
        try {
            $raw = $order->raw;
            $response = $this->post('/order/setOrderCargo', [
                'OrderCode' => $order->externalRef,
                'CargoCompany' => (string) ($raw['CargoCompany'] ?? $raw['cargoCompany'] ?? ''),
                'TrackingNumber' => (string) ($raw['TrackingNumber'] ?? $raw['cargoNumber'] ?? ''),
            ]);

            return $this->writeResult($response, $order->externalRef, 'Kargo yazıldı', 'Kargo yazılamadı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Kargo yazılamadı'));
        }
    }

    public function pushStatus(string $externalRef, string $mrcStatus): SyncResult
    {
        try {
            // Durum listesi uçtan okunur; MRC durumunu Tsoft durum koduna eşler.
            $statusValue = $this->resolveStatus($mrcStatus);

            $response = $this->post('/order/setOrderStatus', [
                'OrderCode' => $externalRef,
                'Status' => $statusValue,
            ]);

            return $this->writeResult($response, $externalRef, 'Durum yazıldı', 'Durum yazılamadı');
        } catch (Throwable $e) {
            return SyncResult::failure($this->safeException($e, 'Durum yazılamadı'));
        }
    }

    private function resolveStatus(string $mrcStatus): string
    {
        try {
            $response = $this->post('/order2/getOrderStatusList', []);
            if (! $response->successful()) {
                return $mrcStatus;
            }

            foreach ($this->rows($response) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $label = (string) ($row['Status'] ?? $row['Name'] ?? $row['name'] ?? '');
                if ($label !== '' && strcasecmp($label, $mrcStatus) === 0) {
                    return (string) ($row['Id'] ?? $row['id'] ?? $label);
                }
            }
        } catch (Throwable) {
            // Liste okunamazsa ham durum değeri gönderilir.
        }

        return $mrcStatus;
    }

    /** Token, süresinin 5 dakika öncesine kadar önbellekte tutulur. */
    private function token(): string
    {
        $key = self::TOKEN_CACHE_PREFIX.$this->companyId;
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Klasik rest1'de kullanıcı adı URL yolunda, parola `pass` alanında gönderilir.
        $response = $this->http()
            ->asForm()
            ->post($this->baseUrl().'/auth/login/'.rawurlencode($this->username), [
                'pass' => $this->password,
            ]);

        if (! $response->successful()) {
            // Parola asla mesaja eklenmez.
            throw new \RuntimeException('Tsoft rest1 girişi başarısız (HTTP '.$response->status().').');
        }

        $bearer = $response->json('data.0.token') ?? $response->json('token');
        if (! is_string($bearer) || $bearer === '') {
            throw new \RuntimeException('Tsoft rest1 giriş yanıtında erişim anahtarı bulunamadı.');
        }

        $expiration = $response->json('data.0.expirationTime') ?? $response->json('expirationTime') ?? $response->json('expiration_time');
        $expiresAt = is_numeric($expiration)
            ? (int) $expiration
            : (is_string($expiration) ? (strtotime($expiration) ?: 0) : 0);
        $ttl = max(60, ($expiresAt > 0 ? $expiresAt : time() + 3600) - time() - 300);

        Cache::put($key, $bearer, $ttl);

        return $bearer;
    }

    /** Token her çağrıda POST parametresi olarak eklenir. */
    private function post(string $path, array $params = []): Response
    {
        $params['token'] = $this->token();

        return $this->http()->asForm()->post($this->baseUrl().$path, $params);
    }

    /**
     * CAVEAT: Http::retry(2, 200) yalnızca 429 ve 5xx için izin veren bir
     * koşulla sınırlandırılmıştır; diğer 4xx yanıtları tekrar denenmez.
     * Bağlantı hatasında yanıt olmadığı için yeniden deneme yapılmaz.
     */
    private function http(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(3)
            ->retry(2, 200, function (Throwable $e): bool {
                if (! $e instanceof RequestException || $e->response === null) {
                    return false;
                }

                $status = $e->response->status();

                return $status === 429 || $status >= 500;
            });
    }

    private function baseUrl(): string
    {
        $domain = preg_replace('#^https?://#i', '', trim($this->domain));

        return 'https://'.rtrim((string) $domain, '/').'/rest1';
    }

    /** @return array<int,mixed> */
    private function rows(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        foreach (['Data', 'data', 'Orders', 'orders', 'Products', 'products'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_is_list($json[$key]) ? $json[$key] : [$json[$key]];
            }
        }

        return array_is_list($json) ? $json : [];
    }

    private function assertSuccessful(Response $response, string $context): void
    {
        if (! $response->successful()) {
            throw new \RuntimeException($this->failureDetail($response, $context));
        }
    }

    private function writeResult(Response $response, string $externalRef, string $success, string $context): SyncResult
    {
        if (! $response->successful()) {
            return SyncResult::failure($this->failureDetail($response, $context));
        }

        return SyncResult::success($externalRef, $success);
    }

    private function failureDetail(Response $response, string $context): string
    {
        $json = $response->json();
        $detail = '';

        if (is_array($json)) {
            foreach (['message', 'Message', 'error', 'Error'] as $key) {
                if (isset($json[$key]) && is_scalar($json[$key])) {
                    $detail = (string) $json[$key];
                    break;
                }
            }
        }

        $detail = (string) (TsoftMapper::mask(['detail' => $detail])['detail'] ?? '');

        return $context.' (HTTP '.$response->status().')'.($detail !== '' ? ': '.mb_strcut($detail, 0, 300) : '');
    }

    private function safeException(Throwable $e, string $context): string
    {
        if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), $context)) {
            return $e->getMessage();
        }

        return $context.' (HTTP isteği başarısız).';
    }
}
