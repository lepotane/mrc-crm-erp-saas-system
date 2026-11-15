<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * E-POSTA DOĞRULAMA .
 *
 * Kilit: kayıt postası tetikler; imzalı bağlantı doğrular + SPA'ya 302 atar;
 * kurcalanmış imza 403; doğrulanmış hesaba yeniden gönderim "zaten doğrulu"
 * der; hız sınırı 6/dk.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Mail::fake();
    }

    protected function kaydol(string $email = 'yeni@firma.test'): array
    {
        $res = $this->apiPost('/api/v1/auth/register', [
            'name' => 'Yeni Kullanıcı',
            'email' => $email,
            'password' => 'parola1234',
            'company_name' => 'Firma B',
        ]);

        return ['token' => $res->json('token'), 'user' => User::where('email', $email)->first()];
    }

    protected function dogrulamaAdresi(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify', now()->addHours(24),
            ['id' => $user->id, 'hash' => sha1((string) $user->email)]
        );
    }

    public function test_kayit_dogrulama_postasi_gonderir(): void
    {
        ['user' => $user] = $this->kaydol();

        Mail::assertSent(\App\Mail\VerifyEmailMail::class, fn ($m) => $m->hasTo($user->email));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_imzali_baglanti_dogrular_ve_sayfaya_yonlendirir(): void
    {
        ['user' => $user] = $this->kaydol();

        $this->get($this->dogrulamaAdresi($user))
            ->assertRedirect('/dogrulandi?email='.urlencode($user->email));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_kurcalanmis_imza_reddedilir(): void
    {
        ['user' => $user] = $this->kaydol();

        $bozuk = $this->dogrulamaAdresi($user).'oynandi';

        $this->get($bozuk)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_yanlis_kisi_idsi_404(): void
    {
        ['user' => $user] = $this->kaydol();

        $url = URL::temporarySignedRoute(
            'verification.verify', now()->addHours(24),
            ['id' => $user->id + 999, 'hash' => sha1((string) $user->email)]
        );

        $this->get($url)->assertNotFound();
    }

    public function test_dogrulanmis_hesaba_yeniden_gonderim_yok(): void
    {
        ['token' => $token, 'user' => $user] = $this->kaydol();
        $user->markEmailAsVerified();

        $this->apiPost('/api/v1/auth/email/verification-notification', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('message', __('E-postan zaten doğrulanmış.'));
    }

    public function test_yeniden_gonderim_dogrulanmamisa_gider(): void
    {
        ['token' => $token] = $this->kaydol();
        Mail::assertSent(\App\Mail\VerifyEmailMail::class, 1);

        $this->apiPost('/api/v1/auth/email/verification-notification', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        Mail::assertSent(\App\Mail\VerifyEmailMail::class, 2);
    }
}
