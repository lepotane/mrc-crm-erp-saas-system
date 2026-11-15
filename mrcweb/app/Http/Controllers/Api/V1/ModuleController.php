<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Modules\ModuleRegistry;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Modüller + eklentiler .
 *
 * - `GET /modules` — herkes: çekirdek modüller + kurulu eklentiler + keşfedilip
 *   kurulmamış eklentiler (`installed: false`). `config` YALNIZ Admin'e döner
 *   (üye menü/ızgara için bayrakları görür, ayar içeriğini görmez).
 * - `PATCH /modules/{key}` — YALNIZ Admin: aç/kapat + config. `pano`
 *   kapatılamaz (422); bilinmeyen anahtar 404.
 * - `POST /plugins/discover` — YALNIZ Admin: eklenti bildirimi taraması.
 * - `POST /plugins/install` — YALNIZ Admin: keşfedilmiş eklentiyi firmaya kurar
 *   (satır yazar, `migrations/` dizini varsa çalıştırır).
 *
 * Kapatma VERİYİ silmez: satır ve modül kayıtları durur, yeniden açma kayıpsız döner.
 */
class ModuleController extends Controller
{
    use Concerns\ResolvesTenant;

    /**
     * Modül listesi (çekirdek + eklentiler; `config` yalnız Admin'e).
     *
     * @response array{data: array<int, array{key: string, name_tr: string, name_en: string, version: string, enabled: bool, source: string, installed: bool, menu: array, widgets: array}>}
     */
    public function index(Request $request)
    {
        $company = $this->tenantCompany($request);
        $isAdmin = $request->user()->hasRole('Admin');

        return response()->json(['data' => $this->listing($company->id, $isAdmin)]);
    }

    /**
     * Modülü aç/kapat + config yaz (YALNIZ Admin).
     *
     * @response array{message: string, module: array{key: string, enabled: bool}}
     */
    public function update(Request $request, string $key)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);

        if (! ModuleRegistry::isKnown($key)) {
            abort(404, __('Modül bulunamadı.'));
        }

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
        ]);

        if (in_array($key, ModuleRegistry::PINNED, true) && array_key_exists('enabled', $data) && $data['enabled'] === false) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'enabled' => [__('Bu modül kapatılamaz.')],
            ]);
        }

        if (is_array($company->allowed_modules) && ! in_array($key, $company->allowed_modules, true) && $key !== 'pano') {
            abort(403, __('Bu modül firma paketinizde sistem yöneticisi tarafından kısıtlanmıştır.'));
        }

        $row = Module::where('company_id', $company->id)->where('key', $key)->first();

        if ($row === null) {
            $core = ModuleRegistry::core()[$key] ?? null;

            $row = Module::create([
                'company_id' => $company->id,
                'key' => $key,
                'enabled' => $data['enabled'] ?? true,
                'config' => $data['config'] ?? null,
                'version' => $core['version'] ?? '1.0.0',
                'source' => Module::SOURCE_CORE,
            ]);
        } else {
            $row->fill(array_filter($data, static fn ($v) => $v !== null))->save();
        }

        Audit::fromRequest($request, 'module.updated', $company->id, $row, [
            'changed' => ['module:'.$key => ['enabled' => $row->enabled]],
        ]);

        return response()->json([
            'module' => ['key' => $row->key, 'enabled' => $row->enabled],
            'message' => __('Modül güncellendi.'),
        ]);
    }

    /**
     * Eklenti bildirimi tara (`plugins/` altı).
     *
     * @response array{data: array<int, array{key: string, name_tr: string, version: string, installed: bool}>, message: string}
     */
    public function discover(Request $request)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);
        $found = [];

        foreach (ModuleRegistry::scanPlugins() as $manifest) {
            $installed = Module::where('company_id', $company->id)
                ->where('key', $manifest['key'])
                ->exists();

            $found[] = [
                'key' => $manifest['key'],
                'name_tr' => $manifest['name_tr'],
                'version' => $manifest['version'],
                'installed' => $installed,
            ];
        }

        return response()->json([
            'data' => $found,
            'message' => __('Eklenti taraması tamamlandı.'),
        ]);
    }

    /**
     * Keşfedilmiş eklentiyi firmaya kur (YALNIZ Admin).
     *
     * @response array{message: string, module: array{key: string, enabled: bool}}
     */
    public function install(Request $request)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
        ]);

        $manifest = ModuleRegistry::manifestFor($data['key']);

        if ($manifest === null) {
            abort(404, __('Eklenti bulunamadı.'));
        }

        $row = Module::updateOrCreate(
            ['company_id' => $company->id, 'key' => $manifest['key']],
            [
                'enabled' => true,
                'version' => $manifest['version'],
                'source' => Module::SOURCE_LOCAL,
                'installed_at' => now(),
            ]
        );

        $migrationsDir = base_path('plugins/'.$manifest['key'].'/migrations');

        if (is_dir($migrationsDir)) {
            Artisan::call('migrate', ['--path' => 'plugins/'.$manifest['key'].'/migrations', '--force' => true]);
        }

        Audit::fromRequest($request, 'plugin.installed', $company->id, $row, [
            'changed' => ['plugin:'.$manifest['key'] => ['version' => $manifest['version']]],
        ]);

        return response()->json([
            'module' => ['key' => $row->key, 'enabled' => $row->enabled],
            'message' => __('Eklenti kuruldu.'),
        ]);
    }

    /**
     * Kurulu eklentiyi firmadan kaldır (YALNIZ Admin).
     *
     * @response array{message: string}
     */
    public function uninstall(Request $request, string $key)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);

        Module::where('company_id', $company->id)
            ->where('key', $key)
            ->where('source', Module::SOURCE_LOCAL)
            ->delete();

        return response()->json([
            'message' => __('Eklenti kaldırıldı.'),
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    protected function listing(int $companyId, bool $isAdmin): array
    {
        $company = \App\Models\Company::find($companyId);
        $allowed = is_array($company?->allowed_modules) ? $company->allowed_modules : null;
        $rows = Module::where('company_id', $companyId)->get()->keyBy('key');
        $out = [];

        foreach (ModuleRegistry::core() as $key => $def) {
            $row = $rows->get($key);
            $restricted = $allowed !== null && $key !== 'pano' && ! in_array($key, $allowed, true);

            $item = [
                'key' => $key,
                'name_tr' => $def['name_tr'],
                'name_en' => $def['name_en'],
                'version' => $row?->version ?? $def['version'],
                'enabled' => $restricted ? false : ($row === null ? true : (bool) $row->enabled),
                'restricted' => $restricted,
                'source' => Module::SOURCE_CORE,
                'installed' => true,
                'menu' => $def['menu'],
                'widgets' => $def['widgets'],
            ];

            if ($isAdmin) {
                $item['config'] = $row?->config;
            }

            $out[] = $item;
        }

        foreach (ModuleRegistry::scanPlugins() as $manifest) {
            $row = $rows->get($manifest['key']);

            $item = [
                'key' => $manifest['key'],
                'name_tr' => $manifest['name_tr'],
                'name_en' => $manifest['name_en'],
                'version' => $row?->version ?? $manifest['version'],
                'enabled' => $row === null ? false : (bool) $row->enabled,
                'source' => Module::SOURCE_LOCAL,
                'installed' => $row !== null,
                'menu' => $manifest['menu'],
                'widgets' => array_column($manifest['widgets'], 'key'),
                'config_schema' => $manifest['config_schema'],
            ];

            if ($isAdmin) {
                $item['config'] = $row?->config;
            }

            $out[] = $item;
        }

        return $out;
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
