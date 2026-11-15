<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Günlük kur çekme + okuma .
 *
 * Kaynak zinciri: **TCMB** (`today.xml`, `ForexBuying`) → **er-api** (ücretsiz, anahtarsız,
 * TRY-bazlı). İkisi de düşerse SON GEÇERLİ kur kullanılır + hata loglanır (kur yoksa
 * belge 1 kuruyla kesilir — iş durmaz, muhasebe kirlenmez).
 *
 * Tutarlılık denetimi: iki kaynak da cevap verirse USD kuru karşılaştırılır; %5'ten fazla
 * sapma `warning` düşer (kaynaklardan biri saçmalıyor demektir, hangisi olduğu loglanır).
 * Denetim günde bir kez koşar (`currency:fetch`), istek başına maliyet yoktur.
 *
 * Birim sözleşmesi: `rate` = 1 birim döviz başına TRY (TCMB geleneği). er-api TRY-bazlı
 * (`1 TRY = x USD`) verdiği için TERS ÇEVİRİLİR — çevirmeyi unutmak kuru 30 kat
 * şaşırtır, test kilitli.
 */
class ExchangeRates
{
    public const SOURCE_TCMB = 'tcmb';

    public const SOURCE_ERAPI = 'erapi';

    /** Sapma eşiği: iki kaynak bu orandan fazla ayrışırsa uyar. */
    public const DEVIATION_THRESHOLD = 0.05;

    /**
     * @return array{rates: array<string,float>, source: string|null, compared: bool, deviated: bool}
     */
    public static function fetchDaily(?string $date = null): array
    {
        $day = $date ?? now()->format('Y-m-d');

        $tcmb = static::fetchTcmb();
        $erapi = static::fetchErapi();

        $deviated = false;
        $compared = false;

        if ($tcmb !== null && $erapi !== null) {
            $compared = true;

            foreach (['USD', 'EUR'] as $code) {
                if (isset($tcmb[$code], $erapi[$code]) && $tcmb[$code] > 0) {
                    $sapma = abs($tcmb[$code] - $erapi[$code]) / $tcmb[$code];

                    if ($sapma > static::DEVIATION_THRESHOLD) {
                        $deviated = true;

                        Log::warning('Kur kaynakları ayrıştı', [
                            'currency' => $code,
                            'tcmb' => $tcmb[$code],
                            'erapi' => $erapi[$code],
                            'deviation' => round($sapma, 4),
                        ]);
                    }
                }
            }
        }

        $rates = $tcmb ?? $erapi;
        $source = $tcmb !== null ? static::SOURCE_TCMB : ($erapi !== null ? static::SOURCE_ERAPI : null);

        if ($rates === null) {
            Log::error('Kur çekilemedi: TCMB ve yedek kaynak düştü, son geçerli kur kullanılıyor.');

            return ['rates' => [], 'source' => null, 'compared' => false, 'deviated' => false];
        }

        static::store($rates, $day, $source);

        return ['rates' => $rates, 'source' => $source, 'compared' => $compared, 'deviated' => $deviated];
    }

    /** O günkü kur (yoksa o güne eşit/önceki en yeni; TRY=1; hiç yoksa null). */
    public static function rateFor(string $code, ?string $date = null): ?float
    {
        $code = strtoupper($code);

        if ($code === 'TRY') {
            return 1.0;
        }

        $day = $date ?? now()->format('Y-m-d');

        // whereDate: SQLite'da Eloquent date-cast'i yazarken saat de koyar
        // („2026-10-30 00:00:00"); string „<=" o hâli yakalayamaz.
        $row = ExchangeRate::where('currency_code', $code)
            ->whereDate('rate_date', '<=', $day)
            ->orderByDesc('rate_date')
            ->first();

        return $row === null ? null : round((float) $row->rate, 6);
    }

    /** @return array<string,float>|null 1 döviz = kaç TRY */
    public static function fetchTcmb(): ?array
    {
        try {
            $xml = Http::timeout(20)->get('https://www.tcmb.gov.tr/kurlar/today.xml')->body();

            if ($xml === '') {
                return null;
            }

            $doc = @simplexml_load_string($xml);

            if ($doc === false) {
                return null;
            }

            $rates = [];

            // Spread ([...$doc->Currency]) ilk elemanı yutuyor (SimpleXML
            // yineleyici tuhaflığı) — açık foreach hepsini gezer.
            foreach ($doc->Currency as $currency) {
                $code = strtoupper((string) ($currency['Kod'] ?? ''));
                $buying = (float) ($currency->ForexBuying ?? 0);

                if ($code !== '' && $buying > 0) {
                    $rates[$code] = round($buying, 6);
                }
            }

            return $rates === [] ? null : $rates;
        } catch (\Throwable $e) {
            Log::warning('TCMB kur çekilemedi', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string,float>|null er-api TRY-bazlıdır → TERS ÇEVİRİLİR. */
    public static function fetchErapi(): ?array
    {
        try {
            $res = Http::timeout(20)->get('https://open.er-api.com/v6/latest/TRY')->json();

            if (! is_array($res) || ($res['result'] ?? null) !== 'success' || ! is_array($res['rates'] ?? null)) {
                return null;
            }

            $rates = [];

            foreach ($res['rates'] as $code => $perTry) {
                $perTry = (float) $perTry;

                if ($perTry > 0) {
                    $rates[strtoupper((string) $code)] = round(1 / $perTry, 6);
                }
            }

            return $rates === [] ? null : $rates;
        } catch (\Throwable $e) {
            Log::warning('Yedek kur kaynağı çekilemedi', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Çekilen kurları güne işler (varsa günceller — aynı gün iki koşu çift satır doğurmaz). */
    public static function store(array $rates, string $day, string $source): void
    {
        $codes = Currency::activeCodes();

        foreach ($rates as $code => $rate) {
            $code = strtoupper((string) $code);

            if ($code === 'TRY' || ! in_array($code, $codes, true) || $rate <= 0) {
                continue;
            }

            ExchangeRate::updateOrCreate(
                ['currency_code' => $code, 'rate_date' => $day],
                ['rate' => round((float) $rate, 6), 'source' => $source]
            );
        }
    }
}
