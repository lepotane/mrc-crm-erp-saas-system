<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bir kullanıcıyı yeni bir firmaya (tenant) bağlarken kurulan ortak yapı.
 *
 * Kayıt ucu (`POST /auth/register`) ile kurulum aracı (`mrc:create-admin`) aynı
 * şeyi kurmak zorunda; ikisi ayrı yazıldığında bir süre sonra biri eksik kalır.
 * Bu yüzden firma + varsayılan depo/birim + üyelik + rol tanımı TEK yerde durur.
 *
 * Rol tanımı spatie "team" modeliyledir: firma = team, roller (`Admin`, `Personel`)
 * firmaya göredir. `guard_name` açıkça `web` verilir; `auth.defaults.guard` uzun
 * ömürlü süreçlerde (Octane/test) `auth:sanctum` tarafından `sanctum`'a
 * mutasyona uğrayabiliyor ve User-temelli rol aramaları `web` bekliyor.
 */
class TenantProvisioner
{
    /**
     * Kullanıcıya yeni bir firma kurar, varsayılanları açar ve Admin yapar.
     */
    public function provision(User $user, string $companyName, ?string $currencyCode = null): Company
    {
        $company = Company::create(array_filter([
            'owner_id' => $user->id,
            'name' => $companyName,
            'slug' => $this->uniqueSlug($companyName),
            'currency_code' => $currencyCode,
        ], static fn ($v) => $v !== null));

        // Faz 2: her firmada varsayılan depo ve birim hazır olsun (v1 tek depo modu).
        Warehouse::create(['company_id' => $company->id, 'name' => 'Ana Depo', 'is_default' => true]);
        Unit::create(['company_id' => $company->id, 'name' => 'Adet', 'code' => 'adet', 'is_default' => true]);

        $user->companies()->syncWithoutDetaching([$company->id]);
        $user->forceFill(['current_company_id' => $company->id])->save();

        $this->defineRoles($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->assignRole('Admin');

        return $company;
    }

    /**
     * Firmanın iki rolünü (Admin, Personel) oluşturur; varsa dokunmaz.
     */
    public function defineRoles(Company $company): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        foreach (['Admin', 'Personel'] as $ad) {
            if (! Role::where('team_id', $company->id)->where('name', $ad)->exists()) {
                Role::create(['name' => $ad, 'team_id' => $company->id, 'guard_name' => 'web']);
            }
        }
    }

    /**
     * Bağlantı dostu, tekil firma adresi (bozuksa `firma`, çakışırsa `-2`, `-3`…).
     */
    public function uniqueSlug(string $name): string
    {
        $temel = Str::slug($name) ?: 'firma';
        $slug = $temel;
        $i = 1;

        while (Company::where('slug', $slug)->exists()) {
            $slug = $temel.'-'.(++$i);
        }

        return $slug;
    }
}
