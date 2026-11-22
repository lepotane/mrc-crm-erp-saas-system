<?php

namespace App\Integration\Tsoft;

use App\Integration\Canonical\CanonicalOrder;
use App\Integration\Canonical\CanonicalPayment;
use App\Integration\Canonical\CanonicalPrice;
use App\Integration\Canonical\CanonicalStock;
use App\Integration\Canonical\SyncResult;
use App\Integration\Contracts\ChannelAdapterInterface;
use App\Integration\Contracts\OrderSourceInterface;
use App\Integration\Contracts\PaymentSyncInterface;
use App\Integration\Contracts\PriceSyncInterface;
use App\Integration\Contracts\ShipmentSyncInterface;
use App\Integration\Contracts\StockSyncInterface;
use App\Integration\Enums\Channel;
use App\Integration\Enums\Driver;
use App\Integration\Support\CredentialVault;
use App\Models\Company;
use App\Models\TenantIntegration;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Tsoft kanal adapter'ı. Somut HTTP uygulaması mağaza kaydındaki `driver`
 * alanına göre seçilir: rest1 (eski) veya admin2026-01 (yeni).
 */
final class TsoftAdapter implements ChannelAdapterInterface, OrderSourceInterface, PaymentSyncInterface, PriceSyncInterface, ShipmentSyncInterface, StockSyncInterface
{
    private const OPERATIONS = [
        'fetch_orders',
        'fetch_products',
        'fetch_all_products',
        'fetch_stock',
        'push_stock',
        'push_price',
        'push_payment',
        'push_payment_note',
        'push_shipment',
        'push_status',
    ];

    private readonly int $companyId;

    private ?TenantIntegration $integration = null;

    private bool $integrationLoaded = false;

    private LegacyRest1Driver|Admin2026Driver|null $resolvedDriver = null;

    public function __construct(
        Company|int $company,
        private readonly CredentialVault $vault,
    ) {
        $this->companyId = $company instanceof Company ? (int) $company->id : $company;
    }

    public function channel(): Channel
    {
        return Channel::Tsoft;
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, self::OPERATIONS, true);
    }

    /** @return array<int, CanonicalOrder> */
    public function fetchOrders(?CarbonInterface $since = null, int $limit = 100): array
    {
        return $this->driver()->fetchOrders($since, $limit);
    }

    public function fetchOrder(string $externalRef): ?CanonicalOrder
    {
        return $this->driver()->fetchOrder($externalRef);
    }

    /**
     * Ürün fiyatlarını çözülmüş sürücüden okur; desteklenmiyorsa boş dizi.
     *
     * @param  array<int,mixed>  $skus
     * @return array<int,mixed>
     */
    public function fetchProducts(array $skus): array
    {
        try {
            $driver = $this->driver();
        } catch (Throwable) {
            return [];
        }

        if (! is_object($driver) || ! method_exists($driver, 'fetchProducts')) {
            return [];
        }

        try {
            $rows = $driver->fetchProducts($skus);
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Filtresiz TAM katalog okuma (sync diff taraması için). YALNIZCA OKUMA.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAllProducts(): array
    {
        try {
            $driver = $this->driver();
        } catch (Throwable) {
            return [];
        }

        if (! is_object($driver) || ! method_exists($driver, 'fetchAllProducts')) {
            return [];
        }

        try {
            $rows = $driver->fetchAllProducts();
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    public function fetchStock(string $sku, ?string $warehouseCode = null): ?CanonicalStock
    {
        return $this->driver()->fetchStock($sku, $warehouseCode);
    }

    public function pushStock(CanonicalStock $stock): SyncResult
    {
        return $this->guard(fn () => $this->driver()->pushStock($stock));
    }

    public function pushPrice(CanonicalPrice $price): SyncResult
    {
        return $this->guard(fn () => $this->driver()->pushPrice($price));
    }

    public function pushPayment(CanonicalPayment $payment): SyncResult
    {
        return $this->guard(fn () => $this->driver()->pushPayment($payment));
    }

    /** Doğrulanmış not yazımı; drivera göre ödeme yazımına da yönlendirilebilir. */
    public function pushPaymentNote(CanonicalPayment $payment, ?string $note = null): SyncResult
    {
        return $this->guard(function () use ($payment, $note): SyncResult {
            $driver = $this->driver();

            if (! method_exists($driver, 'pushPaymentNote')) {
                return SyncResult::failure('pushPaymentNote desteklenmiyor');
            }

            return $driver->pushPaymentNote($payment, $note);
        });
    }

    public function pushShipment(CanonicalOrder $order): SyncResult
    {
        return $this->guard(fn () => $this->driver()->pushShipment($order));
    }

    public function pushStatus(string $externalRef, string $mrcStatus): SyncResult
    {
        return $this->guard(fn () => $this->driver()->pushStatus($externalRef, $mrcStatus));
    }

    private function driver(): LegacyRest1Driver|Admin2026Driver
    {
        if ($this->resolvedDriver !== null) {
            return $this->resolvedDriver;
        }

        $integration = $this->integration();
        $driver = Driver::tryFrom(strtolower(
            (string) $this->attribute($integration, ['driver'], Driver::Rest1->value)
        )) ?? Driver::Rest1;

        $domain = (string) ($this->attribute($integration, [
            'domain', 'base_url', 'store_domain', 'host', 'url',
        ]) ?? $this->vaultValue('domain') ?? '');

        $this->resolvedDriver = match ($driver) {
            Driver::Admin2026 => new Admin2026Driver(
                $this->companyId,
                $domain,
                $this->vaultValue('clientId') ?? '',
                $this->vaultValue('clientSecret') ?? '',
            ),
            default => new LegacyRest1Driver(
                $this->companyId,
                $domain,
                $this->vaultValue('username') ?? '',
                $this->vaultValue('password') ?? '',
            ),
        };

        return $this->resolvedDriver;
    }

    private function integration(): TenantIntegration
    {
        if (! $this->integrationLoaded) {
            $this->integration = TenantIntegration::query()
                ->where('company_id', $this->companyId)
                ->where('channel', Channel::Tsoft->value)
                ->first();
            $this->integrationLoaded = true;
        }

        if (! $this->integration instanceof TenantIntegration) {
            throw new \RuntimeException('Bu firma için Tsoft entegrasyonu yapılandırılmamış.');
        }

        return $this->integration;
    }

    /** @param array<int,string> $keys */
    private function attribute(TenantIntegration $integration, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = $integration->getAttribute($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Platform tarafından sağlanan CredentialVault'un somut erişim yöntemi
     * çalışma anında çözülür (get/field/value/secret/decrypt, ArrayAccess veya
     * toArray). Sır değerleri günlüğe yazılmaz, yalnızca driver'a iletilir.
     */
    private function vaultValue(string $key): ?string
    {
        $aliases = [
            'username' => ['user', 'api_user', 'rest_user'],
            'password' => ['pass', 'api_password', 'rest_password'],
            'clientId' => ['client_id', 'clientid'],
            'clientSecret' => ['client_secret', 'clientsecret'],
            'domain' => ['host', 'base_url', 'store_domain', 'url'],
        ];

        $candidates = array_merge([$key], $aliases[$key] ?? []);

        foreach ($candidates as $candidate) {
            foreach (['get', 'field', 'value', 'secret', 'decrypt'] as $method) {
                if (! is_callable([$this->vault, $method])) {
                    continue;
                }

                try {
                    $value = $this->vault->{$method}($candidate);
                } catch (Throwable) {
                    continue;
                }

                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }

            if ($this->vault instanceof \ArrayAccess && isset($this->vault[$candidate])) {
                $value = $this->vault[$candidate];
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        if (is_callable([$this->vault, 'toArray'])) {
            try {
                $all = $this->vault->toArray();
            } catch (Throwable) {
                $all = [];
            }

            if (is_array($all)) {
                foreach ($candidates as $candidate) {
                    $value = $all[$candidate] ?? null;
                    if (is_scalar($value) && (string) $value !== '') {
                        return (string) $value;
                    }
                }
            }
        }

        return null;
    }

    /** Tüm yazım sonuçlarını SyncResult olarak normalize eder. */
    private function guard(callable $operation): SyncResult
    {
        try {
            $result = $operation();

            return $result instanceof SyncResult
                ? $result
                : SyncResult::failure('Tsoft işlemi beklenmeyen bir sonuç döndürdü.');
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Tsoft')) {
                return SyncResult::failure($e->getMessage());
            }

            return SyncResult::failure('Tsoft işlemi başarısız.');
        }
    }
}
