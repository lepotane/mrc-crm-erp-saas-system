<?php

namespace App\Integration\Tsoft;

use App\Integration\Enums\Channel;
use App\Integration\Support\CredentialVault;
use App\Models\TenantIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Tsoft rest1 YAZIM ucu (opt-in).
 *
 * Varsayılan olarak HİÇBİR yazma yapılmaz: `integrations.dry_run === true`
 * veya ilgili kanal flag'i kapalıysa guard() hiç HTTP çağrısı yapmadan
 * "skipped" döner. Salt okuma (readProduct) her zaman serbesttir.
 *
 * Canlı doğrulanan uçlar:
 *  - POST /rest1/product/setProducts     → data JSON dizisi ile ürün oluşturur
 *  - POST /rest1/product/updatePrice     → yalnızca gönderilen alanları günceller
 *  - POST /rest1/product/updateStock     → MUTLAK stok yazar (idempotent)
 *  - POST /rest1/product/getProductByCode/{SKU} → ürün okur
 *
 * Kimlik bilgileri CredentialVault/TenantIntegration üzerinden çözülür;
 * token, kullanıcı adı veya parola hiçbir mesaja/loga yazılmaz.
 */
final class TsoftWriteDriver
{
    /** Okuma sürücüsünün önbelleğiyle çakışmaması için ayrı önek. */
    private const TOKEN_CACHE_PREFIX = 'tsoft:write-token:';

    private readonly string $domain;

    private readonly string $username;

    private readonly string $password;

    public function __construct(
        private readonly int $companyId,
        private readonly CredentialVault $vault,
    ) {
        $integration = TenantIntegration::query()
            ->where('company_id', $this->companyId)
            ->where('channel', Channel::Tsoft->value)
            ->first();

        $this->domain = $this->resolveDomain($integration);
        $this->username = $this->vaultValue('username', ['user', 'api_user', 'rest_user']);
        $this->password = $this->vaultValue('password', ['pass', 'api_password', 'rest_password']);
    }

    /**
     * `data` JSON dizisiyle yeni ürün oluşturur. Yeni ürünler Tsoft'ta varsayılan
     * olarak pasif açıldığından çağıran `IsActive => true` göndermelidir.
     */
    public function createProduct(array $fields): array
    {
        return $this->guard(function () use ($fields): array {
            $payload = array_is_list($fields) ? $fields : [$fields];
            $response = $this->post('/product/setProducts', [
                'data' => $this->jsonData($payload),
            ]);

            return $this->writeResult($response, 'Ürün oluşturulamadı', 'Ürün oluşturuldu');
        }, 'catalog_write_enabled');
    }

    /** Yalnızca gönderilen fiyat alanını günceller (isim/stok/maliyet korunur). */
    public function updatePrice(string $sku, string $price, string $currency = ''): array
    {
        return $this->guard(function () use ($sku, $price, $currency): array {
            $row = ['ProductCode' => $sku, 'SellingPrice' => $price];
            if (trim($currency) !== '') {
                $row['Currency'] = $currency;
            }

            $response = $this->post('/product/updatePrice', [
                'data' => $this->jsonData([$row]),
            ]);

            return $this->writeResult($response, 'Fiyat yazılamadı', 'Fiyat yazıldı');
        }, 'catalog_write_enabled');
    }

    /** MUTLAK stok yazar. StockIncrease/StockDecrease asla kullanılmaz. */
    public function setStock(string $sku, string $quantity): array
    {
        return $this->guard(function () use ($sku, $quantity): array {
            $response = $this->post('/product/updateStock', [
                'data' => $this->jsonData([[
                    'ProductCode' => $sku,
                    'Stock' => $quantity,
                ]]),
            ]);

            return $this->writeResult($response, 'Stok yazılamadı', 'Stok yazıldı');
        }, 'stock_write_enabled');
    }

    /**
     * Salt okuma: dry_run/flag'lere bakılmaksızın çalışır.
     * Ürün bulunamazsa veya istek başarısızsa null döner, fırlatmaz.
     *
     * @return array<string,mixed>|null
     */
    public function readProduct(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        try {
            $response = $this->post('/product/getProductByCode/'.rawurlencode($sku));
            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            $row = null;
            if (is_array($json)) {
                foreach (['data', 'Data'] as $key) {
                    if (isset($json[$key]) && is_array($json[$key])) {
                        $candidate = array_is_list($json[$key]) ? ($json[$key][0] ?? null) : $json[$key];
                        if (is_array($candidate)) {
                            $row = $candidate;

                            break;
                        }
                    }
                }
            }
            if ($row === null) {
                return null;
            }

            // Yalnızca beyaz listedeki teknik alanlar döndürülür; ham satır
            // (kişisel veri içerebilecek başka alanlar) dışarı sızmaz.
            $safe = [];
            foreach ([
                'Stock', 'SellingPrice', 'BuyingPrice', 'ProductName', 'DefaultCategoryCode', 'IsActive',
                // Yalnızca okuma: yapı/görsel eşlemesi için teknik alanlar.
                'DefaultCategoryName', 'StockUnit', 'ImageUrl', 'Images',
            ] as $field) {
                $safe[$field] = $row[$field] ?? null;
            }

            return $safe;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tek yazma kapısı: dry_run VE ilgili kanal flag'i birlikte açık olmalı.
     * Kapalıysa hiç HTTP çağrısı yapılmaz; servis bu "skipped" işaretini
     * eşleme kaydı yazmamak için kullanır.
     */
    private function guard(callable $operation, string $featureFlag): array
    {
        if (
            config('integrations.dry_run') === true
            || config('integrations.channels.tsoft.'.$featureFlag) !== true
        ) {
            return ['ok' => true, 'skipped' => true, 'message' => 'dry_run veya yazma kapalı'];
        }

        try {
            $result = $operation();

            return is_array($result) ? $result : $this->failure('İşlem başarısız.');
        } catch (Throwable) {
            // Ham yanıt/payload asla mesaja eklenmez.
            return $this->failure('İşlem başarısız.');
        }
    }

    /** @return array<string,mixed> */
    private function writeResult(Response $response, string $failure, string $success): array
    {
        if (! $response->successful()) {
            return $this->failure($failure.' (HTTP '.$response->status().').');
        }

        $json = $response->json();
        if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
            return $this->failure($failure.'.');
        }

        return ['ok' => true, 'message' => $success, 'data' => is_array($json) ? $json : []];
    }

    /** @return array{ok: bool, message: string, data: array<string,mixed>} */
    private function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'data' => []];
    }

    /** asForm() ile JSON dizi göndermek için değer string olarak kodlanır. */
    private function jsonData(array $rows): string
    {
        $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    /** Token her çağrıda POST parametresi olarak eklenir. */
    private function post(string $path, array $params = []): Response
    {
        $params['token'] = $this->token();

        return $this->http()->asForm()->post($this->baseUrl().$path, $params);
    }

    /** Token, süresinin 5 dakika öncesine kadar önbellekte tutulur. */
    private function token(): string
    {
        $key = self::TOKEN_CACHE_PREFIX.$this->companyId;
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if ($this->domain === '' || $this->username === '' || $this->password === '') {
            throw new RuntimeException('Tsoft rest1 bağlantısı yapılandırılmamış.');
        }

        // Klasik rest1'de kullanıcı adı URL yolunda, parola `pass` alanında gider.
        $response = $this->http()->asForm()->post(
            $this->baseUrl().'/auth/login/'.rawurlencode($this->username),
            ['pass' => $this->password],
        );

        if (! $response->successful()) {
            // Parola asla mesaja eklenmez.
            throw new RuntimeException('Tsoft rest1 girişi başarısız (HTTP '.$response->status().').');
        }

        $bearer = $response->json('data.0.token') ?? $response->json('token');
        if (! is_string($bearer) || $bearer === '') {
            throw new RuntimeException('Tsoft rest1 giriş yanıtında erişim anahtarı bulunamadı.');
        }

        $expiration = $response->json('data.0.expirationTime') ?? $response->json('expirationTime') ?? $response->json('expiration_time');
        $expiresAt = is_numeric($expiration)
            ? (int) $expiration
            : (is_string($expiration) ? (strtotime($expiration) ?: 0) : 0);
        $ttl = max(60, ($expiresAt > 0 ? $expiresAt : time() + 3600) - time() - 300);

        Cache::put($key, $bearer, $ttl);

        return $bearer;
    }

    /** Yeniden deneme yalnızca 429 ve 5xx içindir. */
    private function http(): PendingRequest
    {
        return Http::timeout(60)
            ->connectTimeout(5)
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

    private function resolveDomain(?TenantIntegration $integration): string
    {
        if ($integration !== null) {
            foreach (['domain', 'base_url', 'store_domain', 'host', 'url'] as $key) {
                $value = $integration->getAttribute($key);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return $this->vaultValue('domain', ['host', 'base_url', 'store_domain', 'url']);
    }

    /** @param array<int,string> $aliases */
    private function vaultValue(string $key, array $aliases = []): string
    {
        foreach (array_merge([$key], $aliases) as $candidate) {
            try {
                $value = $this->vault->get($this->companyId, Channel::Tsoft, $candidate);
            } catch (Throwable) {
                continue;
            }

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }
}
