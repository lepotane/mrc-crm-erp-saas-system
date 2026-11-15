<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyJoinRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Başvuru (join request) akışının güvenlik kilitleri.
 *
 * Kapatılan üç açık:
 *  1. Herkese açık `POST /public/join-requests` ucu, başvuru ALMAYAN (kapalı)
 *     firmaya da başvuru kabul ediyordu → yöneticilere bildirim bombardımanı.
 *  2. `lookupCompany` tüm firmaları sayıyordu → pasif/kapalı firmaların adları
 *     sızıyordu.
 *  3. `approve` ucu, e-postası zaten kayıtlı bir hesabı PAROLA DOĞRULAMADAN
 *     firmaya ekliyordu → hesap ele geçirme yolu.
 */
class JoinRequestSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'yonetici@firma.test']);
        $this->company = Company::create([
            'name' => 'Güvenlik Test A.Ş.',
            'slug' => 'guvenlik-test-as',
            'tax_number' => '5555555555',
            'owner_id' => $this->admin->id,
        ]);

        $this->admin->update(['current_company_id' => $this->company->id]);
        $this->company->users()->attach($this->admin->id);

        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Personel', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->admin->assignRole('Admin');
    }

    protected function basvuru(array $ek = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'first_name' => 'Ahmet',
            'last_name' => 'Yılmaz',
            'email' => 'ahmet.yilmaz@ornek.com',
            'password' => 'Guv3nliSifre!',
            'requested_class' => 'Personel',
        ], $ek);
    }

    public function test_kapali_firmaya_basvuru_reddedilir(): void
    {
        $this->company->forceFill(['accepting_applications' => false])->save();

        $res = $this->postJson('/api/v1/public/join-requests', $this->basvuru());

        $res->assertStatus(422);
        $this->assertSame(0, CompanyJoinRequest::count());
    }

    public function test_pasif_veya_onay_bekleyen_firmaya_basvuru_reddedilir(): void
    {
        foreach (['pending', 'suspended', 'expired'] as $durum) {
            $this->company->forceFill([
                'accepting_applications' => true,
                'approval_status' => $durum,
            ])->save();

            $res = $this->postJson('/api/v1/public/join-requests', $this->basvuru());

            $res->assertStatus(422);
        }

        $this->assertSame(0, CompanyJoinRequest::count());
    }

    public function test_kapali_firma_listede_ve_arama_sonucunda_gorunmez(): void
    {
        $this->company->forceFill([
            'accepting_applications' => false,
            'approval_status' => 'active',
        ])->save();

        $liste = $this->getJson('/api/v1/public/companies');
        $liste->assertOk();
        $this->assertNotContains($this->company->id, array_column($liste->json('companies'), 'id'));

        $arama = $this->postJson('/api/v1/public/company-lookup', ['query' => 'Guvenlik Test']);
        $arama->assertOk();
        $this->assertNotSame('exact', $arama->json('status'));
        $this->assertNotSame('suggestion', $arama->json('status'));
    }

    public function test_kayitli_hesabin_parolasi_yanlissa_onaylanmaz_ve_uye_eklenmez(): void
    {
        $mevcut = User::factory()->create([
            'email' => 'calisan@firma.test',
            'password' => 'GerçekParola123!',
        ]);

        $res = $this->postJson('/api/v1/public/join-requests', $this->basvuru([
            'email' => 'calisan@firma.test',
            'password' => 'BaskaParola999!',
        ]));
        $res->assertCreated();
        $requestId = $res->json('request_id');

        $onay = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/join-requests/{$requestId}/approve", ['role' => 'Personel']);

        $onay->assertStatus(422);
        $this->assertFalse($this->company->users()->where('users.id', $mevcut->id)->exists());
        $this->assertSame('pending', CompanyJoinRequest::find($requestId)->status);
    }

    public function test_kayitli_hesabin_parolasi_dogruysa_onaylanir(): void
    {
        $mevcut = User::factory()->create([
            'email' => 'calisan@firma.test',
            'password' => 'GerçekParola123!',
        ]);

        $res = $this->postJson('/api/v1/public/join-requests', $this->basvuru([
            'email' => 'calisan@firma.test',
            'password' => 'GerçekParola123!',
        ]));
        $res->assertCreated();
        $requestId = $res->json('request_id');

        $onay = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/join-requests/{$requestId}/approve", ['role' => 'Personel']);

        $onay->assertOk();
        $this->assertTrue($this->company->users()->where('users.id', $mevcut->id)->exists());
        // Parolanın şifreli kopyası onaydan sonra temizlenir.
        $this->assertNull(CompanyJoinRequest::find($requestId)->password_encrypted);
    }

    public function test_yeni_kullanici_parolasi_olusu_basvuru_parolasi_ile_ayni_kalir(): void
    {
        $res = $this->postJson('/api/v1/public/join-requests', $this->basvuru([
            'email' => 'yeni.aday@ornek.com',
            'password' => 'YeniAdaySifre123!',
        ]));
        $res->assertCreated();
        $requestId = $res->json('request_id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/join-requests/{$requestId}/approve", ['role' => 'Personel'])
            ->assertOk();

        $yeni = User::where('email', 'yeni.aday@ornek.com')->first();
        $this->assertNotNull($yeni);
        $this->assertTrue(Hash::check('YeniAdaySifre123!', $yeni->password));
    }

    public function test_basvuru_parolasi_duz_metin_saklanmaz(): void
    {
        $res = $this->postJson('/api/v1/public/join-requests', $this->basvuru([
            'password' => 'CokGizliParola!',
        ]));
        $res->assertCreated();

        $kayit = CompanyJoinRequest::first();
        $this->assertNotSame('CokGizliParola!', $kayit->password_encrypted);
        $this->assertNotSame('CokGizliParola!', $kayit->password_hash);
        $this->assertStringNotContainsString('CokGizliParola!', json_encode($kayit->toArray()));
    }
}
