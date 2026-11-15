<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class MeController extends Controller
{
    /**
     * Profil: kullanıcı, seçili firma, firmalar, roller ve dil tercihleri
     *
     * Oturum özeti: kullanıcı (aktif firma rolleriyle), aktif firma, firmalar listesi.
     *
     * @response array{
     *     user: array{id: int, name: string, email: string, current_company_id: int, last_login_at: string, locale: string, roles: array<int, string>},
     *     company: array{id: int, name: string, slug: string, currency_code: string},
     *     companies: array<int,
     *     array{id: int, name: string, slug: string, pivot: array{user_id: int, company_id: int, created_at: string, updated_at: string}}>,
     *     locale: string,
     *     locales: array<int,
     *     string>,
     *     locale_formats: array{tr: string, en: string},
     * }
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $company = $user->currentCompany;

        return response()->json([
            'user' => array_merge(
                $user->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'address',
                    'user_class',
                    'job_title',
                    'avatar_color',
                    'current_company_id',
                    'last_login_at',
                    'locale',
                ]),
                [
                    'roles' => $user->isSuperAdmin()
                        ? $user->getRoleNames()->push('Admin')->unique()->values()
                        : $user->getRoleNames(),
                    'email_verified' => $user->hasVerifiedEmail(),
                    'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                    'two_factor_pending' => $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled(),
                    'is_superadmin' => $user->isSuperAdmin(),
                ]
            ),
            'company' => $company ? array_merge(
                $company->only([
                    'id',
                    'name',
                    'slug',
                    'currency_code',
                    'plan',
                    'approval_status',
                    'subscription_ends_at',
                    'allowed_modules',
                    'max_users',
                    'custom_limits',
                    'accepting_applications',
                ]),
                [
                    'is_expired' => $company->isSubscriptionExpired(),
                ]
            ) : null,
            'companies' => $user->isSuperAdmin()
                ? Company::orderBy('name')->get(['id', 'name', 'slug'])
                : $user->companies()->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.slug']),
            // SPA'nın dil sözlüğü + Intl biçimlendirmesi için aktif dil bilgisi
            'locale' => app()->getLocale(),
            'locales' => config('i18n.supported'),
            'locale_formats' => config('i18n.formats'),
        ]);
    }

    /**
     * Arayüz dilini değiştir (tr / en) — tercihe göre tüm metinler çevrilir
     *
     * Kullanıcının dilini kalıcı olarak değiştirir (Ayarlar → Dil).
     *
     * @response array{
     *     user: array{id: int, name: string, email: string, current_company_id: int, locale: string, roles: array<int, string>},
     *     locale: string,
     *     locale_format: string,
     * }
     */
    public function updateLocale(Request $request)
    {
        $supported = config('i18n.supported', ['tr']);

        $data = $request->validate([
            'locale' => ['required', 'string', 'in:' . implode(',', $supported)],
        ]);

        $user = $request->user();
        $user->forceFill(['locale' => $data['locale']])->save();
        app()->setLocale($data['locale']);

        return response()->json([
            'user' => array_merge(
                $user->only(['id', 'name', 'email', 'current_company_id', 'locale']),
                ['roles' => $user->getRoleNames()]
            ),
            'locale' => $user->locale,
            'locale_format' => config('i18n.formats')[$user->locale] ?? 'tr-TR',
        ]);
    }

    /**
     * Üyesi olunan firmalar listesi
     *
     * @response array{companies: array<int, array{id: int, name: string, slug: string, pivot: array{user_id: int, company_id: int, created_at: string, updated_at: string}}>}
     */
    public function companies(Request $request)
    {
        $user = $request->user();
        $companies = $user->isSuperAdmin()
            ? Company::orderBy('name')->get(['id', 'name', 'slug'])
            : $user->companies()->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.slug']);

        return response()->json(['companies' => $companies]);
    }

    /**
     * Seçili firmayı değiştir (tüm işlemler bu firma bağlamında yürür)
     *
     * Aktif firmayı değiştirir (üyelik doğrulanır).
     *
     * @response array{user: array{id: int, name: string, email: string, current_company_id: int, roles: array<int, string>}, company: array{id: int, name: string, slug: string, currency_code: string}}
     */
    public function select(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $user = $request->user();

        $isMember = $user->companies()->whereKey($data['company_id'])->exists();
        if (! $isMember && ! $user->isSuperAdmin()) {
            return response()->json(['message' => __('Bu firmanın üyesi değilsiniz.')], 403);
        }

        $user->forceFill(['current_company_id' => $data['company_id']])->save();
        $company = $user->currentCompany()->first();

        // Seçilen firma = yeni spatie team bağlamı; roller tenant-bazlı okunmalı.
        // Relationship cache düşerek çalışır: yüklenmişse tazele.
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->unsetRelation('roles');

        return response()->json([
            'user' => array_merge(
                $user->only(['id', 'name', 'email', 'current_company_id']),
                ['roles' => $user->getRoleNames()]
            ),
            'company' => $company?->only(['id', 'name', 'slug', 'currency_code']),
        ]);
    }

    /**
     * Kullanıcı profil bilgilerini ve şifresini güncelle
     *
     * Oturum açmış kullanıcının ad-soyad, telefon, adres, unvan, avatar rengi ve isteğe bağlı şifresini günceller.
     *
     * @response array{user: array{id: int, name: string, email: string, phone: string|null, address: string|null, user_class: string, job_title: string|null, avatar_color: string|null, roles: array<int, string>}}
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', 'unique:users,email,' . ($user?->id ?? 0)],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'user_class' => ['sometimes', 'string', 'max:60'],
            'avatar_color' => ['nullable', 'string', 'max:32'],
            'current_password' => ['nullable', 'required_with:new_password', 'current_password'],
            'new_password' => ['nullable', 'string', 'min:8', 'max:120'],
        ]);

        $fillable = array_intersect_key($data, array_flip([
            'name', 'email', 'phone', 'address', 'job_title', 'user_class', 'avatar_color',
        ]));

        if (! empty($data['new_password'])) {
            $fillable['password'] = $data['new_password'];
        }

        $user->fill($fillable)->save();

        return response()->json([
            'user' => array_merge(
                $user->only([
                    'id', 'name', 'email', 'phone', 'address',
                    'user_class', 'job_title', 'avatar_color', 'current_company_id', 'locale',
                ]),
                ['roles' => $user->getRoleNames()]
            ),
        ]);
    }
}
