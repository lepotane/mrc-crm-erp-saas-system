<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Süper Yönetici hesabının kurulumu (tek doğru yer).
 *
 * Neden ayrı sınıf: bu hesap hem migration'da (ilk kurulum) hem artisan
 * komutunda (`mrc:create-superadmin`, kurtarma) hem de testlerde (süper yönetici
 * gereken senaryolar) oluşturuluyordu. Üç ayrı kopyası olduğunda biri
 * unutuluyor — 07 Kas'ta olan da buydu: migration her ortamda (test dahil)
 * hesabı seed ediyor, "kullanıcı sayısı = 1" diye sayan 7 test kırılıyordu.
 *
 * Artık:
 *   - migration yalnız ŞEMA'yı kurar, hesabı üretimde seed eder;
 *   - test ortamında hesap seed EDİLMEZ (testler kendi ihtiyacını doğrudan
 *     sorar: `Tests\TestCase::seedSuperAdmin()`);
 *   - parola `config('mrc.superadmin.password')` üzerinden gelir, `.env` ile
 *     ezilebilir.
 */
class SuperAdminBootstrap
{
    /**
     * Süper yönetici hesabını oluşturur/gunceller.
     *
     * GÜVENLİK: Parola verilmezse yeni hesap OLUŞTURULMAZ (boş parolalı hesap
     * "kimse giremez ama hesap var" durumu yaratır ve sonradan ele geçirilebilir
     * bir yüzey olur). Var olan hesabın parolasına da kendiliğinden dokunulmaz —
     * parola değişikliği bilinçli bir işlemdir (`mrc:create-superadmin`).
     *
     * @param  string|null  $email  Boşsa config'teki varsayılan kullanılır.
     * @param  string|null  $password  Boşsa parola DEĞİŞTİRİLMEZ / hesap kurulmaz.
     * @param  bool  $resetPassword  Mevcut hesabın parolası yenilensin mi.
     * @return int Kullanıcı id'si (bulunamazsa 0).
     */
    public static function ensure(?string $email = null, ?string $password = null, bool $resetPassword = true): int
    {
        $email = $email ?: (string) config('mrc.superadmin.email');
        $password = $password ?: (string) (config('mrc.superadmin.password') ?? '');

        if ($email === '') {
            return 0;
        }

        $now = now();
        $firstCompanyId = DB::table('companies')->orderBy('id')->value('id');

        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            $superId = (int) $existing->id;

            $attributes = [
                'is_superadmin' => true,
                'user_class' => config('mrc.superadmin.user_class'),
                'job_title' => config('mrc.superadmin.job_title'),
                'current_company_id' => $existing->current_company_id ?: $firstCompanyId,
                'email_verified_at' => $existing->email_verified_at ?? $now,
                'updated_at' => $now,
            ];

            if ($resetPassword && $password !== '') {
                $attributes['password'] = Hash::make($password);
            }

            DB::table('users')->where('id', $superId)->update($attributes);
        } else {
            // Parola yoksa hesap kurma.
            if ($password === '') {
                return 0;
            }

            $superId = (int) DB::table('users')->insertGetId([
                'name' => config('mrc.superadmin.name'),
                'email' => $email,
                'password' => Hash::make($password),
                'user_class' => config('mrc.superadmin.user_class'),
                'job_title' => config('mrc.superadmin.job_title'),
                'avatar_color' => config('mrc.superadmin.avatar_color'),
                'is_superadmin' => true,
                'current_company_id' => $firstCompanyId,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($firstCompanyId) {
            self::attachFirstCompany($superId, (int) $firstCompanyId, $now);
        }

        return $superId;
    }

    /**
     * Süper yöneticiyi ilk firmaya ekler ve Admin rolünü (ekip kapsamında) verir.
     */
    protected static function attachFirstCompany(int $superId, int $companyId, $now): void
    {
        $existsPivot = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $superId)
            ->exists();

        if (! $existsPivot) {
            DB::table('company_user')->insert([
                'company_id' => $companyId,
                'user_id' => $superId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('model_has_roles')) {
            return;
        }

        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');

        if (! $adminRoleId) {
            return;
        }

        $teamCol = Schema::hasColumn('model_has_roles', 'company_id')
            ? 'company_id'
            : (Schema::hasColumn('model_has_roles', 'team_id') ? 'team_id' : null);

        $q = DB::table('model_has_roles')
            ->where('role_id', $adminRoleId)
            ->where('model_type', User::class)
            ->where('model_id', $superId);

        if ($teamCol) {
            $q->where($teamCol, $companyId);
        }

        if ($q->exists()) {
            return;
        }

        $row = [
            'role_id' => $adminRoleId,
            'model_type' => User::class,
            'model_id' => $superId,
        ];

        if ($teamCol) {
            $row[$teamCol] = $companyId;
        }

        DB::table('model_has_roles')->insert($row);
    }
}
