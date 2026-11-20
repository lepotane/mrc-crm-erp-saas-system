<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CreateAccountantRoleCommand extends Command
{
    protected $signature = 'mrc:create-accountant-role';

    protected $description = 'MaliMüşavir muhasebeci rolünü (varsa) güvenli izinlerle oluşturur.';

    public function handle(): int
    {
        $guard = (string) config('auth.defaults.guard');
        $roleName = 'MaliMüşavir';

        // Idempotent: an existing role is never deleted or rewritten.
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => $guard,
        ]);

        // Only permissions that already exist in the database may be attached.
        $available = Permission::pluck('name')->map(fn ($name) => (string) $name)->all();
        $allowlist = ['manage accounting', 'view accounting'];
        $candidates = array_values(array_intersect($allowlist, $available));
        $grant = Permission::whereIn('name', $candidates)
            ->where('guard_name', $guard)
            ->pluck('name')
            ->all();

        if ($grant !== []) {
            // givePermissionTo only attaches missing permissions; it never
            // revokes anything from a pre-existing role.
            $role->givePermissionTo($grant);
        }

        $this->info('Rol: '.$roleName.' (guard: '.$guard.')');
        $this->line('Verilen izinler: '.($grant !== [] ? implode(', ', $grant) : '(yok — mevcut güvenli izin bulunamadı)'));
        $this->line('Mevcut roller/izinler silinmedi veya değiştirilmedi.');

        return self::SUCCESS;
    }
}
