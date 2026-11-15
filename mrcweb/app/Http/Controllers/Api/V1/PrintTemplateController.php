<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Services\LabelRenderer;
use App\Services\ProductBarcode;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Yazdırma şablonları : fatura / teklif / sipariş / alım / fiş /
 * irsaliye / etiket çıktılarının görünümü firma başına özelleştirilir.
 *
 * - Tür başına birden çok şablon durabilir; biri varsayılan (`is_default`).
 * - Kayıtlı şablon yoksa yerleşik tasarım basılır (sessiz düşüş).
 * - `preview`: kaydetmeden örnek veriyle PDF üretir (tasarımcı canlı önizleme).
 * - Şema v1 `validateLayout` içinde kilitlidir; bilinmeyen alan reddedilir (422).
 */
class PrintTemplateController extends Controller
{
    use ResolvesTenant;

    /** Tür → tasarımcı blok anahtarları (sıra tasarımcıda sürükle-bırak ile değişir). */
    public const BLOCKS = [
        'invoice' => ['parti', 'kalemler', 'toplam', 'notlar'],
        'quote' => ['parti', 'kalemler', 'toplam', 'notlar'],
        'sales_order' => ['parti', 'kalemler', 'toplam', 'notlar'],
        'purchase' => ['parti', 'kalemler', 'toplam', 'notlar'],
        'delivery' => ['parti', 'kalemler', 'imza', 'notlar'],
        'receipt' => ['kalemler', 'toplam', 'odeme', 'altbilgi'],
    ];

    public const LABEL_FIELDS = ['name', 'barcode', 'qr', 'code', 'price'];

    public const HEADER_COLORS = ['#1e3a5f', '#0f766e', '#7c2d12', '#3f3f46', '#4f46e5', '#131722', '#b9ff66'];

    /** Şablon listesi (türe göre süzülebilir). */
    public function index(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'kind' => ['sometimes', 'string', Rule::in(PrintTemplate::KINDS)],
        ]);

        $q = PrintTemplate::where('company_id', $companyId)->orderBy('kind')->orderBy('id');
        if (isset($data['kind'])) {
            $q->where('kind', $data['kind']);
        }

        return response()->json([
            'data' => $q->get()->map(fn (PrintTemplate $t) => $this->present($t))->values(),
        ]);
    }

    /** Yeni yazdırma şablonu (türün ilki varsayılan olur). */
    public function store(Request $request)
    {
        $companyId = $this->tenantId($request);

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(PrintTemplate::KINDS)],
            'name' => ['required', 'string', 'max:80'],
            'layout' => ['required', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $layout = $this->validateLayout($data['kind'], $data['layout']);

        $tpl = DB::transaction(function () use ($companyId, $data, $layout) {
            $ilk = ! PrintTemplate::where('company_id', $companyId)->where('kind', $data['kind'])->exists();
            $varsayilan = $ilk || ($data['is_default'] ?? false);
            if ($varsayilan) {
                PrintTemplate::where('company_id', $companyId)->where('kind', $data['kind'])
                    ->update(['is_default' => false]);
            }

            return PrintTemplate::create([
                'company_id' => $companyId,
                'kind' => $data['kind'],
                'name' => $data['name'],
                'is_default' => $varsayilan,
                'layout' => $layout,
            ]);
        });

        Audit::fromRequest($request, 'print_template.created', $companyId, $tpl, [
            'kind' => $tpl->kind,
            'name' => $tpl->name,
        ]);

        return response()->json(['data' => $this->present($tpl)], 201);
    }

    /** Tek şablon detayı. */
    public function show(Request $request, PrintTemplate $template)
    {
        $this->authorizeTemplate($request, $template);

        return response()->json(['data' => $this->present($template)]);
    }

    /** Şablonu güncelle (ad, yerleşim, varsayılanlık). */
    public function update(Request $request, PrintTemplate $template)
    {
        $companyId = $this->tenantId($request);
        $this->authorizeTemplate($request, $template);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'layout' => ['sometimes', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('layout', $data)) {
            $data['layout'] = $this->validateLayout($template->kind, $data['layout']);
        }

        DB::transaction(function () use ($companyId, $template, $data) {
            if (($data['is_default'] ?? false) === true) {
                PrintTemplate::where('company_id', $companyId)->where('kind', $template->kind)
                    ->update(['is_default' => false]);
                $data['is_default'] = true;
            }
            $template->update($data);

            // Türün varsayılanı kalmadıysa (kapatıldıysa) en eski şablon devralır.
            if (! PrintTemplate::where('company_id', $companyId)->where('kind', $template->kind)
                ->where('is_default', true)->exists()) {
                PrintTemplate::where('company_id', $companyId)->where('kind', $template->kind)
                    ->orderBy('id')->limit(1)->update(['is_default' => true]);
            }
        });

        Audit::fromRequest($request, 'print_template.updated', $companyId, $template->fresh(), [
            'kind' => $template->kind,
            'name' => $template->name,
        ]);

        return response()->json(['data' => $this->present($template->fresh())]);
    }

    /** Şablonu sil (varsayılansa türün en eskisi devralır). */
    public function destroy(Request $request, PrintTemplate $template)
    {
        $companyId = $this->tenantId($request);
        $this->authorizeTemplate($request, $template);

        DB::transaction(function () use ($companyId, $template) {
            $kind = $template->kind;
            $varsayilandi = $template->is_default;
            $template->delete();

            if ($varsayilandi) {
                PrintTemplate::where('company_id', $companyId)->where('kind', $kind)
                    ->orderBy('id')->limit(1)->update(['is_default' => true]);
            }
        });

        Audit::fromRequest($request, 'print_template.deleted', $companyId, null, [
            'kind' => $template->kind,
            'name' => $template->name,
        ]);

        return response()->json(['message' => __('Şablon silindi.')]);
    }

    /** Türün varsayılan şablonu yap. */
    public function setDefault(Request $request, PrintTemplate $template)
    {
        $companyId = $this->tenantId($request);
        $this->authorizeTemplate($request, $template);

        DB::transaction(function () use ($companyId, $template) {
            PrintTemplate::where('company_id', $companyId)->where('kind', $template->kind)
                ->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });

        Audit::fromRequest($request, 'print_template.default', $companyId, $template->fresh(), [
            'kind' => $template->kind,
            'name' => $template->name,
        ]);

        return response()->json(['data' => $this->present($template->fresh())]);
    }

    /**
     * Canlı önizleme: kaydedilmemiş `layout` + örnek veriyle PDF üretir.
     * Kayıt oluşturmaz, plan limitine sayılmaz.
     *
     * Etikette `product_id` verilirse o ürünün gerçek adıyla basılır (uzun ad
     * taşmaları tasarımda yakalansın diye); verilmezse örnek veri kullanılır.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(PrintTemplate::KINDS)],
            'layout' => ['required', 'array'],
            'lang' => ['sometimes', 'string', Rule::in(['tr', 'en'])],
            'product_id' => ['sometimes', 'integer',
                Rule::exists('products', 'id')->where('company_id', $this->tenantId($request))],
            'document_id' => ['sometimes', 'integer',
                Rule::exists('documents', 'id')->where('company_id', $this->tenantId($request))],
        ]);
        $layout = $this->validateLayout($data['kind'], $data['layout']);

        $lang = $data['lang'] ?? app()->getLocale();
        $onceki = app()->getLocale();
        app()->setLocale($lang);

        try {
            if ($data['kind'] === 'label') {
                $pdf = Pdf::loadView('pdf.labels', $this->sampleLabel($data['kind'], $layout, $lang, $data['product_id'] ?? null));
                [$en, $boy] = BarcodeController::LABEL_SIZES[$layout['size']];
                $pdf->setPaper([0, 0, $en * 2.83465, $boy * 2.83465]);
            } else {
                $gorunum = $data['kind'] === 'delivery' ? 'pdf.delivery'
                    : ($data['kind'] === 'receipt' ? 'pdf.receipt' : 'pdf.document');
                $pdf = Pdf::loadView($gorunum, $this->samplePayload($data['kind'], $layout, $lang, $data['document_id'] ?? null));
                if ($data['kind'] === 'receipt') {
                    $pdf->setPaper([0, 0, 226.8, 1000]);
                } else {
                    $pdf->setPaper('a4');
                }
            }

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
            ]);
        } finally {
            app()->setLocale($onceki);
        }
    }

    /**
     * Görsel tasarımcı tuvali: aynı örnek veriyle HAM HTML döndürür (srcdoc).
     * Yalnız belge türleri (label bestesi PNG'dir, tuvali ayrıdır).
     */
    #[\Dedoc\Scramble\Attributes\Response(status: 200, description: 'Örnek verili HTML (JSON içinde).')]
    public function previewHtml(Request $request)
    {
        $companyId = $this->tenantId($request);
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(PrintTemplate::DOC_KINDS)],
            'layout' => ['required', 'array'],
            'lang' => ['sometimes', 'string', Rule::in(['tr', 'en'])],
            'document_id' => ['sometimes', 'integer',
                Rule::exists('documents', 'id')->where('company_id', $companyId)],
        ]);
        $layout = $this->validateLayout($data['kind'], $data['layout']);

        $lang = $data['lang'] ?? app()->getLocale();
        $onceki = app()->getLocale();
        app()->setLocale($lang);

        try {
            $gorunum = $data['kind'] === 'delivery' ? 'pdf.delivery'
                : ($data['kind'] === 'receipt' ? 'pdf.receipt' : 'pdf.document');
            $html = view($gorunum, $this->samplePayload($data['kind'], $layout, $lang, $data['document_id'] ?? null))->render();

            return response()->json(['html' => $html]);
        } finally {
            app()->setLocale($onceki);
        }
    }

    // -- iç yardımcılar ----------------------------------------------------

    protected function authorizeTemplate(Request $request, PrintTemplate $template): void
    {
        abort_if(
            $template->company_id !== $this->tenantId($request),
            404,
            __('Şablon bulunamadı.')
        );
    }

    protected function present(PrintTemplate $t): array
    {
        return [
            'id' => $t->id,
            'kind' => $t->kind,
            'name' => $t->name,
            'is_default' => (bool) $t->is_default,
            'layout' => $t->layout,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ];
    }

    /**
     * Şema v1: bilinmeyen anahtar ya da aralık dışı değer 422 ile reddedilir.
     * Tasarımcı eski sürüm gönderirse (`version` ≠ 1) de reddedilir — sessiz
     * bozulmuş çıktıdan iyidir.
     */
    protected function validateLayout(string $kind, array $layout): array
    {
        if (($layout['version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['layout.version' => [__('Desteklenmeyen şablon sürümü.')]]);
        }

        if ($kind === 'label') {
            return $this->validateLabelLayout($layout);
        }

        $bloklar = self::BLOCKS[$kind] ?? null;
        if ($bloklar === null) {
            throw ValidationException::withMessages(['kind' => [__('Bu türde şablon desteklenmiyor.')]]);
        }

        $gelen = $layout['blocks'] ?? null;
        if (! is_array($gelen) || $gelen === []) {
            throw ValidationException::withMessages(['layout.blocks' => [__('En az bir blok gereklidir.')]]);
        }

        $gorulen = [];
        $temiz = [];
        foreach ($gelen as $i => $b) {
            $anahtar = $b['key'] ?? null;
            $ozel = $anahtar === 'ozel';
            if (! is_array($b) || (! $ozel && ! in_array($anahtar, $bloklar, true))) {
                throw ValidationException::withMessages(["layout.blocks.{$i}.key" => [__('Bilinmeyen blok.')]]);
            }
            // 'ozel' serbest öğelerdir: metin (yazı), cizgi (ayraç), bosluk (boşluk).
            // Birden çok olabilir; tipe göre ek alanları doğrulanır.
            if ($ozel) {
                $tip = $b['tip'] ?? 'metin';
                if (! in_array($tip, ['metin', 'cizgi', 'bosluk'], true)) {
                    throw ValidationException::withMessages(["layout.blocks.{$i}.tip" => [__('Bilinmeyen özel blok tipi.')]]);
                }
                $oge = ['key' => 'ozel', 'tip' => $tip,
                    'visible' => array_key_exists('visible', $b) ? (bool) $b['visible'] : true];
                if ($tip === 'metin') {
                    $metin = $b['text'] ?? null;
                    if (! is_string($metin) || trim($metin) === '' || mb_strlen($metin) > 500) {
                        throw ValidationException::withMessages(["layout.blocks.{$i}.text" => [__('Metin bloğu 1-500 karakter olmalı.')]]);
                    }
                    $oge['text'] = trim($metin);
                }
                if ($tip === 'bosluk') {
                    $yuk = $b['yukseklik'] ?? 8;
                    if (! is_numeric($yuk) || $yuk < 2 || $yuk > 30) {
                        throw ValidationException::withMessages(["layout.blocks.{$i}.yukseklik" => [__('Boşluk 2-30 mm olmalı.')]]);
                    }
                    $oge['yukseklik'] = (float) $yuk;
                }
                $temiz[] = $oge;
                continue;
            }
            if (in_array($anahtar, $gorulen, true)) {
                throw ValidationException::withMessages(["layout.blocks.{$i}.key" => [__('Blok yinelenemez.')]]);
            }
            $gorulen[] = $anahtar;
            $temiz[] = ['key' => $anahtar, 'visible' => array_key_exists('visible', $b) ? (bool) $b['visible'] : true];
        }

        // Eksik bloklar görünür varsayılanla sona eklenir (eski kayıt + yeni blok uyumu).
        foreach ($bloklar as $k) {
            if (! in_array($k, $gorulen, true)) {
                $temiz[] = ['key' => $k, 'visible' => true];
            }
        }

        $stil = $layout['style'] ?? [];
        if (! is_array($stil)) {
            throw ValidationException::withMessages(['layout.style' => [__('Geçersiz stil.')]]);
        }
        $logoPos = $stil['logoPos'] ?? 'left';
        $renk = $stil['headerColor'] ?? '#1e3a5f';
        $altbilgi = $stil['footerText'] ?? null;
        $fontOlcek = $stil['fontScale'] ?? 'normal';
        $logoGoster = array_key_exists('logoVisible', $stil) ? (bool) $stil['logoVisible'] : true;
        if (! in_array($logoPos, ['left', 'right'], true)) {
            throw ValidationException::withMessages(['layout.style.logoPos' => [__('Geçersiz logo konumu.')]]);
        }
        if (! in_array($renk, self::HEADER_COLORS, true) && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $renk)) {
            throw ValidationException::withMessages(['layout.style.headerColor' => [__('Geçersiz başlık rengi.')]]);
        }
        if (! in_array($fontOlcek, PrintTemplate::FONT_SCALES, true)) {
            throw ValidationException::withMessages(['layout.style.fontScale' => [__('Geçersiz yazı boyu.')]]);
        }
        if ($altbilgi !== null && (! is_string($altbilgi) || mb_strlen($altbilgi) > 140)) {
            throw ValidationException::withMessages(['layout.style.footerText' => [__('Alt bilgi en çok 140 karakter olabilir.')]]);
        }

        // Sütun görünürlüğü (bilinmeyen anahtar reddedilir; verilmeyen açık sayılır).
        $sutunlar = [];
        $gelenSutun = $stil['columns'] ?? [];
        if (! is_array($gelenSutun)) {
            throw ValidationException::withMessages(['layout.style.columns' => [__('Geçersiz sütun ayarı.')]]);
        }
        foreach ($gelenSutun as $k => $v) {
            if (! in_array($k, PrintTemplate::COLUMNS, true)) {
                throw ValidationException::withMessages(["layout.style.columns.{$k}" => [__('Bilinmeyen sütun.')]]);
            }
            $sutunlar[$k] = (bool) $v;
        }
        foreach (PrintTemplate::COLUMNS as $k) {
            $sutunlar[$k] = $sutunlar[$k] ?? true;
        }

        // Toplam satırları (genel toplam her zaman basılır, listede yeri yok).
        $toplamlar = [];
        $gelenToplam = $stil['totals'] ?? [];
        if (! is_array($gelenToplam)) {
            throw ValidationException::withMessages(['layout.style.totals' => [__('Geçersiz toplam ayarı.')]]);
        }
        foreach ($gelenToplam as $k => $v) {
            if (! in_array($k, PrintTemplate::TOTALS, true)) {
                throw ValidationException::withMessages(["layout.style.totals.{$k}" => [__('Bilinmeyen toplam satırı.')]]);
            }
            $toplamlar[$k] = (bool) $v;
        }
        foreach (PrintTemplate::TOTALS as $k) {
            $toplamlar[$k] = $toplamlar[$k] ?? true;
        }

        return [
            'version' => 1,
            'blocks' => $temiz,
            'style' => [
                'logoPos' => $logoPos, 'headerColor' => $renk, 'footerText' => $altbilgi,
                'fontScale' => $fontOlcek, 'logoVisible' => $logoGoster,
                'columns' => $sutunlar, 'totals' => $toplamlar,
            ],
        ];
    }

    protected function validateLabelLayout(array $layout): array
    {
        $size = $layout['size'] ?? null;
        if (! is_string($size) || ! array_key_exists($size, BarcodeController::LABEL_SIZES)) {
            throw ValidationException::withMessages(['layout.size' => [__('Geçersiz etiket ölçüsü.')]]);
        }

        $alanlar = $layout['fields'] ?? null;
        if (! is_array($alanlar) || $alanlar === []) {
            throw ValidationException::withMessages(['layout.fields' => [__('En az bir alan gereklidir.')]]);
        }

        $temiz = [];
        $gorunur = 0;
        foreach ($alanlar as $i => $f) {
            if (! is_array($f) || ! in_array($f['key'] ?? null, self::LABEL_FIELDS, true)) {
                throw ValidationException::withMessages(["layout.fields.{$i}.key" => [__('Bilinmeyen alan.')]]);
            }
            foreach (['x', 'y', 'w'] as $k) {
                if (! isset($f[$k]) || ! is_numeric($f[$k]) || $f[$k] < 0 || $f[$k] > 100) {
                    throw ValidationException::withMessages(["layout.fields.{$i}.{$k}" => [__('0-100 arası olmalıdır.')]]);
                }
            }
            $font = (int) ($f['font'] ?? 8);
            if ($font < 6 || $font > 16) {
                throw ValidationException::withMessages(["layout.fields.{$i}.font" => [__('Yazı boyu 6-16 olabilir.')]]);
            }
            $hiza = $f['align'] ?? 'left';
            if (! in_array($hiza, ['left', 'center', 'right'], true)) {
                throw ValidationException::withMessages(["layout.fields.{$i}.align" => [__('Geçersiz hizalama.')]]);
            }
            $acik = array_key_exists('visible', $f) ? (bool) $f['visible'] : true;
            if ($acik) $gorunur++;
            // İsim alanı en çok satır sayısı (1-3); kutuya sığmayan satır atılır.
            $satir = (int) ($f['maxLines'] ?? 2);
            if ($satir < 1 || $satir > 3) {
                throw ValidationException::withMessages(["layout.fields.{$i}.maxLines" => [__('Satır sayısı 1-3 olabilir.')]]);
            }
            $temiz[] = [
                'key' => $f['key'],
                'x' => (float) $f['x'], 'y' => (float) $f['y'], 'w' => (float) $f['w'],
                'h' => isset($f['h']) && is_numeric($f['h']) ? max(0, min(100, (float) $f['h'])) : 0,
                'font' => $font, 'align' => $hiza, 'visible' => $acik, 'maxLines' => $satir,
            ];
        }

        if ($gorunur === 0) {
            throw ValidationException::withMessages(['layout.fields' => [__('En az bir görünür alan gereklidir.')]]);
        }

        return [
            'version' => 1,
            'size' => $size,
            'showPrice' => array_key_exists('showPrice', $layout) ? (bool) $layout['showPrice'] : true,
            'fields' => $temiz,
        ];
    }

    // -- örnek veri (önizleme) ----------------------------------------------

    protected function samplePayload(string $kind, array $layout, string $lang, ?int $documentId = null): array
    {
        // Gerçek belge istendiyse onunla bas (tasarımcıda "gerçek veriyle gör").
        if ($documentId !== null) {
            $doc = Document::with(['items.product.unit', 'party', 'company'])->findOrFail($documentId);
            $cikti = (new DocumentPdfController)->payload(
                $doc,
                $kind === 'delivery' ? 'delivery' : ($kind === 'receipt' ? 'receipt' : null)
            );
            $cikti['tpl'] = PrintTemplate::normalizeForOutput($layout);
            if ($kind === 'receipt') {
                $odeme = Payment::where('company_id', $doc->company_id)
                    ->where('document_id', $doc->id)->orderByDesc('id')->first();
                $yontemler = [
                    Payment::METHOD_CASH => __('Nakit'),
                    Payment::METHOD_BANK => __('Banka'),
                    Payment::METHOD_CARD => __('Kart'),
                    Payment::METHOD_TRANSFER => __('Havale / EFT'),
                ];
                $cikti['isReceipt'] = true;
                $cikti['size'] = '80';
                $cikti['paymentMethodLabel'] = $yontemler[$odeme?->method] ?? ($odeme?->method ?? '-');
                $cikti['tendered'] = $odeme?->tendered_amount === null ? null : (float) $odeme->tendered_amount;
                $cikti['change'] = $odeme?->change_amount === null ? null : (float) $odeme->change_amount;
            }

            return $cikti;
        }

        $sirket = new Company([
            'name' => 'Örnek Ticaret Ltd.',
            'address' => 'Örnek Mah. Test Cad. No: 1',
            'city' => 'İstanbul',
            'tax_office' => 'Kadıköy',
            'tax_number' => '1234567890',
            'phone' => '0216 000 00 00',
        ]);
        $cari = new Party(['name' => 'Örnek Müşteri', 'code' => 'C-001', 'phone' => '0532 000 00 00']);

        $tr = $lang === 'tr';
        $ad1 = $tr ? 'Volvo Smart Anahtar 433MHz' : 'Volvo Smart Key 433MHz';
        $ad2 = $tr ? 'Pil CR2032' : 'Battery CR2032';

        $mkKalem = function (string $ad, float $adet, float $fiyat, float $kdv) use ($tr) {
            $iskonto = round($adet * $fiyat * 0.1, 2);
            $ara = round($adet * $fiyat - $iskonto, 2);
            $vergi = round($ara * $kdv / 100, 2);

            return new DocumentItem([
                'name' => $ad,
                'quantity' => $adet,
                'unit_price' => $fiyat,
                'discount_rate' => 10,
                'tax_rate' => $kdv,
                'line_discount' => $iskonto,
                'line_tax' => $vergi,
                'line_total' => round($ara + $vergi, 2),
                'display_name' => $ad,
                'unit_display_name' => $tr ? 'Adet' : 'Pcs',
            ]);
        };

        $kalemler = [$mkKalem($ad1, 1, 1750, 20), $mkKalem($ad2, 2, 50, 20)];
        $ara = round(array_sum(array_map(fn ($k) => $k->quantity * $k->unit_price - $k->line_discount, $kalemler)), 2);
        $vergi = round(array_sum(array_map(fn ($k) => $k->line_tax, $kalemler)), 2);

        $tur = $kind === 'quote' ? Document::TYPE_QUOTE
            : ($kind === 'sales_order' ? Document::TYPE_SALES_ORDER
            : ($kind === 'purchase' ? Document::TYPE_PURCHASE : Document::TYPE_INVOICE));

        $belge = new Document([
            'type' => $tur,
            'serial' => 'ÖRN-26-00001',
            'status' => $kind === 'delivery' ? 'issued' : 'issued',
            'issue_date' => '2026-11-06',
            'due_date' => '2026-11-13',
            'currency_code' => 'TRY',
            'subtotal' => $ara,
            'discount_total' => round($ara * 0.1 + 100, 2),
            'tax_total' => $vergi,
            'grand_total' => round($ara + $vergi, 2),
            'notes' => $tr ? 'Örnek açıklama satırı.' : 'Sample note line.',
        ]);
        $belge->setRelation('items', collect($kalemler));

        $money = static fn ($v): string => $tr
            ? number_format((float) $v, 2, ',', '.')
            : number_format((float) $v, 2, '.', ',');
        $qty = static function ($v) use ($tr): string {
            $s = number_format((float) $v, 2, $tr ? ',' : '.', $tr ? '.' : ',');
            $sep = $tr ? ',' : '.';

            return str_contains($s, $sep) ? rtrim(rtrim($s, '0'), $sep) : $s;
        };
        $date = static function ($d) use ($tr): string {
            if (! $d) return '';
            $c = $d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d);
            $trA = [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
            $enA = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            return $tr ? ((int) $c->format('j') . ' ' . $trA[(int) $c->format('n')] . ' ' . $c->format('Y'))
                : ($enA[(int) $c->format('n')] . ' ' . $c->format('j') . ', ' . $c->format('Y'));
        };

        $cikti = [
            'document' => $belge,
            'company' => $sirket,
            'party' => $cari,
            'logoFile' => null,
            'paid' => 0.0,
            'remaining' => (float) $belge->grand_total,
            'money' => $money,
            'qty' => $qty,
            'date' => $date,
            'typeLabel' => $tur,
            'statusLabel' => $tr ? 'İhraç' : 'Issued',
            'tpl' => PrintTemplate::normalizeForOutput($layout),
        ];

        if ($kind === 'receipt') {
            $cikti['isReceipt'] = true;
            $cikti['size'] = '80';
            $cikti['paymentMethodLabel'] = $tr ? 'Nakit' : 'Cash';
            $cikti['tendered'] = (float) $belge->grand_total;
            $cikti['change'] = 0.0;
        }

        return $cikti;
    }

    protected function sampleLabel(string $kind, array $layout, string $lang, ?int $productId = null): array
    {
        $tr = $lang === 'tr';
        [$bw, $bh] = BarcodeController::LABEL_SIZES[$layout['size']];

        // Gerçek ürün istendiyse onun adıyla bas (uzun ad taşmaları tasarımda yakalansın).
        $ornekAd = $tr ? 'Volvo Smart Anahtar 433MHz' : 'Volvo Smart Key 433MHz';
        $ornekKod = '240000000001';
        $ornekFiyat = 1750.0;
        if ($productId !== null) {
            $urun = Product::findOrFail($productId);
            $ornekAd = $urun->localizedName($lang);
            $ornekFiyat = (float) ($urun->sale_price ?? 0);
            $gercekKod = (string) ($urun->barcode ?: $urun->sku ?: '');
            if ($gercekKod !== '') {
                $ornekKod = $gercekKod;
            }
        }

        // Önizleme bestesi baskıyla birebir aynı koldan geçer (GD varsa).
        $beste = null;
        if (LabelRenderer::available()) {
            $beste = 'data:image/png;base64,' . base64_encode(LabelRenderer::compose(
                [$bw, $bh],
                $layout['fields'],
                [
                    'name' => $ornekAd,
                    'code' => $ornekKod,
                    'price' => $ornekFiyat,
                    'barcode' => $ornekKod,
                    'showPrice' => $layout['showPrice'],
                    'lang' => $lang,
                ]
            ));
        }

        $png = ProductBarcode::render($ornekKod, 'code128', 'png', 1, $bh >= 30 ? 34 : 26)['content'];

        return [
            'labels' => [[
                'name' => $ornekAd,
                'code' => $ornekKod,
                'image' => $beste ?? ('data:image/png;base64,' . base64_encode($png)),
                'price' => $ornekFiyat,
                'composed' => $beste !== null,
            ]],
            'showPrice' => $layout['showPrice'],
            'size' => $layout['size'],
            'dims' => [$bw, $bh],
            'money' => static fn ($v): string => $tr
                ? number_format((float) $v, 2, ',', '.')
                : number_format((float) $v, 2, '.', ','),
        ];
    }
}
