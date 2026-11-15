<?php

namespace App\Modules;

use App\Models\Company;
use App\Models\Module;
use Illuminate\Support\Facades\File;

/**
 * Modül kayıt defteri .
 *
 * Çekirdek modüller KODDA tanımlıdır (veritabanı tohumu yok): satır yoksa modül
 * AÇIK varsayılır, bu yüzden eski firmalar ve `RegistersCompany` akışı değişmeden
 * çalışır. Eklentiler `mrcweb/plugins/<anahtar>/manifest.json` ile keşfedilir;
 * kurulum firma başına satır yazar (`source=local`).
 *
 * Kapatılamayan tek modül `pano` dur (ızgara her zaman açık; Ayarlar da öyle).
 * `satislar` + `alimlar` menüleri TEK `belgeler` modülüne bağlıdır çünkü
 * `/documents` ucu tipleriyle birlikte paylaşılır (teklif/sipariş/fatura/alım
 * ayrımı uçta değil menüdedir).
 */
class ModuleRegistry
{
    /** Kapatılamaz: pano ızgarası her zaman açık. */
    public const PINNED = ['pano'];

    /**
     * @return array<string, array{name_tr: string, name_en: string, version: string, menu: array<int,string>, widgets: array<int,string>}>
     */
    public static function core(): array
    {
        return [
            'pano' => [
                'name_tr' => 'Panel', 'name_en' => 'Dashboard', 'version' => '1.0.0',
                'menu' => ['/'], 'widgets' => ['kpi', 'revenue_trend', 'due_calendar', 'fx_rates', 'low_stock', 'recent_activity', 'debt_summary'],
            ],
            'cariler' => [
                'name_tr' => 'Cariler', 'name_en' => 'Parties', 'version' => '1.0.0',
                'menu' => ['/musteriler'], 'widgets' => [],
            ],
            'urunler' => [
                'name_tr' => 'Ürünler', 'name_en' => 'Products', 'version' => '1.0.0',
                'menu' => ['/urunler'], 'widgets' => [],
            ],
            'stok' => [
                'name_tr' => 'Stok', 'name_en' => 'Inventory', 'version' => '1.0.0',
                'menu' => ['/stok'], 'widgets' => ['low_stock'],
            ],
            'belgeler' => [
                'name_tr' => 'Belgeler', 'name_en' => 'Documents', 'version' => '1.0.0',
                'menu' => ['/satislar', '/alimlar'], 'widgets' => [],
            ],
            'borc-alacak' => [
                'name_tr' => 'Borç-Alacak', 'name_en' => 'Receivables & Payables', 'version' => '1.0.0',
                'menu' => ['/borc-alacak'], 'widgets' => ['debt_summary'],
            ],
            'raporlar' => [
                'name_tr' => 'Raporlar', 'name_en' => 'Reports', 'version' => '1.0.0',
                'menu' => ['/raporlar'], 'widgets' => [],
            ],
            'odeme' => [
                'name_tr' => 'Online Tahsilat', 'name_en' => 'Online Payments', 'version' => '1.0.0',
                'menu' => [], 'widgets' => [],
            ],
            'doviz' => [
                'name_tr' => 'Döviz', 'name_en' => 'Currencies', 'version' => '1.0.0',
                'menu' => [], 'widgets' => ['fx_rates'],
            ],
            'ekip' => [
                'name_tr' => 'Ekip', 'name_en' => 'Team', 'version' => '1.0.0',
                'menu' => ['/kullanicilar'], 'widgets' => [],
            ],
            'pos' => [
                'name_tr' => 'Hızlı Satış', 'name_en' => 'Quick Sale', 'version' => '1.0.0',
                'menu' => ['/hizli-satis'], 'widgets' => [],
            ],
        ];
    }

    /** İstek yol öneki → modül anahtarı (uzundan kısaya eşleşir). */
    public static function moduleForPath(string $relativePath): ?string
    {
        $rel = trim($relativePath, '/');

        // Firma üyelik uçları modül dışıdır; yalnız ekip listesi `ekip` modülüne bağlıdır.
        if ($rel === 'company/members' || str_starts_with($rel, 'company/members/')) {
            return 'ekip';
        }

        $map = [
            'payment-settings' => 'odeme',
            'payment-links' => 'odeme',
            'exchange-rates' => 'doviz',
            'stock-movements' => 'stok',
            'bank-accounts' => 'borc-alacak',
            'stock-transfers' => 'stok',
            'stock-counts' => 'stok',
            'invitations' => 'ekip',
            'currencies' => 'doviz',
            'categories' => 'urunler',
            'documents' => 'belgeler',
            'quick-sales' => 'pos',
            'print-jobs' => 'pos',
            'devices' => 'pos',
            'bridge' => 'pos',
            'print-templates' => 'belgeler',
            'parties' => 'cariler',
            'products' => 'urunler',
            'reports' => 'raporlar',
            'warehouses' => 'stok',
            'payments' => 'borc-alacak',
            'dashboard' => 'pano',
            'units' => 'urunler',
        ];

        foreach ($map as $prefix => $key) {
            if ($rel === $prefix || str_starts_with($rel, $prefix.'/')) {
                return $key;
            }
        }

        return null;
    }

    /** Satır yoksa AÇIK (lazy default); satır varsa enabled kararı odur. SüperAdmin kısıtlaması (`allowed_modules`) varsa önceliklidir. */
    public static function isEnabled(?int $companyId, string $key): bool
    {
        if ($companyId === null) {
            return true;
        }

        if ($key !== 'pano') {
            $company = Company::find($companyId);
            if ($company && is_array($company->allowed_modules)) {
                if (! in_array($key, $company->allowed_modules, true)) {
                    return false;
                }
            }
        }

        $row = Module::where('company_id', $companyId)->where('key', $key)->first();

        return $row === null ? true : (bool) $row->enabled;
    }

    public static function isKnown(string $key): bool
    {
        if (isset(static::core()[$key])) {
            return true;
        }

        foreach (static::scanPlugins() as $plugin) {
            if ($plugin['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kurulu eklentinin manifesti (kurulu değilse null).
     *
     * @return array<string,mixed>|null
     */
    public static function installedManifest(int $companyId, string $key): ?array
    {
        $row = Module::where('company_id', $companyId)
            ->where('key', $key)
            ->where('source', Module::SOURCE_LOCAL)
            ->first();

        if ($row === null) {
            return null;
        }

        return static::manifestFor($key);
    }

    /** Eklenti manifestlerini doğrula + listele (kurulum durumundan bağımsız). */
    public static function scanPlugins(): array
    {
        $dir = base_path('plugins');

        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach (File::directories($dir) as $pluginDir) {
            $manifest = static::manifestFor(basename((string) $pluginDir));

            if ($manifest !== null) {
                $out[] = $manifest;
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $out;
    }

    /**
     * Tek manifesti oku + doğrula (bozuksa null — keşif listesini bozmaz).
     *
     * @return array<string,mixed>|null
     */
    public static function manifestFor(string $key): ?array
    {
        $file = base_path('plugins/'.$key.'/manifest.json');

        if (! is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) File::get($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        if (($data['key'] ?? null) !== $key) {
            return null;
        }

        if (! is_string($data['name_tr'] ?? null) || ($data['name_tr'] === '')) {
            return null;
        }

        if (! is_string($data['version'] ?? null) || ($data['version'] === '')) {
            return null;
        }

        $widgets = $data['widgets'] ?? [];

        if (! is_array($widgets)) {
            return null;
        }

        foreach ($widgets as $w) {
            if (! is_array($w)
                || ! is_string($w['key'] ?? null) || ($w['key'] === '')
                || ! is_string($w['title_key'] ?? null)
                || ! is_array($w['roles'] ?? null) || ($w['roles'] === [])
            ) {
                return null;
            }
        }

        return [
            'key' => $key,
            'name_tr' => $data['name_tr'],
            'name_en' => $data['name_en'] ?? $data['name_tr'],
            'version' => $data['version'],
            'permissions' => $data['permissions'] ?? [],
            'routes' => $data['routes'] ?? [],
            'migrations' => $data['migrations'] ?? [],
            'menu' => $data['menu'] ?? [],
            'widgets' => array_values($widgets),
            'config_schema' => $data['config_schema'] ?? [],
        ];
    }

    /**
     * Açık eklentilerin widget tanımları .
     *
     * @return array<string, array{title_key: string, description_key: string, default_enabled: bool, roles: array<int,string>, size: string}>
     */
    public static function pluginWidgets(Company $company): array
    {
        $out = [];

        $rows = Module::where('company_id', $company->id)
            ->where('source', Module::SOURCE_LOCAL)
            ->where('enabled', true)
            ->get();

        foreach ($rows as $row) {
            $manifest = static::manifestFor((string) $row->key);

            if ($manifest === null) {
                continue;
            }

            foreach ($manifest['widgets'] as $w) {
                $out[(string) $w['key']] = [
                    'title_key' => (string) $w['title_key'],
                    'description_key' => (string) ($w['description_key'] ?? ''),
                    'default_enabled' => (bool) ($w['default_enabled'] ?? true),
                    'roles' => array_values($w['roles']),
                    'size' => (string) ($w['size'] ?? 'half'),
                    'module' => (string) $row->key,
                ];
            }
        }

        return $out;
    }

    /** Eklenti widget verisi: modül config'inden okunur (tablo yok, kurulumsuz örnek). */
    public static function pluginWidgetData(string $widgetKey, Company $company): ?array
    {
        $rows = Module::where('company_id', $company->id)
            ->where('source', Module::SOURCE_LOCAL)
            ->where('enabled', true)
            ->get();

        foreach ($rows as $row) {
            $manifest = static::manifestFor((string) $row->key);

            if ($manifest === null) {
                continue;
            }

            foreach ($manifest['widgets'] as $w) {
                if (($w['key'] ?? null) === $widgetKey) {
                    $config = $row->config ?? [];

                    return ['text' => (string) ($config['text'] ?? '')];
                }
            }
        }

        return null;
    }
}
