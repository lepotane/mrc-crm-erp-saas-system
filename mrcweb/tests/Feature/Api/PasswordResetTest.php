<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ŞİFRE SIFIRLAMA .
 *
 * Kilitlenen sözleşme: bilinmeyen e-postada da 200 (kullanıcı sayımı yok);
 * jeton 60 dk ömürlü ve tek kullanımlık; başarıda TÜM anahtarlar düşer;
 * yanlış/ölü jeton 422'dir (hangi e-postaların kayıtlı olduğu sızmaz).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Mail::fake();
    }

    protected function kaydol(string $email = 'sahip@firma-a.test'): array
    {
        $res = $this->apiPost('/api/v1/auth/register', [
            'name' => 'Sahip Kullanıcı',
            'email' => $email,
            'password' => 'parola1234',
            'company_name' => 'Firma A',
        ]);

        return ['token' => $res->json('token'), 'user' => User::where('email', $email)->first()];
    }

    public function test_bilinmeyen_epostada_bile_200_ve_posta_yok(): void
    {
        $this->apiPost('/api/v1/auth/forgot-password', ['email' => 'yok@kimse.test'])
            ->assertOk()
            ->assertJsonPath('message', __('E-posta kayıtlıysa sıfırlama bağlantısı gönderildi.'));

        Mail::assertNothingSent();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('password_reset_tokens')->count());
    }

    public function test_kayitli_epostaya_baglanti_gider_ve_jeton_saklanir(): void
    {
        $this->kaydol();

        $this->apiPost('/api/v1/auth/forgot-password', ['email' => 'sahip@firma-a.test'])
            ->assertOk();

        Mail::assertSent(\App\Mail\ResetPasswordMail::class, fn ($m) => $m->hasTo('sahip@firma-a.test'));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('password_reset_tokens')->count());
    }

    public function test_gecerli_jetonla_sifre_yenilenir_eski_olur(): void
    {
        ['token' => $eski] = $this->kaydol();

        $token = \Illuminate\Support\Facades\Password::broker()->createToken(
            User::where('email', 'sahip@firma-a.test')->first()
        );

        $this->apiPost('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'sahip@firma-a.test',
            'password' => 'yepyeni-parola-99',
            'password_confirmation' => 'yepyeni-parola-99',
        ])->assertOk()->assertJsonPath('message', __('Şifren yenilendi. Yeni şifrenle giriş yap.'));

        $this->assertTrue(Hash::check('yepyeni-parola-99', User::first()->fresh()->password));

        // Eski jeton öldü (tek kullanımlık).
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('password_reset_tokens')->count());

        // Eski oturum anahtarı düştü, yeni şifreyle giriş açılır.
        $this->apiGet('/api/v1/me', ['Authorization' => "Bearer {$eski}"])->assertUnauthorized();
        $this->apiPost('/api/v1/auth/login', ['email' => 'sahip@firma-a.test', 'password' => 'parola1234'])
            ->assertStatus(422);
        $this->apiPost('/api/v1/auth/login', ['email' => 'sahip@firma-a.test', 'password' => 'yepyeni-parola-99'])
            ->assertOk();
    }

    public function test_gecersiz_jeton_422_ve_ipucu_vermez(): void
    {
        $this->kaydol();

        $this->apiPost('/api/v1/auth/reset-password', [
            'token' => 'olum-boyu-gecersiz',
            'email' => 'sahip@firma-a.test',
            'password' => 'yepyeni-parola-99',
            'password_confirmation' => 'yepyeni-parola-99',
        ])->assertStatus(422)->assertJsonPath('errors.token.0', __('Bağlantı geçersiz ya da süresi dolmuş. Yeni bağlantı isteyin.'));

        $this->assertTrue(Hash::check('parola1234', User::first()->fresh()->password));
    }

    public function test_sifre_onayi_tutmazsa_422(): void
    {
        $this->kaydol();

        $token = \Illuminate\Support\Facades\Password::broker()->createToken(User::first());

        $this->apiPost('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'sahip@firma-a.test',
            'password' => 'yepyeni-parola-99',
            'password_confirmation' => 'farkli-parola-11',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
