<?php

namespace App\Console\Commands;

use App\Support\SuperAdminBootstrap;
use Illuminate\Console\Command;

/**
 * Süper Yönetici hesabını oluşturur / onarır (`php artisan mrc:create-superadmin`).
 *
 * Neden ayrı komut: 07 Kas'ta hesabın seed'i migration'ın içindeydi. Migration
 * bir kez çalışır — hesabı sonradan onarmak (parola sıfırlamak, silinmiş
 * hesabı geri getirmek) için yol yoktu. Artık hesap tek yerden
 * (`App\Support\SuperAdminBootstrap`) yönetiliyor; migration yalnız şemayı
 * kurar + ilk kurulumda hesabı tohumlar.
 *
 * Kullanım:
 *   php artisan mrc:create-superadmin
 *   php artisan mrc:create-superadmin --email=a@b.com --password=YeniParola123
 *   php artisan mrc:create-superadmin --keep-password   (parolaya dokunma)
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'mrc:create-superadmin
        {--email= : Süper yönetici e-posta adresi (varsayılan: config)}
        {--password= : Parola (verilmezse config varsayılanı)}
        {--keep-password : Mevcut hesabın parolasına dokunma}';

    protected $description = 'Süper Yönetici hesabını oluşturur/onarır (firma/tenant yönetiminden bağımsız)';

    public function handle(): int
    {
        $email = $this->option('email') ?: (string) config('mrc.superadmin.email');
        $keepPassword = (bool) $this->option('keep-password');
        $password = $keepPassword ? null : ($this->option('password') ?: (string) (config('mrc.superadmin.password') ?? ''));

        if (! $keepPassword && $password === '') {
            $this->error('Parola belirtilmedi.');
            $this->line('  Seçenek 1: --password=... ile verin');
            $this->line('  Seçenek 2: .env içine MRC_SUPERADMIN_PASSWORD=... yazın');
            $this->line('  Seçenek 3: --keep-password ile mevcut parolayı koruyun');

            return self::FAILURE;
        }

        if (! $keepPassword && strlen((string) $password) < 8) {
            $this->error('Parola en az 8 karakter olmalı.');

            return self::FAILURE;
        }

        $id = SuperAdminBootstrap::ensure($email, $password, ! $keepPassword);

        if (! $id) {
            $this->error('Hesap oluşturulamadı (e-posta boş olabilir).');

            return self::FAILURE;
        }

        $this->info("Süper Yönetici hazır: {$email} (id={$id})");

        if (! $keepPassword) {
            $this->line('Parola değiştirildi.');
            $this->comment('Parolayı .env içine MRC_SUPERADMIN_PASSWORD olarak yazabilirsin (depo private olsa bile).');
        }

        return self::SUCCESS;
    }
}
