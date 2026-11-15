<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * İlk yönetici hesabını kurar (`php artisan mrc:create-admin`).
 *
 * Neden gerekiyor: `POST /auth/register` kaydı tarayıcıdan yapılır; cPanel
 * kurulumunda (veya SSH/cron köprüsünde) ilk hesabı açmanın deterministik bir yolu
 * olması gerekir. Bu komut, kayıt ucuyla AYNI işi yapar (firma + varsayılan depo/
 * birim + üyelik + Admin rolü) — ikisi ayrı yazılırsa bir süre sonra biri eksik
 * kalır; ortak nokta: `App\Services\TenantProvisioner`.
 *
 * Kullanım (etkileşimli):
 *   php artisan mrc:create-admin
 * Kullanım (betik/cron köprüsü, non-interactive):
 *   php artisan mrc:create-admin --email=admin@firma.test --password=... --company="Firma A.Ş."
 *
 * `--rotate` verilirse mevcut kullanıcının yalnız parolası yenilenir; yeni hesap
 * açılmaz (yanlışlıkla ikinci bir "sahip" üretmemek için varsayılan davranış reddetmektir).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'mrc:create-admin
        {--email= : Yöneticinin e-posta adresi}
        {--password= : Parola (en az 8 karakter; verilmezse etkileşimli sorulur)}
        {--name= : Ad soyad (verilmezse firma adından üretilir)}
        {--company= : Kurulacak firmanın adı}
        {--rotate : Kullanıcı varsa yalnız parolası yenilenir}';

    protected $description = 'İlk yönetici hesabını ve firmasını kurar (kayıt ucuyla aynı işi yapar)';

    public function handle(TenantProvisioner $provisioner): int
    {
        // Sorular yalnız gerçek terminale yazıldığında sorulur: cron/CI gibi
        // etkileşimsiz çalıştırmalarda eksik seçenek validation hatası olur
        // (ask() etkileşimsiz girdide istisna fırlatır, tty olmayan akışta da asılı kalabilir).
        // Web SAPI'de (köprü betiği) STDIN tanımsızdır — tanımsız sabite
        // dokunmak ölümcül hatadır, önce varlık denetlenir.
        $etkilesim = $this->input->isInteractive() && defined('STDIN') && @stream_isatty(STDIN);
        $yalnizParola = (bool) $this->option('rotate');

        $email = $this->girilen('--email') ?? ($etkilesim ? $this->ask('Yönetici e-posta adresi') : null);
        $sirket = $this->girilen('--company');
        $ad = $this->girilen('--name');
        $parola = $this->girilen('--password') ?? ($etkilesim ? $this->secret('Parola (en az 8 karakter)') : null);

        if (! $yalnizParola) {
            $sirket ??= ($etkilesim ? $this->ask('Firma adı') : null);
            $ad ??= (Str::limit((string) $sirket, 40, '') ?: 'Yönetici');
        }

        $hatalar = $this->dogrula(
            ['email' => $email, 'company' => $sirket, 'name' => $ad, 'password' => $parola],
            $yalnizParola
        );

        if ($hatalar !== []) {
            foreach ($hatalar as $mesaj) {
                $this->error($mesaj);
            }

            $this->line('');
            $this->line('Betik içinde kullanırken hepsini ver:');
            $this->line('  php artisan mrc:create-admin --email=admin@firma.test --company="Firma A.Ş." --password=<parola>');

            return self::FAILURE;
        }

        try {
            return $this->kur($email, $sirket, $ad, $parola, $provisioner);
        } catch (QueryException $e) {
            // cPanel'de en sık unutulan adım: migrate. Anlaşılır bir ricayla düş.
            $this->error('Veritabanına ulaşılamadı ya da tablolar eksik. Önce: php artisan migrate --force');
            $this->line($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, string|null>  $girdi
     * @return list<string>
     */
    private function dogrula(array $girdi, bool $yalnizParola = false): array
    {
        $hatalar = [];

        if (! filter_var((string) $girdi['email'], FILTER_VALIDATE_EMAIL)) {
            $hatalar[] = 'Geçerli bir e-posta adresi verin (--email=...).';
        }

        // --rotate yeni firma kurmaz; firma adı ve ad sorulmaz (mevcut kullanıcıya dokunulmaz).
        if (! $yalnizParola) {
            if (mb_strlen((string) $girdi['company']) < 2 || mb_strlen((string) $girdi['company']) > 150) {
                $hatalar[] = 'Firma adı 2-150 karakter olmalı (--company=...).';
            }

            if (mb_strlen((string) $girdi['name']) > 120) {
                $hatalar[] = 'Ad en fazla 120 karakter olabilir.';
            }
        }

        if ($girdi['password'] === null) {
            $hatalar[] = 'Parola gerekir: --password=<parola> verin ya da komutu etkileşimli çalıştırın.';
        } elseif (mb_strlen((string) $girdi['password']) < 8) {
            $hatalar[] = 'Parola en az 8 karakter olmalı.';
        }

        return $hatalar;
    }

    private function kur(?string $email, ?string $sirket, ?string $ad, ?string $parola, TenantProvisioner $provisioner): int
    {
        $mevcut = User::where('email', (string) $email)->first();

        if ($mevcut !== null) {
            if (! $this->option('rotate')) {
                $this->error("Bu e-posta ile bir kullanıcı zaten var: {$email}");
                $this->line('Parolayı yenilemek için: --rotate; yeni firma açmak için farklı bir e-posta kullanın.');

                return self::FAILURE;
            }

            $mevcut->forceFill(['password' => Hash::make($parola)])->save();

            $this->info('Parola yenilendi: '.$mevcut->email);
            $this->line('Firma bağlamı: '.($mevcut->currentCompany?->name ?? '(üyeliği yok — davet akışıyla eklenmeli)'));

            return self::SUCCESS;
        }

        $kullanici = null;
        $firma = null;

        DB::transaction(function () use (&$kullanici, &$firma, $email, $sirket, $ad, $parola, $provisioner): void {
            $kullanici = User::create([
                'name' => $ad,
                'email' => $email,
                'password' => $parola,
            ]);

            $firma = $provisioner->provision($kullanici, $sirket);
        });

        // Tabloda DB varsayılanlarını (para birimi vb.) da göstermek için tazelenmiş hali okunur.
        $firma->refresh();
        $kullanici->refresh();

        $this->info('Yönetici hesabı açıldı.');
        $this->table(['Alan', 'Değer'], [
            ['Kullanıcı', "#{$kullanici->id} {$kullanici->name} <{$kullanici->email}>"],
            ['Rol', 'Admin (firma bazlı) + Personel rolü tanımlandı'],
            ['Firma', "#{$firma->id} {$firma->name} (slug: {$firma->slug})"],
            ['Varsayılan depo', Warehouse::where('company_id', $firma->id)->where('is_default', true)->value('name')],
            ['Varsayılan birim', Unit::where('company_id', $firma->id)->where('is_default', true)->value('name')],
            ['Para birimi', (string) $firma->currency_code],
            ['Giriş adresi', rtrim((string) config('app.url'), '/').'/'],
        ]);

        $this->line('');
        $this->line('Firma sayısı: '.Company::count().' · sonraki kullanıcılar Ayarlar → Kullanıcılar ekranından davet edilir.');

        return self::SUCCESS;
    }

    /**
     * Verilmemiş/boş seçenek null döner (etkileşimli soruya izin verir).
     */
    private function girilen(string $secenek): ?string
    {
        $deger = $this->option(ltrim($secenek, '-'));

        return ($deger === null || $deger === '') ? null : (string) $deger;
    }
}
