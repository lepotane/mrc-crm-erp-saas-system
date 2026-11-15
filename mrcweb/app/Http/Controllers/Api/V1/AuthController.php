<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmailMail;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Audit;
use App\Support\SessionDevice;
use App\Support\TwoFactor;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class AuthController extends Controller
{
    /**
     * Kayıt ol: kullanıcı + kendi firması oluşturulur, Admin rolü verilir ve firma bağlamı kurulur
     *
     * Kayıt: kullanıcı + firma (tenant) + Admin rolü (firma=team) + varsayılan bağlam.
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{token: string, user: array{id: int, name: string, email: string}, company: array{id: int, name: string, slug: string}}',
    )]
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'company_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_number' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:80'],
            'require_approval' => ['nullable', 'boolean'],
        ]);

        $requireApproval = (bool) ($data['require_approval'] ?? false);

        $user = null;
        $company = null;

        DB::transaction(function () use (&$user, &$company, $data, $requireApproval) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'user_class' => 'Yönetici',
            ]);

            // Firma + varsayılan depo/birim + üyelik + roller: kurulum aracıyla
            // (mrc:create-admin) ortak TenantProvisioner üzerinden yürür.
            $company = app(TenantProvisioner::class)->provision($user, $data['company_name']);

            $company->forceFill([
                'approval_status' => $requireApproval ? 'pending' : 'active',
                'tax_number' => $data['tax_number'] ?? $company->tax_number,
                'city' => $data['city'] ?? $company->city,
                'phone' => $data['phone'] ?? $company->phone,
            ])->save();
        });

        $this->sendVerificationMail($user);

        if ($requireApproval) {
            // SüperAdmin kullanıcılarına yeni firma kurulum başvurusu bildirimi düşür
            $superAdmins = User::where('is_superadmin', true)->get();
            foreach ($superAdmins as $sa) {
                \App\Models\AppNotification::pushToCompany(
                    (int) ($sa->current_company_id ?: $company->id),
                    __('Yeni Firma Kurulum Başvurusu'),
                    __(':company (:email) yönetici onayı bekliyor.', ['company' => $company->name, 'email' => $user->email]),
                    'warning',
                    '/yonetim-ussu',
                    '#DC6F31',
                    $sa->id
                );
            }

            return response()->json([
                'pending_approval' => true,
                'message' => __('Firma kurulum başvurunuz alındı. Sistem yöneticisi onayladıktan sonra hesabınız aktifleşecek ve giriş yapabileceksiniz.'),
                'user' => array_merge($user->only(['id', 'name', 'email']), ['email_verified' => $user->hasVerifiedEmail()]),
                'company' => $company->only(['id', 'name', 'slug', 'approval_status']),
            ], 201);
        }

        // Oturum anahtarı sürelidir: çıkış unutulsa bile düşer (config/api.php).
        $ttl = (int) config('api.session_token_ttl_days', 14);
        $created = $user->createToken('auth', ['*'], $ttl > 0 ? now()->addDays($ttl) : null);
        $token = $created->plainTextToken;
        SessionDevice::stamp($created, $request);

        return response()->json([
            'token' => $token,
            'user' => array_merge($user->only(['id', 'name', 'email']), ['email_verified' => $user->hasVerifiedEmail()]),
            'company' => $company->only(['id', 'name', 'slug']),
        ], 201);
    }

    /**
     * Şifre sıfırlama bağlantısı iste: hesap varsa posta gider, yoksa da 200 döner (kullanıcı sayımı yapılamaz).
     *
     * @response array{message: string}
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user !== null) {
            $token = Password::broker()->createToken($user);

            Mail::to($user->email)->send(new ResetPasswordMail(
                $user->name,
                rtrim((string) config('app.url'), '/')
                    .'/sifre-sifirla?token='.$token.'&email='.urlencode($user->email),
            ));

            Audit::record('auth.password_reset_requested', $user->current_company_id, $user->id);
        }

        return response()->json(['message' => __('E-posta kayıtlıysa sıfırlama bağlantısı gönderildi.')]);
    }

    /**
     * Şifreyi yenile: bağlantıdaki jeton + e-posta + iki kez yeni şifre.
     *
     * Başarıda tüm erişim anahtarları düşer (çalıntı oturum kapanır), kullanıcı
     * yeni şifreyle giriş yapar.
     *
     * @response array{message: string}
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $durum = Password::broker()->reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));

                Audit::record('auth.password_reset', $user->current_company_id, $user->id);
            }
        );

        if ($durum !== Password::PASSWORD_RESET) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'token' => [__('Bağlantı geçersiz ya da süresi dolmuş. Yeni bağlantı isteyin.')],
            ]);
        }

        return response()->json(['message' => __('Şifren yenilendi. Yeni şifrenle giriş yap.')]);
    }

    /**
     * E-postayı doğrula: postadaki imzalı bağlantı buraya gelir, kayıt düşüp SPA başarı sayfasına 302 atılır.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1((string) $user->email), $hash)) {
            abort(404, __('Kayıt bulunamadı.'));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            Audit::record('auth.email_verified', $user->current_company_id, $user->id);
        }

        return redirect()->to(rtrim((string) config('app.url'), '/').'/dogrulandi?email='.urlencode($user->email));
    }

    /**
     * Doğrulama postasını yeniden gönder (yalnız doğrulanmamış hesap).
     *
     * @response array{message: string}
     */
    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => __('E-postan zaten doğrulanmış.')]);
        }

        $this->sendVerificationMail($user);

        Audit::fromRequest($request, 'auth.verification_sent', $user->current_company_id);

        return response()->json(['message' => __('Doğrulama bağlantısı yeniden gönderildi.')]);
    }

    /** Doğrulama postası hazırlar (kayıt + yeniden gönderim ortak). */
    protected function sendVerificationMail(User $user): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(24),
            ['id' => $user->id, 'hash' => sha1((string) $user->email)]
        );

        Mail::to($user->email)->send(new VerifyEmailMail($user->name, $url));
    }

    /**
     * Giriş yap: Bearer erişim anahtarı döner (oturum anahtarı, süreli)
     *
     * @response array{
     *     token: string,
     *     user: array{id: int, name: string, email: string, current_company_id: int},
     *     company: array{id: int, name: string, slug: string, currency_code: string},
     * }
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => __('E-posta veya şifre hatalı.'),
                'errors' => ['email' => [__('Girilen bilgiler doğrulanamadı.')]],
            ], 422);
        }

        // Aktif firma bağlamı yoksa ilk üyelik varsayılan olur
        if ($user->current_company_id === null) {
            $first = DB::table('company_user')->where('user_id', $user->id)->orderBy('company_id')->value('company_id');
            if ($first) {
                $user->forceFill(['current_company_id' => $first])->save();
            }
        }

        $company = $user->currentCompany;

        // SüperAdmin değilse firma onay durumu ve kullanım süresi denetlenir
        if (! $user->isSuperAdmin() && $company) {
            if ($company->approval_status === 'pending') {
                return response()->json([
                    'message' => __('Firmanız henüz sistem yöneticisi tarafından onaylanmadı. Onaylandığında giriş yapabilirsiniz.'),
                    'code' => 'company_pending_approval',
                ], 403);
            }

            if ($company->approval_status === 'suspended' || $company->approval_status === 'rejected') {
                return response()->json([
                    'message' => __('Firma hesabınızın erişimi kısıtlanmıştır. Sistem yöneticisiyle iletişime geçin.'),
                    'code' => 'company_suspended',
                ], 403);
            }

            if ($company->isSubscriptionExpired()) {
                return response()->json([
                    'message' => __('Firma kullanım süreniz dolmuştur. Süre uzatımı için yöneticiyle iletişime geçin.'),
                    'code' => 'subscription_expired',
                ], 403);
            }
        }

        $user->forceFill(['last_login_at' => now()])->save();

        // 2FA etkinse TAM anahtar verilmez: 10 dakikalık „sınama" anahtarı döner
        // . Sınama anahtarıyla YALNIZ challenge ucu çalışır;
        // yetenek denetimi uçta yapılır (token.scope dışında tutulur).
        if ($user->hasTwoFactorEnabled()) {
            $challenge = $user->createToken('2fa-challenge', ['2fa:challenge'], now()->addMinutes(10));

            return response()->json([
                'requires_two_factor' => true,
                'challenge_token' => $challenge->plainTextToken,
                'expires_in_seconds' => 600,
                'user' => array_merge($user->only(['id', 'name', 'email']), ['email_verified' => $user->hasVerifiedEmail()]),
            ], 202);
        }

        // Oturum anahtarı sürelidir: çıkış unutulsa bile süre dolunca düşer.
        $ttl = (int) config('api.session_token_ttl_days', 14);
        $abilities = $user->isSuperAdmin() ? ['*', 'superadmin:access'] : ['*'];
        $created = $user->createToken('auth', $abilities, $ttl > 0 ? now()->addDays($ttl) : null);
        $token = $created->plainTextToken;
        SessionDevice::stamp($created, $request);
        $companies = $user->isSuperAdmin()
            ? Company::orderBy('name')->get(['id', 'name', 'slug'])
            : $user->companies()->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.slug']);

        // Girişte istek henüz kimliksizdir ($request->user() null) — kimlik AÇIKÇA verilir.
        Audit::record('auth.login', $user->current_company_id, $user->id, null, [], $request->ip());

        return response()->json([
            'token' => $token,
            'user' => array_merge(
                $user->only(['id', 'name', 'email', 'current_company_id', 'user_class', 'job_title', 'avatar_color']),
                [
                    'email_verified' => $user->hasVerifiedEmail(),
                    'is_superadmin' => $user->isSuperAdmin(),
                ]
            ),
            'company' => $company ? array_merge(
                $company->only(['id', 'name', 'slug', 'currency_code', 'plan', 'approval_status', 'subscription_ends_at', 'allowed_modules', 'custom_limits', 'max_users']),
                ['is_expired' => $company->isSubscriptionExpired()]
            ) : null,
            'companies' => $companies,
        ]);
    }

    /**
     * Çıkış yap: kullanılan erişim anahtarı iptal edilir
     *
     * @response array{message: string}
     */
    public function logout(Request $request)
    {
        Audit::fromRequest($request, 'auth.logout', $request->user()?->current_company_id);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Çıkış yapıldı.')]);
    }

    /**
     * 2FA sınaması: sınama anahtarı + telefon kodu (veya yedek kod) → TAM oturum.
     *
     * Kapı serttir: sınama anahtarı bu uca özeldir (başka uçta geçmez), 5 yanlış
     * denemede anahtar ÖLÜR (kaba kuvvet 6 haneli kodu deneyemez), yedek kodlar
     * TEK KULLANIMLIKTIR. Başarıda sınama anahtarı silinir, normal oturum açılır.
     *
     * @response array{token: string, user: array, company: array|null, companies: array}
     */
    public function challenge(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        // Yetenek denetimi burada: sınama anahtarı „sadece bu uç" demektir.
        // Normal oturum/anahtarla bu uca gelinirse reddedilir (kafa karışıklığı yok).
        if (! $token || ($token->abilities ?? []) !== ['2fa:challenge']) {
            return response()->json(['message' => __('Bu uç yalnız iki faktörlü giriş sınaması içindir.')], 403);
        }

        $user = $request->user();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        if (empty($data['code']) && empty($data['recovery_code'])) {
            return response()->json([
                'message' => __('Doğrulama kodu gerekli.'),
                'errors' => ['code' => [__('Telefonundaki güncel kodu veya yedek kodlarından birini girin.')]],
            ], 422);
        }

        if (! $user->hasTwoFactorEnabled()) {
            $token->delete();

            return response()->json(['message' => __('Bu hesapta iki faktörlü giriş etkin değil.')], 422);
        }

        $attemptKey = '2fa:attempts:'.$token->id;

        if (($this->challengeAttempts($attemptKey)) >= 5) {
            $token->delete();
            Cache::forget($attemptKey);

            return response()->json(['message' => __('Çok fazla yanlış deneme. Yeniden giriş yapın.')], 403);
        }

        $passed = false;
        $burnedRecovery = null;

        if (! empty($data['code']) && $user->two_factor_secret !== null) {
            $passed = TwoFactor::verify($user->two_factor_secret, $data['code']);
        } elseif (! empty($data['recovery_code'])) {
            $burnedRecovery = $this->consumeRecoveryCode($user, $data['recovery_code']);
            $passed = $burnedRecovery !== null;
        }

        if (! $passed) {
            // Sayaç 10 dakika yaşar (sınama anahtarıyla aynı ömür); ilk yazımda
            // TTL kurulur çünkü increment() TTL taşımaz.
            if (! Cache::has($attemptKey)) {
                Cache::put($attemptKey, 0, now()->addMinutes(10));
            }
            $attempts = (int) Cache::increment($attemptKey);

            // 5. yanlışta sınama ANINDA ölür: kaba kuvvet 6 haneyi deneyemez.
            if ($attempts >= 5) {
                $token->delete();
                Cache::forget($attemptKey);

                return response()->json(['message' => __('Çok fazla yanlış deneme. Yeniden giriş yapın.')], 403);
            }

            return response()->json([
                'message' => __('Kod doğrulanamadı.'),
                'errors' => ['code' => [__('Kod hatalı. Kalan deneme: :count', ['count' => 5 - $attempts])]],
            ], 422);
        }

        Cache::forget($attemptKey);
        $token->delete();

        $user->forceFill(['last_login_at' => now()])->save();

        $ttl = (int) config('api.session_token_ttl_days', 14);
        $created = $user->createToken('auth', ['*'], $ttl > 0 ? now()->addDays($ttl) : null);
        $session = $created->plainTextToken;
        SessionDevice::stamp($created, $request);
        $company = $user->currentCompany;

        Audit::record('auth.login', $user->current_company_id, $user->id, null,
            $burnedRecovery !== null ? ['via' => 'recovery_code'] : [], $request->ip());
        $companies = $user->isSuperAdmin()
            ? Company::orderBy('name')->get(['id', 'name', 'slug'])
            : $user->companies()->orderBy('companies.name')->get(['companies.id', 'companies.name', 'companies.slug']);

        return response()->json([
            'token' => $session,
            'user' => array_merge(
                $user->only(['id', 'name', 'email', 'current_company_id']),
                ['email_verified' => $user->hasVerifiedEmail()]
            ),
            'company' => $company?->only(['id', 'name', 'slug', 'currency_code']),
            'companies' => $companies,
            'recovery_codes_remaining' => $burnedRecovery !== null
                ? count($user->refresh()->two_factor_recovery_codes ?? [])
                : null,
        ]);
    }

    /** Yedek kodu yak: listedeyse sil ve true dön (tek kullanım). */
    protected function consumeRecoveryCode(User $user, string $code): ?string
    {
        $hash = TwoFactor::hashRecoveryCode($code);
        $stored = $user->two_factor_recovery_codes ?? [];

        $index = array_search($hash, $stored, true);

        if ($index === false) {
            return null;
        }

        unset($stored[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

        return $code;
    }

    protected function challengeAttempts(string $key): int
    {
        return (int) (Cache::get($key) ?? 0);
    }
}
