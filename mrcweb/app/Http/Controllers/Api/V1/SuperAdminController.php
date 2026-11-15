<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Gizli SüperAdmin Yönetim Üssü Controller'ı.
 * Yalnızca `EnsureSuperAdmin` (`superadmin.stealth`) katmanından geçen istekleri kabul eder.
 */
class SuperAdminController extends Controller
{
    /**
     * Tüm sistem özeti, firmalar, süper yöneticiler ve modül/eklenti kataloğu.
     */
    public function overview(Request $request)
    {
        $companies = Company::query()
            ->with('owner:id,name,email')
            ->withCount(['users' => function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('users.is_superadmin')->orWhere('users.is_superadmin', false);
                })
                ->whereDoesntHave('roles', fn ($r) => $r->where('name', 'SuperAdmin'));
            }])
            ->orderByRaw("CASE WHEN approval_status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Company $c) {
                $daysRemaining = null;
                if ($c->subscription_ends_at !== null) {
                    $daysRemaining = (int) ceil(now()->diffInSeconds($c->subscription_ends_at, false) / 86400);
                }

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'tax_number' => $c->tax_number,
                    'city' => $c->city,
                    'phone' => $c->phone,
                    'plan' => $c->plan ?: 'pro',
                    'approval_status' => $c->approval_status ?: 'active',
                    'subscription_ends_at' => $c->subscription_ends_at?->toIso8601String(),
                    'is_expired' => $c->isSubscriptionExpired(),
                    'days_remaining' => $daysRemaining,
                    'allowed_modules' => $c->allowed_modules,
                    'max_users' => $c->max_users ?: 25,
                    'custom_limits' => $c->custom_limits,
                    'effective_limits' => \App\Support\PlanLimits::for($c)->snapshot($c)['limits'],
                    'accepting_applications' => (bool) $c->accepting_applications,
                    'users_count' => $c->users_count,
                    'owner' => $c->owner ? [
                        'id' => $c->owner->id,
                        'name' => $c->owner->name,
                        'email' => $c->owner->email,
                    ] : null,
                    'created_at' => $c->created_at?->toIso8601String(),
                ];
            });

        $superAdmins = User::query()
            ->where('is_superadmin', true)
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'last_login_at', 'created_at']);

        $coreModules = [];
        foreach (ModuleRegistry::core() as $key => $def) {
            $coreModules[] = [
                'key' => $key,
                'name_tr' => $def['name_tr'],
                'name_en' => $def['name_en'],
                'pinned' => in_array($key, ModuleRegistry::PINNED, true),
            ];
        }

        $plugins = ModuleRegistry::scanPlugins();

        $availablePlans = [];
        foreach (config('plans.plans', []) as $pKey => $pDef) {
            $availablePlans[] = [
                'key' => $pKey,
                'label' => $pDef['label'] ?? $pKey,
                'limits' => $pDef['limits'] ?? [],
            ];
        }

        return response()->json([
            'stats' => [
                'total_companies' => $companies->count(),
                'pending_companies' => $companies->where('approval_status', 'pending')->count(),
                'active_companies' => $companies->where('approval_status', 'active')->where('is_expired', false)->count(),
                'expired_companies' => $companies->where('is_expired', true)->count(),
                'total_users' => User::count(),
                'superadmins_count' => $superAdmins->count(),
                'plugins_count' => count($plugins),
            ],
            'companies' => $companies,
            'superadmins' => $superAdmins,
            'core_modules' => $coreModules,
            'plugins' => $plugins,
            'available_plans' => $availablePlans,
        ]);
    }

    /**
     * Firma onay durumu, kullanım süresi (abonelik), modül kısıtlamaları ve kullanıcı/stok/belge limitlerini güncelle.
     */
    public function updateCompany(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'approval_status' => ['sometimes', 'string', Rule::in(['active', 'pending', 'suspended', 'rejected'])],
            'duration_preset' => ['nullable', 'string', Rule::in(['7d', '30d', '90d', '180d', '365d', 'unlimited', 'expired'])],
            'subscription_ends_at' => ['nullable'],
            'allowed_modules' => ['nullable', 'array'],
            'allowed_modules.*' => ['string'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'plan' => ['sometimes', 'string', 'max:40'],
            'custom_limits' => ['nullable', 'array'],
            'custom_limits.users' => ['nullable'],
            'custom_limits.products' => ['nullable'],
            'custom_limits.documents_per_month' => ['nullable'],
            'custom_limits.payment_links_per_month' => ['nullable'],
            'accepting_applications' => ['sometimes', 'boolean'],
        ]);

        $wasPending = $company->approval_status === 'pending';

        if (array_key_exists('name', $data)) {
            $company->name = $data['name'];
        }
        if (array_key_exists('approval_status', $data)) {
            $company->approval_status = $data['approval_status'];
        }
        if (array_key_exists('plan', $data)) {
            $company->plan = $data['plan'];
        }
        if (array_key_exists('accepting_applications', $data)) {
            $company->accepting_applications = $data['accepting_applications'];
        }

        // Kullanım Süresi (Lisans Bitiş Tarihi) Hesabı
        if (! empty($data['duration_preset'])) {
            $company->subscription_ends_at = match ($data['duration_preset']) {
                '7d' => now()->addDays(7),
                '30d' => now()->addDays(30),
                '90d' => now()->addDays(90),
                '180d' => now()->addDays(180),
                '365d' => now()->addDays(365),
                'expired' => now()->subDay(),
                'unlimited' => null,
                default => $company->subscription_ends_at,
            };
        } elseif (array_key_exists('subscription_ends_at', $data)) {
            $subEnd = $data['subscription_ends_at'];
            if ($subEnd === null || $subEnd === '' || $subEnd === 'unlimited') {
                $company->subscription_ends_at = null;
            } else {
                try {
                    $company->subscription_ends_at = \Carbon\Carbon::parse($subEnd);
                } catch (\Throwable) {
                    $company->subscription_ends_at = null;
                }
            }
        }

        // Modül Kısıtlamaları (pano her zaman açık)
        if (array_key_exists('allowed_modules', $data)) {
            $mods = $data['allowed_modules'];
            if (is_array($mods)) {
                if (! in_array('pano', $mods, true)) {
                    $mods[] = 'pano';
                }
                $company->allowed_modules = array_values(array_unique($mods));
            } else {
                $company->allowed_modules = null;
            }
        }

        // Özel Plan Limitleri (custom_limits: users, products, documents_per_month, payment_links_per_month)
        if (array_key_exists('custom_limits', $data)) {
            $rawLimits = $data['custom_limits'];
            if (is_array($rawLimits)) {
                $sanitized = [];
                foreach (['users', 'products', 'documents_per_month', 'payment_links_per_month'] as $resKey) {
                    if (array_key_exists($resKey, $rawLimits)) {
                        $val = $rawLimits[$resKey];
                        if ($val === null || $val === '' || $val === 'unlimited' || (is_string($val) && strtolower($val) === 'null')) {
                            $sanitized[$resKey] = null;
                        } else {
                            $sanitized[$resKey] = max(0, (int) $val);
                        }
                    }
                }
                $company->custom_limits = $sanitized;

                if (array_key_exists('users', $sanitized) && $sanitized['users'] !== null) {
                    $company->max_users = $sanitized['users'];
                }
            } else {
                $company->custom_limits = null;
            }
        }

        if (array_key_exists('max_users', $data) && $data['max_users'] !== null) {
            $company->max_users = (int) $data['max_users'];
            if (is_array($company->custom_limits)) {
                $cLimits = $company->custom_limits;
                $cLimits['users'] = $company->max_users;
                $company->custom_limits = $cLimits;
            }
        }

        $company->save();

        if ($wasPending && $company->approval_status === 'active') {
            AppNotification::pushToCompany(
                $company->id,
                __('Firma Hesabınız Onaylandı!'),
                __('Sistem yöneticisi firma başvurunuzu onayladı. Tüm yetkili modüllerinizi hemen kullanabilirsiniz.'),
                'success',
                '/',
                '#B9FF66'
            );
        }

        $daysRemaining = null;
        if ($company->subscription_ends_at !== null) {
            $daysRemaining = (int) ceil(now()->diffInSeconds($company->subscription_ends_at, false) / 86400);
        }

        return response()->json([
            'message' => __(':name firma ayarları ve limitleri güncellendi.', ['name' => $company->name]),
            'company' => array_merge($company->toArray(), [
                'is_expired' => $company->isSubscriptionExpired(),
                'days_remaining' => $daysRemaining,
                'effective_limits' => \App\Support\PlanLimits::for($company)->snapshot($company)['limits'],
            ]),
        ]);
    }

    /**
     * Firmayı sil.
     */
    public function destroyCompany(Request $request, Company $company)
    {
        $name = $company->name;
        $company->delete();

        return response()->json([
            'message' => __(':name firması sistemden kaldırıldı.', ['name' => $name]),
        ]);
    }

    /**
     * Aktif SüperAdmin'in kendi kullanıcı adı/e-posta ve şifresini güncellemesi.
     */
    public function updateCredentials(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'new_password' => ['nullable', 'string', 'min:8', 'max:120'],
        ]);

        $user->name = $data['name'];
        $user->email = strtolower(trim($data['email']));

        if (! empty($data['new_password'])) {
            $user->password = $data['new_password'];
        }

        $user->save();

        return response()->json([
            'message' => __('SüperAdmin güvenlik ve giriş bilgileriniz güncellendi.'),
            'user' => $user->only(['id', 'name', 'email', 'is_superadmin']),
        ]);
    }

    /**
     * Yeni bir SüperAdmin ekle veya mevcut kullanıcıya SüperAdmin yetkisi ver.
     */
    public function storeSuperAdmin(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        $email = strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'name' => $data['name'],
                'password' => $data['password'],
                'is_superadmin' => true,
                'user_class' => 'Süper Yönetici',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        } else {
            $firstCompanyId = Company::orderBy('id')->value('id');
            $user = new User([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
                'user_class' => 'Süper Yönetici',
                'job_title' => 'Sistem Yöneticisi',
                'avatar_color' => '#B9FF66',
                'current_company_id' => $firstCompanyId,
            ]);
            $user->forceFill([
                'is_superadmin' => true,
                'email_verified_at' => now(),
            ])->save();
        }

        return response()->json([
            'message' => __(':email SüperAdmin olarak yetkilendirildi.', ['email' => $user->email]),
            'superadmin' => $user->only(['id', 'name', 'email', 'last_login_at', 'created_at']),
        ], 201);
    }

    /**
     * Bir SüperAdmin yetkisini kaldır (sistemde en az 1 SüperAdmin kalması zorunludur).
     */
    public function revokeSuperAdmin(Request $request, User $user)
    {
        $count = User::where('is_superadmin', true)->count();
        if ($count <= 1) {
            return response()->json([
                'message' => __('Sistemde en az bir SüperAdmin kalmalıdır.'),
            ], 422);
        }

        $user->forceFill(['is_superadmin' => false])->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => __(':email kullanıcısının SüperAdmin yetkisi kaldırıldı.', ['email' => $user->email]),
        ]);
    }

    /**
     * Yeni bir modüler Plugin paketi oluştur (`plugins/<key>/manifest.json`) ve isteğe bağlı tüm firmalara kur.
     */
    public function createPlugin(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'name_tr' => ['required', 'string', 'max:80'],
            'name_en' => ['nullable', 'string', 'max:80'],
            'version' => ['nullable', 'string', 'max:20'],
            'widget_title_tr' => ['required', 'string', 'max:80'],
            'widget_description_tr' => ['nullable', 'string', 'max:160'],
            'widget_size' => ['nullable', 'string', Rule::in(['half', 'full'])],
            'install_all' => ['nullable', 'boolean'],
        ]);

        $key = $data['key'];
        $dir = base_path('plugins/'.$key);
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $manifest = [
            'key' => $key,
            'name_tr' => $data['name_tr'],
            'name_en' => $data['name_en'] ?: $data['name_tr'],
            'version' => $data['version'] ?: '1.0.0',
            'permissions' => [],
            'routes' => [],
            'migrations' => [],
            'menu' => ['/'],
            'widgets' => [
                [
                    'key' => str_replace('-', '_', $key).'_widget',
                    'title_key' => $data['widget_title_tr'],
                    'description_key' => $data['widget_description_tr'] ?: $data['name_tr'],
                    'default_enabled' => true,
                    'roles' => ['Admin', 'Personel'],
                    'size' => $data['widget_size'] ?: 'half',
                ],
            ],
            'config_schema' => [
                [
                    'key' => 'content',
                    'label_tr' => 'Eklenti İçeriği / Notu',
                    'label_en' => 'Plugin Content / Note',
                    'type' => 'textarea',
                ],
            ],
        ];

        File::put($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (! empty($data['install_all'])) {
            foreach (Company::all() as $comp) {
                Module::updateOrCreate(
                    ['company_id' => $comp->id, 'key' => $key],
                    [
                        'enabled' => true,
                        'version' => $manifest['version'],
                        'source' => Module::SOURCE_LOCAL,
                        'installed_at' => now(),
                    ]
                );
            }
        }

        return response()->json([
            'message' => __(':name eklentisi oluşturuldu ve sisteme eklendi.', ['name' => $data['name_tr']]),
            'plugin' => $manifest,
        ], 201);
    }
}
