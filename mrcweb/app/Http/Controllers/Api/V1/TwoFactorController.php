<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * İki faktörlü giriş yönetimi — KULLANICININ KENDİ hesabı .
 *
 * Akış (3 adım, kilitlenmeye karşı):
 *  1. `POST /me/2fa/setup` → sır + otpauth adresi döner (telefon uygulamasına okutulur).
 *     Bu aşamada 2FA ETKİN DEĞİLDİR — sır „onaysız" durur.
 *  2. `POST /me/2fa/confirm {code}` → telefondaki kod doğrulanırsa etkinleşir ve
 *     yedek kodlar DÖNER (bir kez; kaybeden hesabına giremez).
 *  3. Kapatmak için `POST /me/2fa/disable {password}` (şifre = „bu gerçekten benim" kanıtı).
 *
 * Yedek kodlar tek kullanımlıktır; `POST /me/2fa/recovery-codes {password}` seti yeniler
 * (eskiler ölür). Tüm uçlar firma bağlamı gerektirmez — 2FA kullanıcıya aittir, firmaya değil;
 * kullanıcı 3 firmada da olsa tek telefon kodu yeterlidir.
 */
class TwoFactorController extends Controller
{
    /**
     * Kurulumu başlat: sır + otpauth adresi (QR bu metinden üretilir).
     *
     * @response array{secret: string, otpauth_url: string, message: string}
     */
    public function setup(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('İki faktörlü giriş zaten etkin. Önce kapatın, sonra yeniden kurun.')],
            ]);
        }

        $secret = TwoFactor::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => TwoFactor::otpauthUrl($user->email, $secret),
            'message' => __('Telefonundaki doğrulama uygulamasına bu hesabı ekle, sonra alttaki kodla onayla.'),
        ]);
    }

    /**
     * Kurulumu onayla: telefondaki kod doğruysa 2FA etkinleşir, yedek kodlar döner.
     *
     * @response array{message: string, recovery_codes: array<int, string>, two_factor_enabled: bool}
     */
    public function confirm(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('İki faktörlü giriş zaten etkin.')],
            ]);
        }

        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Önce kurulumu başlatın.')],
            ]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        if (! TwoFactor::verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => [__('Kod doğrulanamadı. Telefonundaki güncel kodu girin.')],
            ]);
        }

        $codes = TwoFactor::generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                static fn (string $c): string => TwoFactor::hashRecoveryCode($c),
                $codes
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();

        Audit::fromRequest($request, 'auth.2fa.enabled', $user->current_company_id);

        return response()->json([
            'message' => __('İki faktörlü giriş etkin. Yedek kodları güvenli bir yere yazın; bir daha gösterilmez.'),
            'recovery_codes' => $codes,
            'two_factor_enabled' => true,
        ]);
    }

    /**
     * 2FA'yı kapat (şifre ister — oturumu ele geçiren biri tek tıkla kapatamasın).
     *
     * @response array{message: string, two_factor_enabled: bool}
     */
    public function disable(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('Şifre hatalı.')],
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Audit::fromRequest($request, 'auth.2fa.disabled', $user->current_company_id);

        return response()->json([
            'message' => __('İki faktörlü giriş kapatıldı.'),
            'two_factor_enabled' => false,
        ]);
    }

    /**
     * Yedek kod setini yenile (şifre ister; eskiler anında ölür).
     *
     * @response array{message: string, recovery_codes: array<int, string>}
     */
    public function regenerateCodes(Request $request)
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('İki faktörlü giriş etkin değil.')],
            ]);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('Şifre hatalı.')],
            ]);
        }

        $codes = TwoFactor::generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                static fn (string $c): string => TwoFactor::hashRecoveryCode($c),
                $codes
            ),
        ])->save();

        return response()->json([
            'message' => __('Yeni yedek kodlar üretildi. Eskiler artık geçersiz.'),
            'recovery_codes' => $codes,
        ]);
    }
}
