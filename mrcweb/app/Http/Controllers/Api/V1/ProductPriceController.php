<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\ExchangeRates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ÜRÜN FİYAT SINIFLARI (para birimi bazlı fiyat listesi).
 *
 * Bir ürünün TL / USD / EUR fiyatı FARKLI olabilir. Bu uçlar o listeyi yönetir:
 *   PUT    /products/{product}/prices            → fiyat satırlarını yaz (upsert)
 *   DELETE /products/{product}/prices/{currency} → fiyat sınıfını sil
 *   POST   /products/{product}/prices/sync       → kur bazlı eşitleme
 *
 * "Kur bazlı eşitleme": TL fiyatı ile USD fiyatı zorunlu olarak eşit değildir;
 * istenirse biri diğerine KURLA çevrilerek eşitlenebilir (kullanıcı kararı).
 */
class ProductPriceController extends Controller
{
    use ResolvesTenant;

    /**
     * Fiyat satırlarını yaz (upsert) — tek çağrıda tüm listeyi göndermek da mümkün.
     *
     * @response array{prices: array<int, array{currency_code: string, sale_price: string, cost_price: string|null}>}
     */
    public function update(Request $request, Product $product)
    {
        $this->tenantdenVarMi($request, $product);

        $data = $request->validate([
            'prices' => ['required', 'array', 'max:20'],
            'prices.*.currency_code' => ['required', 'string', Rule::in(Currency::activeCodes())],
            'prices.*.sale_price' => ['required', 'numeric', 'min:0'],
            'prices.*.cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data['prices'] as $satir) {
            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'currency_code' => strtoupper($satir['currency_code']),
                ],
                [
                    'company_id' => $product->company_id,
                    'sale_price' => $satir['sale_price'],
                    'cost_price' => $satir['cost_price'] ?? null,
                ]
            );
        }

        // Ana fiyat (ürnün kendi currency_code'su) listede de tutulur → raporlar
        // ve dışa aktarım aynı değeri görür.
        $anaCcy = strtoupper((string) ($product->currency_code ?: 'TRY'));
        foreach ($data['prices'] as $satir) {
            if (strtoupper($satir['currency_code']) === $anaCcy) {
                $product->update([
                    'sale_price' => $satir['sale_price'],
                    'cost_price' => $satir['cost_price'] ?? $product->cost_price,
                ]);
            }
        }

        $product->load('prices');

        return response()->json([
            'message' => __('Fiyat listesi kaydedildi.'),
            'prices' => $this->liste($product),
        ]);
    }

    /**
     * Fiyat sınıfını sil (ürnün ana fiyatı silinemez).
     */
    public function destroy(Request $request, Product $product, string $currency)
    {
        $this->tenantdenVarMi($request, $product);

        $ccy = strtoupper($currency);

        if ($ccy === strtoupper((string) ($product->currency_code ?: 'TRY'))) {
            return response()->json([
                'message' => __('Ürünün ana fiyatı silinemez.'),
            ], 422);
        }

        ProductPrice::where('product_id', $product->id)
            ->where('currency_code', $ccy)
            ->delete();

        return response()->json([
            'message' => __('Fiyat sınıfı silindi.'),
            'prices' => $this->liste($product->fresh()->load('prices')),
        ]);
    }

    /**
     * KUR BAZLI EŞİTLEME: kaynak fiyat sınıfı, kurla hedef sınıfa çevrilerek
     * yazılır. "TL ve USD fiyatı eşit olmak zorunda değil; istenirse eşitlenir."
     *
     * @response array{message: string, prices: array<int, array{currency_code: string, sale_price: string, cost_price: string|null}>}
     */
    public function sync(Request $request, Product $product)
    {
        $this->tenantdenVarMi($request, $product);

        $data = $request->validate([
            'from_currency' => ['required', 'string', Rule::in(Currency::activeCodes())],
            'to_currency' => ['required', 'string', Rule::in(Currency::activeCodes())],
            'date' => ['nullable', 'date'],
        ]);

        $kaynakCcy = strtoupper($data['from_currency']);
        $hedefCcy = strtoupper($data['to_currency']);

        if ($kaynakCcy === $hedefCcy) {
            return response()->json([
                'message' => __('Kaynak ve hedef para birimi aynı olamaz.'),
            ], 422);
        }

        $tarih = $data['date'] ?? now()->format('Y-m-d');

        $kaynakFiyat = $product->prices->firstWhere('currency_code', $kaynakCcy);

        if ($kaynakFiyat === null) {
            return response()->json([
                'message' => __('Kaynak fiyat sınıfı bulunamadı.'),
            ], 422);
        }

        $kaynakKur = $this->kur($kaynakCcy, $tarih);
        $hedefKur = $this->kur($hedefCcy, $tarih);

        if ($kaynakKur === null || $hedefKur === null) {
            return response()->json([
                'message' => __('Kur bulunamadı; eşitleme yapılamadı. Önce kurları güncelleyin.'),
            ], 422);
        }

        $carpan = $kaynakKur / $hedefKur;

        $yeniSatis = round(((float) $kaynakFiyat->sale_price) * $carpan, 4);
        $yeniMaliyet = $kaynakFiyat->cost_price === null
            ? null
            : round(((float) $kaynakFiyat->cost_price) * $carpan, 4);

        ProductPrice::updateOrCreate(
            [
                'product_id' => $product->id,
                'currency_code' => $hedefCcy,
            ],
            [
                'company_id' => $product->company_id,
                'sale_price' => $yeniSatis,
                'cost_price' => $yeniMaliyet,
            ]
        );

        if ($hedefCcy === strtoupper((string) ($product->currency_code ?: 'TRY'))) {
            $product->update(['sale_price' => $yeniSatis, 'cost_price' => $yeniMaliyet ?? $product->cost_price]);
        }

        return response()->json([
            'message' => __('Fiyat sınıfı kur bazlı eşitlendi.'),
            'prices' => $this->liste($product->fresh()->load('prices')),
        ]);
    }

    /** Kur (TRY'ye göre). TRY = 1. */
    protected function kur(string $currency, string $date): ?float
    {
        if ($currency === 'TRY') {
            return 1.0;
        }

        $kur = ExchangeRates::rateFor($currency, $date);

        return $kur !== null && $kur > 0 ? (float) $kur : null;
    }

    /** @return array<int, array{currency_code: string, sale_price: string, cost_price: string|null}> */
    protected function liste(Product $product): array
    {
        return $product->prices
            ->sortBy('currency_code')
            ->values()
            ->map(fn (ProductPrice $p) => [
                'currency_code' => $p->currency_code,
                'sale_price' => (string) $p->sale_price,
                'cost_price' => $p->cost_price === null ? null : (string) $p->cost_price,
            ])->all();
    }

    /** Kiracı kapısı: başka firmanın ürünü 404'tür (sızıntı yok). */
    protected function tenantdenVarMi(Request $request, Product $product): void
    {
        abort_if(
            $product->company_id !== $this->tenantId($request),
            404,
            __('Ürün bulunamadı.')
        );
    }
}
