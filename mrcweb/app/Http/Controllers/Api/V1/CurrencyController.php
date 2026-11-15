<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\Audit;
use App\Support\ExchangeRates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Para birimleri + kurlar .
 *
 * Liste herkese açık (Personel de „dolar bugün kaç" bilmeli); tanımlama YALNIZ Admin.
 * Kodlar ISO'dur, sonradan eklenmez/çıkarılmaz — Admin yalnız bayrak + görünümü yönetir
 * (etkin/pasif, ondalık, sembol). Yeni para birimi gereksinimi kod değişikliğidir
 * (doğrulama listeleri + kur çekme bu tabloya göre çalışır).
 */
class CurrencyController extends Controller
{
    /**
     * Para birimleri + bugünkü kurlarıyla (tek çağrıda kart beslenir).
     *
     * @response array{data: array<int, array{code: string, name: string, symbol: string, decimals: int, is_active: bool, rate_today: float|null, rate_date: string|null, rate_source: string|null}>}
     */
    public function index()
    {
        $today = now()->format('Y-m-d');

        $rows = Currency::orderBy('sort_order')->orderBy('code')->get()->map(function (Currency $c) use ($today) {
            if ($c->code === 'TRY') {
                return [
                    'code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol,
                    'decimals' => $c->decimals, 'is_active' => $c->is_active,
                    'rate_today' => 1.0, 'rate_date' => $today, 'rate_source' => 'sabit',
                ];
            }

            $row = ExchangeRate::where('currency_code', $c->code)
                ->where('rate_date', '<=', $today)
                ->orderByDesc('rate_date')
                ->first();

            return [
                'code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol,
                'decimals' => $c->decimals, 'is_active' => $c->is_active,
                'rate_today' => $row === null ? null : (float) $row->rate,
                'rate_date' => $row === null ? null : substr((string) $row->rate_date, 0, 10),
                'rate_source' => $row?->source,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Para birimi tanımını güncelle (Admin): etkin/pasif + görünüm.
     *
     * @response array{currency: array{code: string, name: string, symbol: string, decimals: int, is_active: bool}, message: string}
     */
    public function update(Request $request, string $code)
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $currency = Currency::where('code', strtoupper($code))->first();

        if ($currency === null) {
            abort(404, __('Para birimi bulunamadı.'));
        }

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'symbol' => ['sometimes', 'string', 'max:8'],
            'name' => ['sometimes', 'string', 'max:60'],
        ]);

        if (array_key_exists('is_active', $data) && $data['is_active'] === false && $currency->code === 'TRY') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'is_active' => [__('Ana para birimi kapatılamaz.')],
            ]);
        }

        $currency->fill($data)->save();
        Currency::forgetActiveCodes();

        Audit::fromRequest($request, 'settings.updated', (int) $request->user()->current_company_id, null, [
            'changed' => ['currency:'.$currency->code => $data],
        ]);

        return response()->json([
            'currency' => $currency->only(['code', 'name', 'symbol', 'decimals', 'is_active']),
            'message' => __('Para birimi güncellendi.'),
        ]);
    }

    /**
     * Kurları şimdi çek (Admin): cron beklenmeden TCMB+yedekten günceller.
     * Başarısızsa 422 (son geçerli kur durur, tabloya dokunulmaz).
     *
     * @response array{source: string, rates: array, message: string}
     */
    public function fetch(Request $request)
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }

        $result = \App\Support\ExchangeRates::fetchDaily();

        if ($result['source'] === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'source' => [__('Kur çekilemedi (TCMB + yedek düştü). Son geçerli kur kullanılıyor.')],
            ]);
        }

        Audit::fromRequest($request, 'settings.updated', (int) $request->user()->current_company_id, null, [
            'changed' => ['exchange_rates_fetch' => $result['source']],
        ]);

        return response()->json([
            'source' => $result['source'],
            'rates' => $result['rates'],
            'message' => __('Kurlar güncellendi.'),
        ]);
    }

    /**
     * Kur geçmişi / bugünkü kurlar.
     *
     * Parametresiz: etkin paraların bugünkü (yoksa son) kurları.
     * `currency` + `from/to` ile o paranın tarih aralığı dökülür (grafik besler).
     *
     * @response array{data: array<int, array{currency_code: string, rate_date: string, rate: float, source: string}>}
     */
    public function rates(Request $request)
    {
        $data = $request->validate([
            'currency' => ['nullable', 'string', Rule::in(Currency::query()->pluck('code')->all())],
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        if (! empty($data['currency'])) {
            $query = ExchangeRate::where('currency_code', strtoupper($data['currency']));

            if (! empty($data['from'])) {
                $query->where('rate_date', '>=', $data['from']);
            }
            if (! empty($data['to'])) {
                $query->where('rate_date', '<=', $data['to']);
            }
            if (! empty($data['date'])) {
                $query->where('rate_date', $data['date']);
            }

            $rows = $query->orderByDesc('rate_date')->limit(90)->get();
        } else {
            $day = $data['date'] ?? now()->format('Y-m-d');

            $rows = collect(Currency::activeCodes())
                ->filter(fn (string $code) => $code !== 'TRY')
                ->map(fn (string $code) => ExchangeRate::where('currency_code', $code)
                    ->where('rate_date', '<=', $day)
                    ->orderByDesc('rate_date')
                    ->first())
                ->filter()
                ->values();
        }

        return response()->json(['data' => $rows->map(fn (ExchangeRate $r) => [
            'currency_code' => $r->currency_code,
            'rate_date' => substr((string) $r->rate_date, 0, 10),
            'rate' => (float) $r->rate,
            'source' => $r->source,
        ])->values()]);
    }
}
