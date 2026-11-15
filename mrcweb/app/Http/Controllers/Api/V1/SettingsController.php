<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Firma başına ayarlar (settings: key/value). Key seti sabit whitelist'tir —
 * yeni ayar ihtiyacında bu dizi ve frontend form birlikte güncellenir.
 */
class SettingsController extends Controller
{
    /** Faz 1 ayar alanları */
    public const ALLOWED_KEYS = [
        'currency_code',          // TRY|USD|EUR (company.currency_code ile senkron)
        'default_tax_rate',       // 0-100
        'invoice_series_prefix',  // FT, MS...
        'default_payment_days',   // 0-365
    ];

    /**
     * Firma ayarları: para birimi, varsayılan KDV oranı, fatura seri öneki, varsayılan vade
     *
     * @response array{settings: array{currency_code: string, default_tax_rate: string, invoice_series_prefix: string, default_payment_days: string}}
     */
    public function index(Request $request)
    {
        $company = $request->user()->currentCompany;

        $settings = $company->settings()->whereIn('key', self::ALLOWED_KEYS)->pluck('value', 'key');

        return response()->json(['settings' => $this->withDefaults($settings)]);
    }

    /**
     * Firma ayarlarını güncelle (Admin)
     *
     * @response array{settings: array{currency_code: string, default_tax_rate: string, invoice_series_prefix: string, default_payment_days: string}}
     */
    public function update(Request $request)
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $data = $request->validate([
            'settings' => ['required', 'array', 'max:10'],
            'settings.currency_code' => ['sometimes', 'string', Rule::in(Currency::activeCodes())],
            'settings.default_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'settings.invoice_series_prefix' => ['sometimes', 'nullable', 'string', 'max:6', 'regex:/^[A-Za-z0-9]+$/'],
            'settings.default_payment_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        $company = $request->user()->currentCompany;

        foreach ($data['settings'] as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                continue; // bilinmeyen key'ler sessizce yoksayılır (API ileri uyumluluk)
            }

            // Boş değer = "bu ayarı sil, sistem varsayılanına dön". Örneğin fatura
            // seri öneki boş bırakılırsa sonraki faturalarda FTR öneki kullanılır;
            // kullanıcı kendi önekini yanlış yazdığını sonradan anlayabilir.
            if ($value === null || $value === '') {
                $company->settings()->where('key', $key)->delete();
                continue;
            }

            $company->setSetting($key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        // Para birimi tek doğruluk kaynağı: company.currency_code
        if (array_key_exists('currency_code', $data['settings'] ?? [])) {
            if ($data['settings']['currency_code'] === null || $data['settings']['currency_code'] === '') {
                $company->forceFill(['currency_code' => 'TRY'])->save();
            } else {
                $company->forceFill(['currency_code' => $data['settings']['currency_code']])->save();
            }
        }

        $settings = $company->settings()->whereIn('key', self::ALLOWED_KEYS)->pluck('value', 'key');

        // Değişen anahtarlar + yeni değerler loglanır (ayar değerlerinde sır yoktur).
        Audit::fromRequest($request, 'settings.updated', $company->id, null, [
            'changed' => $data['settings'],
        ]);

        return response()->json(['settings' => $this->withDefaults($settings)]);
    }

    protected function withDefaults($settings): array
    {
        return [
            'currency_code' => $settings['currency_code'] ?? 'TRY',
            'default_tax_rate' => $settings['default_tax_rate'] ?? '20',
            'invoice_series_prefix' => $settings['invoice_series_prefix'] ?? 'FT',
            'default_payment_days' => $settings['default_payment_days'] ?? '7',
        ];
    }
}
