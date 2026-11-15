<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yazdırma şablonu : firmanın belge/etiket çıktısını nasıl
 * basacağını tarif eden JSON. `layout` v1 şeması PrintTemplateController'da
 * doğrulanır; işleme DocumentPdf/QuickSale/Barcode controller'larında okunur.
 *
 * Neden ayrı tablo (company JSON alanı değil): şablonlar listelenir,
 * kopyalanır, varsayılan seçilir; tür başına birden çok varyant durabilir
 * (örn. "Sade irsaliye", "Logolu irsaliye").
 */
class PrintTemplate extends Model
{
    protected $fillable = ['company_id', 'kind', 'name', 'is_default', 'layout'];

    protected $casts = [
        'is_default' => 'boolean',
        'layout' => 'array',
    ];

    public const KINDS = ['invoice', 'quote', 'sales_order', 'purchase', 'receipt', 'delivery', 'label'];

    public const DOC_KINDS = ['invoice', 'quote', 'sales_order', 'purchase', 'receipt', 'delivery'];

    /** Kalem tablosu sütunları (görünürlük tasarımcıdan açılıp kapatılır). */
    public const COLUMNS = ['miktar', 'birim', 'birim_fiyat', 'iskonto', 'kdv', 'satir_toplam'];

    /** Toplam tablosu satırları (genel toplam her zaman basılır). */
    public const TOTALS = ['ara_toplam', 'iskonto', 'kdv'];

    /** Yazı boyu ölçekleri. */
    public const FONT_SCALES = ['compact', 'normal', 'large'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function defaultFor(int $companyId, string $kind): ?self
    {
        return static::where('company_id', $companyId)
            ->where('kind', $kind)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Çıktıda kullanılacak çözümlenmiş yerleşim (yoksa null → yerleşik tasarım).
     * Yalnız görünür bloklar (tasarımcı sırasıyla) + stil varsayılanlarıyla döner;
     * blade'ler bu diziye göre kısmi şablonları dizer.
     */
    public static function resolvedLayout(int $companyId, string $kind): ?array
    {
        $t = static::defaultFor($companyId, $kind);
        if (! $t || ! is_array($t->layout)) {
            return null;
        }

        return static::normalizeForOutput($t->layout);
    }

    /**
     * Kayıtlı taslağı çıktı biçimine indirger (blok anahtar listesi + stil).
     * Önizleme de aynı yolu kullanır: tek doğruluk kaynağı.
     *
     * @param array{blocks?:array,style?:array} $layout
     */
    public static function normalizeForOutput(array $layout): ?array
    {
        $ham = collect($layout['blocks'] ?? [])
            ->filter(fn ($b) => ($b['visible'] ?? true) && isset($b['key']));
        if ($ham->isEmpty()) {
            return null;
        }

        // Çıktı biçimi: [{key, tip?, text?, yukseklik?}] — 'ozel' öğe verisini taşır.
        $bloklar = $ham->map(fn ($b) => is_array($b) && $b['key'] === 'ozel'
            ? array_merge(['key' => 'ozel', 'tip' => 'metin'], array_intersect_key($b, ['tip' => 1, 'text' => 1, 'yukseklik' => 1, 'visible' => 1]))
            : ['key' => $b['key']]
        )->values()->all();

        $stil = $layout['style'] ?? [];

        $sutunlar = [];
        foreach (self::COLUMNS as $k) {
            $sutunlar[$k] = (bool) ($stil['columns'][$k] ?? true);
        }
        $toplamlar = [];
        foreach (self::TOTALS as $k) {
            $toplamlar[$k] = (bool) ($stil['totals'][$k] ?? true);
        }

        return [
            'blocks' => $bloklar,
            'style' => [
                'logoPos' => $stil['logoPos'] ?? 'left',
                'headerColor' => $stil['headerColor'] ?? '#1e3a5f',
                'footerText' => $stil['footerText'] ?? null,
                'fontScale' => $stil['fontScale'] ?? 'normal',
                'logoVisible' => array_key_exists('logoVisible', $stil) ? (bool) $stil['logoVisible'] : true,
                'columns' => $sutunlar,
                'totals' => $toplamlar,
            ],
        ];
    }
}
