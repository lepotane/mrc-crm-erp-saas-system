<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Services\LabelRenderer;
use App\Services\ProductBarcode;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ürün barkod üretimi + etiket PDF'i .
 *
 * - `GET /products/{product}/barcode?format=png|svg&type=ean13|code128` —
 *   barkod görselini dosya olarak döndürür (JSON değil). EAN-13 istenen kod
 *   12 haneyse checksum sunucuda eklenir, 13 haneyse doğrulanır.
 * - `POST /products/{product}/barcode/assign` — barkodsuz ürüne mağaza-içi
 *   EAN-13 atar (`24` öneki + ürün id + checksum; deterministik).
 * - `POST /products/labels` — `{items: [{product_id, qty}], show_price?, size?}` →
 *   etiket PDF'i (rulo; sayfa başına 1 etiket; ölçü: 38×25 varsayılan, 40×30, 50×30).
 */
class BarcodeController extends Controller
{
    use Concerns\ResolvesTenant;

    /** Etiket ölçü presetleri (mm): [en, boy]. */
    public const LABEL_SIZES = [
        '38x25' => [38, 25],
        '40x30' => [40, 30],
        '50x30' => [50, 30],
    ];

    /**
     * Barkod görseli (dosya döner; hata JSON).
     */
    public function show(Request $request, Product $product)
    {
        abort_if($product->company_id !== $this->tenantId($request), 404, __('Ürün bulunamadı.'));

        $data = $request->validate([
            'format' => ['nullable', 'string', Rule::in(['png', 'svg'])],
            'type' => ['nullable', 'string', Rule::in(ProductBarcode::TYPES)],
        ]);

        $format = $data['format'] ?? 'png';
        $type = $data['type'] ?? 'ean13';

        try {
            $rendered = ProductBarcode::render((string) ($product->barcode ?: $product->sku ?: ''), $type, $format);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['barcode' => [$e->getMessage()]]);
        }

        return response($rendered['content'], 200, [
            'Content-Type' => $rendered['mime'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Barkodsuz ürüne mağaza-içi EAN-13 ata.
     *
     * @response array{message: string, product: array{id: int, barcode: string}}
     */
    public function assign(Request $request, Product $product)
    {
        abort_if($product->company_id !== $this->tenantId($request), 404, __('Ürün bulunamadı.'));

        $data = $request->validate([
            'prefix' => ['nullable', 'string', 'regex:/^\d{2,6}$/'],
            'force' => ['nullable', 'boolean'],
        ]);

        if ($product->barcode && empty($data['force'])) {
            throw ValidationException::withMessages([
                'barcode' => [__('Üründe zaten barkod var.')],
            ]);
        }

        try {
            $code = ProductBarcode::instoreEan13($product->id, $data['prefix'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['barcode' => [$e->getMessage()]]);
        }

        $product->update(['barcode' => $code]);

        Audit::fromRequest($request, 'product.updated', (int) $product->company_id, $product, [
            'changed' => ['barcode' => $code],
        ]);

        return response()->json([
            'barcode' => $code,
            'product' => ['id' => $product->id, 'barcode' => $code],
            'message' => __('Barkod atandı.'),
        ]);
    }

    /**
     * Etiket PDF'i (rulo; adet × ürün sayfası). Ölçü presetleri: 38×25 (varsayılan), 40×30, 50×30.
     */
    public function labels(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:200'],
            'show_price' => ['sometimes', 'boolean'],
            'size' => ['sometimes', 'string', Rule::in(['38x25', '40x30', '50x30'])],
            'barcode_type' => ['sometimes', 'string', Rule::in(['ean13', 'code128', 'qr'])],
            'lang' => ['sometimes', 'string', Rule::in(['tr', 'en'])],
        ]);

        $lang = $data['lang'] ?? app()->getLocale();
        if (app()->getLocale() !== $lang) {
            app()->setLocale($lang);
        }

        $products = Product::where('company_id', $companyId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->unique()->all())
            ->get()->keyBy('id');

        $labels = [];
        $kompozit = [];

        // Karar 48: varsayılan etiket şablonu + GD varsa alanlar TEK resme
        // basılır (sürükle-bırak yerleşim birebir çıkar). Yoksa yerleşik akış.
        $sablon = PrintTemplate::defaultFor($companyId, 'label');
        $duzen = ($sablon && LabelRenderer::available() && ! empty($sablon->layout['fields']))
            ? $sablon->layout
            : null;
        // Şablon aktifse ölçü + dizim şablondan gelir (tasarım o ölçüye yapılmıştır).
        $olcuAdi = $duzen ? $duzen['size'] : ($data['size'] ?? '38x25');
        $boyut = self::LABEL_SIZES[$olcuAdi];
        $gosterFiyat = $duzen
            ? ($duzen['showPrice'] && ($data['show_price'] ?? true))
            : (bool) ($data['show_price'] ?? true);

        // Şablonsuz akışın barkod doğal yüksekliği: dompdf img'de CSS boyunu
        // uygular ama yüksekliği doğal px'ten türetir (60px ~55.6pt → 0.9267).
        $pxH = $olcuAdi === '38x25' ? 26 : 34;

        foreach ($data['items'] as $row) {
            $product = $products->get($row['product_id']);

            if ($product === null) {
                continue;
            }

            $code = (string) ($product->barcode ?: $product->sku ?: '');

            if ($code === '') {
                throw ValidationException::withMessages([
                    'items' => [__('Barkodsuz ürün etikete giremez: :name', ['name' => $product->name])],
                ]);
            }

            if ($duzen) {
                $pid = $product->id;
                if (! isset($kompozit[$pid])) {
                    $kompozit[$pid] = 'data:image/png;base64,' . base64_encode(LabelRenderer::compose(
                        $boyut,
                        $duzen['fields'],
                        [
                            'name' => $product->localizedName($lang),
                            'code' => (string) ($product->barcode ?: $product->sku ?: ''),
                            'price' => (float) ($product->sale_price ?? 0),
                            'barcode' => $code,
                            'showPrice' => $gosterFiyat,
                            'lang' => $lang,
                        ]
                    ));
                }
                $resim = $kompozit[$pid];
            } else {
                try {
                    $labelBarcodeType = ($data['barcode_type'] ?? 'code128') === 'qr' ? 'qr' : 'code128';
                    $rendered = ProductBarcode::render($code, $labelBarcodeType, 'png', 1, $pxH);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
                }
                $resim = 'data:image/png;base64,' . base64_encode($rendered['content']);
            }

            for ($i = 0; $i < (int) $row['qty']; $i++) {
                $labels[] = [
                    'name' => $product->localizedName($lang),
                    'code' => $code,
                    'image' => $resim,
                    'price' => (float) ($product->sale_price ?? 0),
                    'composed' => $duzen !== null,
                ];
            }
        }

        if ($labels === []) {
            abort(404, __('Ürün bulunamadı.'));
        }

        $pdf = Pdf::loadView('pdf.labels', [
            'labels' => $labels,
            'showPrice' => $gosterFiyat,
            'size' => $boyut,
            'dims' => self::LABEL_SIZES[$olcuAdi],
            'money' => static fn ($v): string => app()->getLocale() === 'tr'
                ? number_format((float) $v, 2, ',', '.')
                : number_format((float) $v, 2, '.', ','),
        ]);

        [$en, $boy] = self::LABEL_SIZES[$olcuAdi];

        // Rulo etiket (pt: mm × 2.83465).
        $pdf->setPaper([0, 0, $en * 2.83465, $boy * 2.83465]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="etiketler.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
