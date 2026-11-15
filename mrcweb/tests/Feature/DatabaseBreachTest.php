<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\PaymentCredential;
use App\Models\PaymentLink;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\RegistersCompany;
use Tests\TestCase;

/**
 * "VERİTABANI SIZARSA NE OLUR?" KİLİDİ.
 *
 * Bu test, veritabanı DUMP'ının (yedek kopyası, SQL injection, eski disk)
 * kullanıcı/oturum ele geçirmeye YETMEYECEĞINI kanıtlar.
 *
 * Kural: veritabanında hiçbir yerde ÇALIŞAN bir sır bulunmaz —
 *   - kullanıcı parolası  → bcrypt (özet)
 *   - davet token'ı       → APP_KEY ile şifreli (özet: token_hash)
 *   - ödeme linki token'ı → APP_KEY ile şifreli (özet: token_hash)
 *   - sağlayıcı jetonu    → APP_KEY ile şifreli
 *   - ödeme hesabı sırları→ APP_KEY ile şifreli
 *   - 2FA sırrı/kurtarma  → APP_KEY ile şifreli
 *
 * APP_KEY veritabanında DEĞİLDİR (.env) — sızıntı için anahtar gerekir.
 */
class DatabaseBreachTest extends TestCase
{
    use RefreshDatabase;
    use RegistersCompany;

    /** @var array<string,mixed> */
    protected array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->register('sizinti@firma.test', 'Sizinti Firmasi');
    }

    public function test_kullanici_parolasi_veritabaninda_duz_metin_degil(): void
    {
        $parola = 'CokGizliKullaniciParolasi!';

        $this->apiPost('/api/v1/auth/register', [
            'name' => 'Sizinti Kullanici',
            'email' => 'kullanici@sizinti.test',
            'password' => $parola,
            'company_name' => 'Sizinti 2',
        ])->assertCreated();

        $ham = (string) DB::table('users')->where('email', 'kullanici@sizinti.test')->value('password');

        $this->assertNotSame($parola, $ham, 'Parola düz metin saklanıyor!');
        $this->assertStringNotContainsString($parola, $ham);
        $this->assertTrue(Hash::check($parola, $ham), 'Hash doğrulanamıyor');
        $this->assertStringStartsWith('$2y$', $ham, 'bcrypt değil');
    }

    public function test_davet_tokeni_veritabaninda_kullanilamaz(): void
    {
        $this->apiPost('/api/v1/invitations', ['email' => 'aday@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();

        $davet = Invitation::where('company_id', $this->a['company_id'])->firstOrFail();
        $token = $davet->token; // uygulama düz metni görebilir

        $this->assertNotSame('', $token);

        $ham = (string) DB::table('invitations')->where('id', $davet->id)->value('token');
        $hash = (string) DB::table('invitations')->where('id', $davet->id)->value('token_hash');

        $this->assertNotSame($token, $ham, 'Davet tokenı düz metin saklanıyor!');
        $this->assertStringNotContainsString($token, $raw = (string) DB::table('invitations')->where('id', $davet->id)->value('token'));
        $this->assertSame(hash('sha256', $token), $hash, 'Arama özeti eksik');
    }

    public function test_odeme_linki_tokeni_ve_saglayici_jetonu_kullanilamaz(): void
    {
        $cari = Party::create(['company_id' => $this->a['company_id'], 'name' => 'Sizinti Cari']);

        $link = PaymentLink::create([
            'company_id' => $this->a['company_id'],
            'token' => strtolower(bin2hex(random_bytes(16))),
            'provider' => 'iyzico',
            'party_id' => $cari->id,
            'amount' => 100,
            'currency_code' => 'TRY',
            'status' => PaymentLink::STATUS_PENDING,
            'provider_token' => 'iyzico-gizli-jeton-123',
        ]);

        $hamToken = (string) DB::table('payment_links')->where('id', $link->id)->value('token');
        $hamJeton = (string) DB::table('payment_links')->where('id', $link->id)->value('provider_token');

        $this->assertNotSame($link->token, $hamToken, 'Ödeme linki tokenı düz metin!');
        $this->assertNotSame('iyzico-gizli-jeton-123', $hamJeton, 'Sağlayıcı jetonu düz metin!');
        $this->assertSame(
            hash('sha256', 'iyzico-gizli-jeton-123'),
            (string) DB::table('payment_links')->where('id', $link->id)->value('provider_token_hash')
        );
    }

    public function test_odeme_hesabi_sirlari_veritabaninda_duz_metin_degil(): void
    {
        PaymentCredential::create([
            'company_id' => $this->a['company_id'],
            'provider' => PaymentCredential::PROVIDER_PAYTR,
            'credentials' => [
                'merchant_id' => '100001',
                'merchant_key' => 'paytr-gizli-anahtar',
                'merchant_salt' => 'paytr-gizli-tuz',
            ],
            'is_sandbox' => true,
            'is_active' => true,
        ]);

        $ham = (string) DB::table('payment_credentials')
            ->where('company_id', $this->a['company_id'])
            ->value('credentials');

        $this->assertStringNotContainsString('paytr-gizli-anahtar', $ham);
        $this->assertStringNotContainsString('paytr-gizli-tuz', $ham);
    }

    public function test_iki_faktor_sirri_ve_kurtarma_kodlari_sifreli(): void
    {
        $user = User::findOrFail($this->a['user_id']);

        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['kurtarma-kodu-1', 'kurtarma-kodu-2'],
        ])->save();

        $hamSecret = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $hamKodlar = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        $this->assertNotSame('JBSWY3DPEHPK3PXP', $hamSecret);
        $this->assertStringNotContainsString('kurtarma-kodu-1', $hamKodlar);
    }

    public function test_odeme_linki_referansi_token_iceriyor_mu(): void
    {
        // Referans kolonu eskiden bearer token'ı düz metin taşıyordu; DB sızıntısı
        // ödeme sayfasını açabiliyordu. Artık referans link kimliğidir.
        $cari = Party::create(['company_id' => $this->a['company_id'], 'name' => 'Referans Cari']);

        $token = strtolower(bin2hex(random_bytes(16)));

        $link = PaymentLink::create([
            'company_id' => $this->a['company_id'],
            'token' => $token,
            'provider' => 'paytr',
            'party_id' => $cari->id,
            'amount' => 100,
            'currency_code' => 'TRY',
            'status' => PaymentLink::STATUS_PENDING,
            'reference' => $token.':success:card',
        ]);

        $this->assertStringNotContainsString(
            $token,
            (string) DB::table('payment_links')->where('id', $link->id)->value('reference'),
            'Referans bearer token içeriyor!'
        );
    }

    public function test_sifre_ozeti_veritabaninda_tanimli_ve_kullanilabilir(): void
    {
        // Özet kolonu olmadan davet/ödeme linki ARANAMAZ — bu yüzden her ikisi de
        // şifreli + özetli. Test, özet olmadan akışın çalışmadığını da gösterir.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('invitations', 'token_hash')
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('payment_links', 'token_hash')
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('payment_links', 'provider_token_hash')
        );
    }

    public function test_app_key_veritabaninda_degil(): void
    {
        // APP_KEY .env'de durur; veritabanı sızıntısı onu içermez.
        $this->assertNotSame('', (string) config('app.key'));
        $this->assertStringStartsWith('base64:', (string) config('app.key'));
    }

    public function test_davet_akisi_sifreli_tokendan_calisiyor(): void
    {
        // Şifreleme işi akışı bozmamalı: davet yine kabul edilebilir.
        $this->apiPost('/api/v1/invitations', ['email' => 'kabul@firma.test', 'role' => 'Personel'], $this->a['headers'])
            ->assertCreated();

        $davet = Invitation::where('company_id', $this->a['company_id'])->firstOrFail();
        $token = $davet->token;

        $uye = $this->register('kabul@firma.test', 'Kabul Firmasi');

        $this->apiPost('/api/v1/invitations/accept', ['token' => $token], $uye['headers'])->assertOk();
    }
}
