<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `php artisan mrc:create-admin` — cPanel kurulumunun ilk hesabı.
 *
 * Kayıt ucu (`POST /auth/register`) ile aynı işi yapması şart: firma + varsayılan
 * depo/birim + üyelik + Admin rolü. İkisi ayrı yazılırsa bir süre sonra biri eksik
 * kalır ve sessiz bir veri hatası doğar (ör. deposu olmayan firma → belge ihraç
 * edilemez). Bu testler komutun o yapıyı kurduğunu kilitler.
 */
class AdminProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Komutu çalıştırır ve [çıkış kodu, çıktı] döndürür.
     *
     * @param  array<string, string|bool>  $secenekler
     * @return array{0: int, 1: string}
     */
    protected function calistir(array $secenekler): array
    {
        $kod = Artisan::call('mrc:create-admin', $secenekler);

        return [$kod, Artisan::output()];
    }

    public function test_komut_yoneticisini_firmasini_ve_varsayilanlarini_kurar(): void
    {
        [$kod, $cikti] = $this->calistir([
            '--email' => 'admin@mrc.test',
            '--password' => 'parola1234',
            '--company' => 'MRC Otomotiv A.Ş.',
        ]);

        $this->assertSame(0, $kod, $cikti);
        $this->assertStringContainsString('Yönetici hesabı açıldı.', $cikti);
        // Özet tablo veritabanı varsayılanlarını da göstermeli (para birimi boş yazılıyordu)
        $this->assertStringContainsString('TRY', $cikti);

        $kullanici = User::where('email', 'admin@mrc.test')->firstOrFail();
        $firma = Company::firstOrFail();

        $this->assertSame('MRC Otomotiv A.Ş.', $kullanici->currentCompany?->name);
        $this->assertSame($kullanici->id, $firma->owner_id);
        $this->assertSame([$firma->id], $kullanici->companies()->pluck('companies.id')->all());

        // Varsayılan depo + birim (v1 tek depo modu) açılmış olmalı
        $this->assertSame(1, Warehouse::where('company_id', $firma->id)->where('is_default', true)->count());
        $this->assertSame('Adet', Unit::where('company_id', $firma->id)->where('is_default', true)->value('name'));

        // Roller firmaya (team) tanımlı; yönetici Admin
        $this->assertEqualsCanonicalizing(
            ['Admin', 'Personel'],
            Role::where('team_id', $firma->id)->pluck('name')->all()
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($firma->id);
        $this->assertTrue($kullanici->hasRole('Admin'));
        $this->assertFalse($kullanici->hasRole('Personel'));
    }

    public function test_kurulan_hesap_api_girisinde_calisir(): void
    {
        [$kod] = $this->calistir([
            '--email' => 'giris@mrc.test',
            '--password' => 'parola1234',
            '--company' => 'Giriş Firması',
        ]);

        $this->assertSame(0, $kod);

        $this->apiPost('/api/v1/auth/login', [
            'email' => 'giris@mrc.test',
            'password' => 'parola1234',
        ])->assertOk()
            ->assertJsonPath('user.email', 'giris@mrc.test')
            ->assertJsonPath('company.name', 'Giriş Firması');
    }

    public function test_ayni_e_posta_varsa_yeni_hesap_acilmaz(): void
    {
        User::create([
            'name' => 'Mevcut Yönetici',
            'email' => 'mevcut@mrc.test',
            'password' => 'parola1234',
        ]);

        [$kod, $cikti] = $this->calistir([
            '--email' => 'mevcut@mrc.test',
            '--password' => 'yeniParola1234',
            '--company' => 'İkinci Firma',
        ]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('zaten var', $cikti);
        $this->assertSame(1, User::count());
        $this->assertSame(0, Company::count());
    }

    public function test_rotate_yalniz_parolayi_yeniler(): void
    {
        $eskiParola = 'eskiParola1234';

        User::create([
            'name' => 'Döndürme Kullanıcısı',
            'email' => 'dondur@mrc.test',
            'password' => $eskiParola,
        ]);

        [$kod, $cikti] = $this->calistir([
            '--email' => 'dondur@mrc.test',
            '--password' => 'yeniParola9999',
            '--rotate' => true,
        ]);

        $this->assertSame(0, $kod, $cikti);
        $this->assertStringContainsString('Parola yenilendi', $cikti);

        $kullanici = User::where('email', 'dondur@mrc.test')->firstOrFail();

        $this->assertTrue(Hash::check('yeniParola9999', $kullanici->password));
        $this->assertFalse(Hash::check($eskiParola, $kullanici->password));
        $this->assertSame(1, User::count());
        $this->assertSame(0, Company::count(), '--rotate yeni firma kurmaz');
    }

    public function test_parola_eksikse_komut_hata_verir_hicbir_sey_kurulmaz(): void
    {
        [$kod, $cikti] = $this->calistir([
            '--email' => 'eksik@mrc.test',
            '--company' => 'Eksik Firma',
        ]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('Parola gerekir', $cikti);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Company::count());
    }

    public function test_gecersiz_e_posta_ve_kisa_parola_reddedilir(): void
    {
        [$kod, $cikti] = $this->calistir([
            '--email' => 'gecersiz-e-posta',
            '--password' => 'kisa',
            '--company' => 'Ret Firması',
        ]);

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('Geçerli bir e-posta adresi verin', $cikti);
        $this->assertStringContainsString('Parola en az 8 karakter olmalı', $cikti);
        $this->assertSame(0, User::count());
    }

    public function test_slug_tekillestirme(): void
    {
        foreach (['bir@mrc.test', 'iki@mrc.test'] as $eposta) {
            [$kod] = $this->calistir([
                '--email' => $eposta,
                '--password' => 'parola1234',
                '--company' => 'Aynı İsim',
            ]);

            $this->assertSame(0, $kod);
        }

        $this->assertSame(['ayni-isim', 'ayni-isim-2'], Company::orderBy('id')->pluck('slug')->all());
        $this->assertSame(2, Company::count());
    }
}
