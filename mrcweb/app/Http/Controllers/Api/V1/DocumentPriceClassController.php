<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Product;
use App\Support\ExchangeRates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * BELGE FİYAT SINIFI DEĞİŞTİRME.
 *
 * "Ürünlerin fiyatları teklif/fiş/fatura oluştururken hangi fiyatlardan
 * oluşturulacağı seçilebilecek; aynı belgenin çıktısı alınırken kalem bazlı
 * ya da toplu olarak fiyat sınıfları yine değiştirilebilecek."
 *
 * Burada:
 *   - `currency_code` = hedef fiyat sınıfı (para birimi).
 *   - `item_ids` verilirse yalnız o kalemler, verilmezse TÜM kalemler değişir.
 *
 * Belgenin para birimi DEĞİŞMEZ; kalemin birim fiyatı hedef sınıfın fiyatına
 * (bulunamazsa kurla çevrilerek) güncellenir → toplamlar belgenin para biriminde
 * kalır, yazdırılan belge tutarlı olur.
 */
class DocumentPriceClassController extends Controller
{
    use ResolvesTenant;

    /**
     * Kalemlerin fiyat sınıfını değiştirir (toplu veya kalem bazlı).
     *
     * @response array{message: string, updated: int, items: array<int, array{id: int, unit_price: string, price_currency_code: string|null}>}
     */
    public function update(Request $request, Document $document)
    {
        abort_if(
            $document->company_id !== $this->tenantId($request),
            404,
            __('Belge bulunamadı.')
        );

        // Yalnız taslak/iptal belgelerde fiyat değiştirilebilir (kesilmiş belge
        // sözleşmesi bozulur — aynı kural update() içinde de geçerli).
        abort_if(
            ! in_array($document->status, ['draft', 'cancelled'], true),
            422,
            __('Yalnız taslak veya iptal edilmiş belgede fiyat sınıfı değiştirilebilir.')
        );

        $data = $request->validate([
            'currency_code' => ['required', 'string', Rule::in(Currency::activeCodes())],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);

        $hedefCcy = strtoupper($data['currency_code']);

        $items = $document->items()
            ->when(isset($data['item_ids']) && $data['item_ids'] !== [],
                fn ($q) => $q->whereIn('id', $data['item_ids']))
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => __('Seçili kalem bulunamadı.')], 422);
        }

        // Kur fotoğrafı: belgenin kur tarihi (belge para birimi için exchange_rate
        // zaten kayıtlı; diğer para birimleri için tarih bazlı kur gerekir).
        $tarih = $document->rate_date?->format('Y-m-d') ?? $document->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        $belgeKuru = (float) ($document->exchange_rate ?: 1);
        $hedefKuru = $this->kur($hedefCcy, $tarih);

        if ($hedefKuru === null) {
            return response()->json([
                'message' => __('Hedef para biriminin kuru bulunamadı; fiyat sınıfı değiştirilemedi.'),
            ], 422);
        }

        $products = Product::whereIn('id', $items->pluck('product_id')->filter()->unique())
            ->with('prices')
            ->get()
            ->keyBy('id');

        $guncellenen = [];

        DB::transaction(function () use ($items, $products, $hedefCcy, $hedefKuru, $document, &$guncellenen) {
            foreach ($items as $item) {
                $yeniFiyat = null;

                $product = $item->product_id ? $products->get($item->product_id) : null;

                if ($product !== null) {
                    $yeniFiyat = $product->salePriceIn(
                        $hedefCcy,
                        $this->kur(strtoupper((string) ($product->currency_code ?: 'TRY')), null),
                        $hedefKuru
                    );
                }

                // Ürünün o sınıfta fiyatı yoksa kurla çevir (bulunamazsa mevcut fiyat).
                $unitPrice = $yeniFiyat !== null ? (float) $yeniFiyat : (float) $item->unit_price;

                $lineSubtotal = round((float) $item->quantity * $unitPrice, 4);
                $lineDiscount = round($lineSubtotal * (float) $item->discount_rate / 100, 4);
                $lineTax = round(($lineSubtotal - $lineDiscount) * (float) $item->tax_rate / 100, 4);
                $lineTotal = round($lineSubtotal - $lineDiscount + $lineTax, 4);

                $item->forceFill([
                    'unit_price' => $unitPrice,
                    'price_currency_code' => $hedefCcy,
                    'line_subtotal' => $lineSubtotal,
                    'line_discount' => $lineDiscount,
                    'line_tax' => $lineTax,
                    'line_total' => $lineTotal,
                ])->save();

                $guncellenen[] = [
                    'id' => $item->id,
                    'unit_price' => number_format($unitPrice, 4, '.', ''),
                    'price_currency_code' => $hedefCcy,
                ];
            }

            $this->toplamlariYenidenHesapla($document->id);
        });

        return response()->json([
            'message' => __('Fiyat sınıfı güncellendi.'),
            'updated' => count($guncellenen),
            'items' => $guncellenen,
        ]);
    }

    /** Belge toplamlarını kalemlerden yeniden hesaplar. */
    protected function toplamlariYenidenHesapla(int $documentId): void
    {
        $document = Document::find($documentId);

        if ($document === null) {
            return;
        }

        $totals = DocumentItem::where('document_id', $documentId)
            ->selectRaw('COALESCE(SUM(line_subtotal),0) AS s, COALESCE(SUM(line_discount),0) AS d, COALESCE(SUM(line_tax),0) AS t')
            ->first();

        $document->forceFill([
            'subtotal' => round((float) $totals->s, 4),
            'discount_total' => round((float) $totals->d, 4),
            'tax_total' => round((float) $totals->t, 4),
            'grand_total' => round((float) $totals->s - (float) $totals->d + (float) $totals->t, 4),
        ])->save();
    }

    /** Kur (TRY'ye göre). TRY = 1. */
    protected function kur(string $currency, ?string $date): ?float
    {
        if ($currency === 'TRY') {
            return 1.0;
        }

        $kur = ExchangeRates::rateFor($currency, $date);

        return $kur !== null && $kur > 0 ? (float) $kur : null;
    }
}
