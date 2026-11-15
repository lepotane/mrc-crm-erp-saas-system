<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\PaymentCredential;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Firmanın ödeme hesapları : PayTR ve iyzico bilgileri.
 *
 * Yetki: YALNIZ Admin. Bu alanlar firmadan para tahsil eden kasanın anahtarıdır;
 * Personel'in görmesi/degistirmesi uygun degil (kişi değiştirince para akışı kopar).
 *
 * Sır sözleşmesi: GET yanıtında anahtarlar maskelenir (`••••abcd`), tam değer bir daha
 * okunmaz (PAT anahtarlarındaki davranışla aynı). PUT'ta boş bırakılan alan MEVCUT
 * değeri korur — kullanıcı maskeyi görünce onu silmek zorunda kalmamalı. Tüm hesabı
 * kaldırmak isteyen DELETE /payment-settings/{provider} kullanır.
 */
class PaymentSettingController extends Controller
{
    use ResolvesTenant;

    /** Yazılabilir metin alanları (değer şifreli kolona gider). */
    private const TEXT_FIELDS = [
        PaymentCredential::PROVIDER_PAYTR => ['merchant_id', 'merchant_key', 'merchant_salt'],
        PaymentCredential::PROVIDER_IYZICO => ['api_key', 'secret_key'],
    ];

    /** İsteğe bağlı davranış alanları (taksit/oturum). */
    private const OPTION_FIELDS = ['no_installment', 'max_installment', 'timeout_limit'];

    /**
     * İki sağlayıcının durumu da döner (eksik olan `configured: false` ile görünür):
     * SPA'da iki kart yan yana açılsın, kullanıcı hangisinin eksik olduğunu görsün.
     *
     * @response array{data: array<int, array{provider: string, label: string, configured: bool, is_active: bool, is_sandbox: bool, fields: array, missing_fields: array, bank_account_id: int|null, bank_account_name: string|null, callback_url: string, updated_at: string|null}>}
     */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);
        $rows = [];

        foreach (PaymentCredential::PROVIDERS as $provider) {
            $credential = PaymentCredential::where('company_id', $company->id)->where('provider', $provider)->first();

            $rows[] = $this->presentRow($company, $provider, $credential);
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Sağlayıcı hesabını kaydet/güncelle (Admin).
     *
     * @response array{data: array{provider: string, label: string, configured: bool, is_active: bool, is_sandbox: bool, fields: array, missing_fields: array, bank_account_id: int|null, bank_account_name: string|null, callback_url: string, updated_at: string|null}, message: string}
     */
    public function update(Request $request, string $provider)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);

        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(PaymentCredential::PROVIDERS)],
            'credentials' => ['sometimes', 'array'],
            'credentials.merchant_id' => ['nullable', 'string', 'max:32'],
            'credentials.merchant_key' => ['nullable', 'string', 'max:128'],
            'credentials.merchant_salt' => ['nullable', 'string', 'max:128'],
            'credentials.api_key' => ['nullable', 'string', 'max:128'],
            'credentials.secret_key' => ['nullable', 'string', 'max:128'],
            'is_sandbox' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $company->id)],
            'no_installment' => ['sometimes', 'boolean'],
            'max_installment' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'timeout_limit' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);

        $provider = $data['provider'] ?? $provider;

        if (! in_array($provider, PaymentCredential::PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'provider' => [__('Desteklenmeyen ödeme sağlayıcısı: :provider', ['provider' => $provider])],
            ]);
        }

        $credential = PaymentCredential::firstOrNew([
            'company_id' => $company->id,
            'provider' => $provider,
        ]);

        $credentials = (array) ($credential->credentials ?? []);

        // Boş/eksik metin alanı = "mevcut değeri koru". Kullanıcı maskeyi görüyor;
        // formu yeniden gönderirken anahtarını tekrar yazması istenmez.
        foreach (self::TEXT_FIELDS[$provider] as $field) {
            $value = $data['credentials'][$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $credentials[$field] = trim($value);
            }
        }

        foreach (self::OPTION_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $credentials[$field] = $data[$field];
            }
        }

        $credential->credentials = $credentials;
        $credential->is_sandbox = array_key_exists('is_sandbox', $data)
            ? (bool) $data['is_sandbox']
            : ($credential->exists ? (bool) $credential->is_sandbox : true);
        $credential->bank_account_id = array_key_exists('bank_account_id', $data)
            ? ($data['bank_account_id'] ?? null)
            : $credential->bank_account_id;

        // Etkinleştirme ancak bilgiler eksiksizse geçerlidir: eksik hesapla "etkin"
        // demek, link üretiminde anlaşılmaz bir hataya davetiye çıkarmaktır.
        $wantsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $credential->is_active;

        if ($wantsActive) {
            $candidate = new PaymentCredential(['provider' => $provider]);
            $candidate->credentials = $credentials;
            $missing = $candidate->missingFields();

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'credentials' => [__(
                        'Ödeme hesabını etkinleştirmek için eksik alanları doldurun: :fields',
                        ['fields' => implode(', ', array_map(
                            static fn (string $key): string => __(\App\Support\Payments\GatewayResolver::FIELD_LABELS[$key] ?? $key),
                            $missing
                        ))]
                    )],
                ]);
            }
        }

        $credential->is_active = $wantsActive;

        if (! $credential->exists) {
            $credential->created_by = $request->user()->id;
        }

        $credential->save();

        // Meta'ya sır DEĞİL durum yazılır: provider + bayraklar. Anahtar/salt/secret
        // bu tabloya giremez .
        Audit::fromRequest($request, 'payment_settings.updated', $company->id, $credential, [
            'provider' => $provider,
            'is_active' => (bool) $credential->is_active,
            'is_sandbox' => (bool) $credential->is_sandbox,
        ]);

        return response()->json([
            'data' => $this->presentRow($company, $provider, $credential->refresh()),
            'message' => __('Ödeme hesapları kaydedildi.'),
        ]);
    }

    /**
     * Sağlayıcı hesabını tümüyle kaldır (Admin). Silmek, "pasifleştir"ten farklıdır:
     * anahtarlar veritabanından düşer, firmanın o sağlayıcıyla tahsilatı durur.
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, string $provider)
    {
        $this->ensureAdmin($request);

        $deleted = PaymentCredential::where('company_id', $this->tenantId($request))
            ->where('provider', $provider)
            ->delete();

        abort_unless($deleted > 0, 404, __('Ödeme hesabı bulunamadı.'));

        Audit::fromRequest($request, 'payment_settings.updated', $this->tenantId($request), null, [
            'provider' => $provider,
            'removed' => true,
        ]);

        return response()->json(['message' => __('Ödeme hesabı kaldırıldı.')]);
    }

    /**
     * Bildirim adresi (PayTR mağaza panelinde "Bildirim URL", iyzico'da entegrasyon
     * ayarı olarak girilir). Çok kiracılı yapıda TEK adres kullanılır: gelen bildirim
     * link token'ımız üzerinden hangi firmaya ait olduğu bulunur.
     */
    protected function callbackUrl(string $provider): string
    {
        $path = $provider === PaymentCredential::PROVIDER_PAYTR
            ? config('services.paytr.callback_path', '/api/paytr/callback')
            : config('services.iyzico.callback_path', '/api/iyzico/callback');

        return url($path);
    }

    protected function presentRow($company, string $provider, ?PaymentCredential $credential): array
    {
        return [
            'provider' => $provider,
            'label' => PaymentCredential::label($provider),
            'configured' => $credential !== null,
            'is_active' => (bool) ($credential?->is_active ?? false),
            'is_sandbox' => (bool) ($credential?->is_sandbox ?? true),
            'fields' => $credential?->masked() ?? (object) [],
            'missing_fields' => $credential?->missingFields() ?? PaymentCredential::REQUIRED_FIELDS[$provider],
            'bank_account_id' => $credential?->bank_account_id,
            'bank_account_name' => $credential?->bankAccount?->name,
            'callback_url' => $this->callbackUrl($provider),
            'updated_at' => $credential?->updated_at?->format('Y-m-d H:i'),
        ];
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
