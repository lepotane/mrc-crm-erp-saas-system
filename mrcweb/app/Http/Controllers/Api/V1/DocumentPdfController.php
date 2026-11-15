<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Belge PDF üretimi : teklif / sipariş / fatura / alım faturası.
 * Logo public disk’ten **yerel dosya yolu** olarak verilir : dompdf uzak
 * kaynak çekmez, böylece SSRF/sunucudan sunucuya istek riski de doğmaz.
 */
class DocumentPdfController extends Controller
{
    use ResolvesTenant;

    /**
     * Çıktı dilini çöz : `?lang=tr|en`. Geçersizse 422; yoksa mevcut
     * uygulama dili korunur. Tüm PDF uçları (belge/fiş/irsaliye/etiket) buradan geçer.
     */
    public static function resolveOutputLang(Request $request): string
    {
        $lang = $request->query('lang');

        if ($lang === null) {
            return app()->getLocale();
        }

        if (! in_array($lang, ['tr', 'en'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lang' => [__('Dil tr ya da en olmalıdır.')],
            ]);
        }

        app()->setLocale($lang);

        return $lang;
    }

    /**
     * Belge PDF'ini indir (inline veya attachment, dile göre çevrilmiş etiketler)
     *
     * Bu uç JSON DEĞİL dosya döndürür: Content-Type: application/pdf ve
     * Content-Disposition: attachment; filename="<seri>.pdf". `inline=1` sorgu
     * parametresi gönderilirse tarayıcıda açılır (inline). Hata gövdesi sözleşmesi
     * ({message}) yalnız 401/403/404 gibi hata durumlarında devreye girer.
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 200,
        description: 'PDF dosyası (uygulama/pdf). Yanıt gövdesi ikili veridir; JSON sözleşmesi burada geçerli değildir.',
        mediaType: 'application/pdf',
        type: 'string',
        format: 'binary',
    )]
    public function show(Request $request, Document $document)
    {
        abort_if(
            $document->company_id !== $this->tenantId($request),
            404,
            __('Belge bulunamadı.')
        );

        self::resolveOutputLang($request);

        $pdf = Pdf::loadView('pdf.document', $this->payload($document));
        $pdf->setPaper('a4');

        $disposition = $request->boolean('inline')
            ? 'inline'
            : 'attachment; filename="'.$document->serial.'.pdf"';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            // mali belge, token'lı GET: ortak bilgisayarda tarayıcı önbelleğine düşmesin
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * İrsaliye görünümü : satış/alış faturasının fiyatsız miktar
     * listesidir; ayrı belge tipi YOKTUR (muhasebe etkisi yok, yalnız çıktı).
     * Yalnız kesilmiş (taslak/iptal dışı) belgeden üretilir.
     *
     * Bu uç JSON DEĞİL dosya döndürür: Content-Type: application/pdf.
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 200,
        description: 'İrsaliye PDF dosyası (uygulama/pdf). Yanıt gövdesi ikili veridir.',
        mediaType: 'application/pdf',
        type: 'string',
        format: 'binary',
    )]
    public function deliveryNote(Request $request, Document $document)
    {
        $companyId = $this->tenantId($request);

        abort_if(
            $document->company_id !== $companyId
                || ! in_array($document->type, [Document::TYPE_INVOICE, Document::TYPE_PURCHASE], true)
                || in_array($document->status, ['draft', 'cancelled'], true),
            404,
            __('Belge bulunamadı.')
        );

        self::resolveOutputLang($request);

        $pdf = Pdf::loadView('pdf.delivery', $this->payload($document, 'delivery'));
        $pdf->setPaper('a4');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="irsaliye-'.$document->serial.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Şablon verisi (test edilebilir olsun diye ayrı metodda): aktif dile göre
     * para/miktar/tarih biçimlendirme kapatıları ve çevrilmiş etiketler.
     *
     * $kind verilmezse belge türünden çıkarılır; delivery/receipt açıkça verilir.
     * Çözümlenmiş şablon yerleşimi (`tpl`) blade'de blok sırası/görünürlük/stil
     * için kullanılır; şablon yoksa null → yerleşik tasarım basılır.
     *
     * @return array<string, mixed>
     */
    public function payload(Document $document, ?string $kind = null): array
    {
        $document->loadMissing(['items.product.unit', 'party', 'company']);

        // Satır görünen adları çıktı diline çöz : ürün/birim İngilizcesi
        // girilmişse o, girilmemişse Türkçe (localizedName içinde sessiz düşüş).
        $lang = app()->getLocale();
        foreach ($document->items as $item) {
            $item->setAttribute('display_name', $item->product?->localizedName($lang) ?? $item->name);
            $item->setAttribute('unit_display_name', $item->product?->unit?->localizedName($lang) ?? '');
        }

        $paid = round((float) Payment::where('company_id', $document->company_id)
            ->where('document_id', $document->id)
            ->sum('amount'), 4);
        $grand = round((float) $document->grand_total, 4);

        // Logo: public disk’teki dosyanın **mutlak yol olarak** kök hâli verilir.
        // dompdf uzak kaynak (http) çekmez — hem SSRRF riski yok hem de paylaşımlı
        // hostta kendi kendine istek atma gerekmez. Dosya silinmişse logo atlanır
        // (belge yine üretilir).
        $logoFile = null;
        $logoPath = $document->company?->logo_path;
        if ($logoPath && Storage::disk('public')->exists($logoPath)) {
            $logoFile = Storage::disk('public')->path($logoPath);
        }

        $locale = app()->getLocale();

        $money = static fn ($v): string => $locale === 'tr'
            ? number_format((float) $v, 2, ',', '.')
            : number_format((float) $v, 2, '.', ',');

        $qty = static function ($v) use ($locale): string {
            $s = number_format((float) $v, 2, $locale === 'tr' ? ',' : '.', $locale === 'tr' ? '.' : ',');
            $sep = $locale === 'tr' ? ',' : '.';

            return str_contains($s, $sep) ? rtrim(rtrim($s, '0'), $sep) : $s;
        };

        $date = static function ($d) use ($locale): string {
            if ($d === null) {
                return '';
            }
            $day = (int) $d->format('j');
            $month = (int) $d->format('n');
            $year = $d->format('Y');
            $tr = [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
            $en = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            return $locale === 'tr'
                ? "{$day} {$tr[$month]} {$year}"
                : "{$en[$month]} {$day}, {$year}";
        };

        $typeLabels = [
            Document::TYPE_QUOTE => __('Teklif'),
            Document::TYPE_SALES_ORDER => __('Sipariş'),
            Document::TYPE_INVOICE => __('Fatura'),
            Document::TYPE_PURCHASE => __('Alım faturası'),
            Document::TYPE_RETURN => __('İade faturası'),
        ];

        $statusLabels = [
            'draft' => __('Taslak'),
            'sent' => __('Gönderildi'),
            'accepted' => __('Kabul edildi'),
            'rejected' => __('Reddedildi'),
            'confirmed' => __('Onaylandı'),
            'issued' => __('İhraç'),
            'paid' => __('Ödendi'),
            'partially_paid' => __('Kısmen ödendi'),
            'cancelled' => __('İptal'),
        ];

        return [
            'document' => $document,
            'company' => $document->company,
            'party' => $document->party,
            'logoFile' => $logoFile,
            'paid' => $paid,
            'remaining' => round($grand - $paid, 4),
            'money' => $money,
            'qty' => $qty,
            'date' => $date,
            'typeLabel' => $typeLabels[$document->type] ?? $document->type,
            'statusLabel' => $statusLabels[$document->status] ?? $document->status,
            'tpl' => \App\Models\PrintTemplate::resolvedLayout(
                (int) $document->company_id,
                $kind ?? match ($document->type) {
                    Document::TYPE_QUOTE => 'quote',
                    Document::TYPE_SALES_ORDER => 'sales_order',
                    Document::TYPE_PURCHASE => 'purchase',
                    default => 'invoice',
                }
            ),
        ];
    }
}
