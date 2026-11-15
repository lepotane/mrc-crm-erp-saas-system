<?php

namespace Tests\Feature\Api;

use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    protected function registerUser(array $overrides = []): array
    {
        $payload = array_merge([
            'name' => 'Sahip Kullanıcı',
            'email' => 'sahip@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ], $overrides);

        $response = $this->apiPost('/api/v1/auth/register', $payload);

        return ['response' => $response, 'token' => $response->json('token')];
    }

    /**
     * Firma sahibi + Personel daveti kurar.
     *
     * @return array{headers: array<string,string>, invite: array<string,mixed>}
     */
    protected function ownerWithInvite(string $inviteEmail = 'uye@firma-a.test'): array
    {
        ['token' => $token] = $this->registerUser([
            'email' => 'sahip@firma-a.test',
            'company_name' => 'Firma A',
        ]);
        $headers = ['Authorization' => "Bearer {$token}"];

        $invite = $this->apiPost('/api/v1/invitations', [
            'email' => $inviteEmail,
            'role' => 'Personel',
        ], $headers)->assertCreated()->json('invitation');

        return ['headers' => $headers, 'invite' => $invite];
    }

    public function test_admin_davet_uretir_7gun_sureli_bekliyor(): void
    {
        ['invite' => $invite] = $this->ownerWithInvite();

        $this->assertSame('bekliyor', $invite['status']);
        $this->assertLessThanOrEqual(7.0, Invitation::first()->expires_at->diffInDays(now()));
        $this->assertSame(64, strlen(Invitation::first()->token));
    }

    public function test_ayni_epostaya_bekleyen_ikinci_davet_atilamaz(): void
    {
        ['headers' => $headers] = $this->ownerWithInvite('tekrar@firma-a.test');

        $this->apiPost('/api/v1/invitations', [
            'email' => 'tekrar@firma-a.test',
            'role' => 'Personel',
        ], $headers)->assertStatus(422);
    }

    public function test_davetli_kullanici_daveti_kabul_eder_personel_rolu_alir(): void
    {
        ['headers' => $ownerHeaders] = $this->ownerWithInvite('uye@firma-a.test');

        // davetli kullanıcı kendi firmasını kurar (gerçek kayıt + login akışı)
        $invited = $this->registerUser([
            'email' => 'uye@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Kendi Firması',
        ]);
        $invitedHeaders = ['Authorization' => "Bearer {$invited['token']}"];

        $this->apiPost('/api/v1/invitations/accept', ['token' => Invitation::first()->token], $invitedHeaders)
            ->assertOk();

        // üyelik: /me firma listesinde 2 firma olmalı
        $me = $this->apiGet('/api/v1/me', $invitedHeaders)->assertOk();
        $this->assertCount(2, $me->json('companies'));

        // Firma A bağlamına geçince Personel rolü görünür
        $companyIdA = collect($me->json('companies'))->firstWhere('name', 'Firma A')['id'];
        $this->apiPost('/api/v1/companies/select', ['company_id' => $companyIdA], $invitedHeaders)
            ->assertOk()
            ->assertJsonPath('user.roles.0', 'Personel');

        // sahip tarafında üye listesinde görünür
        $members = $this->apiGet('/api/v1/company/members', $ownerHeaders)->json('members');
        $this->assertContains('uye@firma-a.test', collect($members)->pluck('email'));
    }

    public function test_farkli_epostali_kullanici_davet_kabul_emez(): void
    {
        $this->ownerWithInvite('hedef@firma-a.test');
        $deadToken = Invitation::first()->token;

        ['token' => $token] = $this->registerUser([
            'email' => 'baska@firma-b.test',
            'company_name' => 'B',
        ]);

        $this->apiPost('/api/v1/invitations/accept', ['token' => $deadToken], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(403);
    }

    public function test_davetli_kullanici_kendi_davetini_token_ile_gorur_ve_kabul_eder(): void
    {
        $this->ownerWithInvite('uye@firma-a.test');

        ['token' => $token] = $this->registerUser([
            'email' => 'uye@firma-a.test',
            'password' => 'parola1234',
            'company_name' => 'Kendi Firması',
        ]);
        $h = ['Authorization' => "Bearer {$token}"];

        $mine = $this->apiGet('/api/v1/invitations/mine', $h)->assertOk();
        $this->assertCount(1, $mine->json('invitations'));

        $row = $mine->json('invitations.0');
        $this->assertSame('uye@firma-a.test', $row['email']);
        $this->assertSame('Firma A', $row['company_name']);
        $this->assertSame('Personel', $row['role']);
        $this->assertSame(64, strlen($row['token']));
        $this->assertNotEmpty($row['expires_at']);

        // token’ı listeden alıp kabul edebilmeli (e-posta teslimi yok → tek güvenli yol)
        $accept = $this->apiPost('/api/v1/invitations/accept', ['token' => $row['token']], $h)->assertOk();
        $this->assertSame('Firma A', $accept->json('company.name'));
        $this->assertSame($row['company_id'], $accept->json('company.id'));
        // kullanıcı kendi firmasında çalışmaya devam eder; bağlamı kendisi değiştirir
        $this->assertFalse($accept->json('company.current'));

        $joinedId = $accept->json('company.id');
        $this->apiPost('/api/v1/companies/select', ['company_id' => $joinedId], $h)
            ->assertOk()
            ->assertJsonPath('user.roles.0', 'Personel');

        // kabul sonrası davet „bekleyen” listesinden düşer
        $this->assertCount(0, $this->apiGet('/api/v1/invitations/mine', $h)->assertOk()->json('invitations'));
    }

    public function test_davet_listesi_baska_kullanicinin_e_postasini_gormez(): void
    {
        $this->ownerWithInvite('hedef@firma-a.test');

        ['token' => $token] = $this->registerUser([
            'email' => 'baska@firma-b.test',
            'company_name' => 'B',
        ]);

        $this->apiGet('/api/v1/invitations/mine', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(0, 'invitations');
    }

    public function test_davet_e_postasi_buyuk_kucuk_harften_bagimsiz_eslesir(): void
    {
        $this->ownerWithInvite('Uye@Firma-A.test');

        ['token' => $token] = $this->registerUser([
            'email' => 'uye@firma-a.test',
            'company_name' => 'K',
        ]);

        $this->apiGet('/api/v1/invitations/mine', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(1, 'invitations');
    }

    public function test_suresi_gecen_davet_listede_gorunmez(): void
    {
        $this->ownerWithInvite('gecikmis@firma-a.test');
        Invitation::query()->update(['expires_at' => now()->subDay()]);

        ['token' => $token] = $this->registerUser([
            'email' => 'gecikmis@firma-a.test',
            'company_name' => 'K',
        ]);

        $this->apiGet('/api/v1/invitations/mine', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(0, 'invitations');
    }

    public function test_admin_davet_iptal_eder_iptal_token_kabul_emez(): void
    {
        ['headers' => $headers, 'invite' => $invite] = $this->ownerWithInvite('iptal@firma-a.test');
        $deadToken = Invitation::first()->token;

        $this->apiDelete("/api/v1/invitations/{$invite['id']}", [], $headers)->assertOk()
            ->assertJson(['message' => 'Davet iptal edildi.']);

        ['token' => $token] = $this->registerUser([
            'email' => 'iptal@firma-a.test',
            'company_name' => 'K',
        ]);

        $this->apiPost('/api/v1/invitations/accept', ['token' => $deadToken], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(404);
    }

    public function test_personel_davet_uretemez(): void
    {
        // Owner davet eder, davetli kabul eder → Personel olur
        ['headers' => $ownerHeaders] = $this->ownerWithInvite('uye@firma-a.test');
        $invited = $this->registerUser([
            'email' => 'uye@firma-a.test',
            'company_name' => 'Kendi Firması',
        ]);
        $invitedHeaders = ['Authorization' => "Bearer {$invited['token']}"];

        $this->apiPost('/api/v1/invitations/accept', ['token' => Invitation::first()->token], $invitedHeaders)
            ->assertOk();

        // davetliyi hedef Firma A bağlamına geçirdikten sonra davet üretme denemesi
        $me = $this->apiGet('/api/v1/me', $invitedHeaders)->assertOk();
        $companyIdA = collect($me->json('companies'))->firstWhere('name', 'Firma A')['id'];
        $this->apiPost('/api/v1/companies/select', ['company_id' => $companyIdA], $invitedHeaders)
            ->assertOk();

        // Personel olarak davet üretme denemesi → 403
        $this->apiPost('/api/v1/invitations', [
            'email' => 'yildiz@firma-a.test',
            'role' => 'Admin',
        ], $invitedHeaders)->assertStatus(403);
    }
}
