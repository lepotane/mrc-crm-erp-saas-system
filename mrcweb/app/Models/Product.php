<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'company_id', 'category_id', 'unit_id', 'name', 'name_en', 'sku', 'barcode',
        'description', 'cost_price', 'sale_price', 'currency_code',
        'tax_rate', 'track_inventory', 'min_stock', 'max_stock',
        'image_url', 'images',
    ];

    /**
     * Çıktı dilindeki ad : İngilizce istendiyse ve girilmişse
     * İngilizcesi, yoksa Türkçe ad (sessiz geri düşüş — boş satır çıkmaz).
     */
    public function localizedName(?string $lang = null): string
    {
        $lang ??= app()->getLocale();

        if ($lang === 'en' && filled($this->name_en)) {
            return (string) $this->name_en;
        }

        return (string) $this->name;
    }

    protected $casts = [
        'cost_price' => 'decimal:4',
        'sale_price' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'track_inventory' => 'boolean',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'images' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Para birimi bazlı fiyat listesi (fiyat sınıfları).
     *
     * Aynı ürünün TL / USD / EUR fiyatı farklı olabilir. `sale_price` ana
     * fiyattır; diğer para birimleri bu tablodadır.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Belirli bir para birimi için fiyat satırı (yoksa null).
     */
    public function priceFor(string $currencyCode): ?ProductPrice
    {
        $ccy = strtoupper(trim($currencyCode));

        if ($ccy === '') {
            return null;
        }

        return $this->prices->firstWhere('currency_code', $ccy);
    }

    /**
     * Ürünün verilen para birimindeki SATIŞ fiyatı.
     *
     * Öncelik: o para biriminin kendi fiyat satırı → ana fiyat (kurla çevrilmiş).
     *
     * Kurlar "1 birim döviz = kaç TRY" anlamındadır:
     *   fiyat_hedef = fiyat_ana × kur(ana para) / kur(hedef para)
     * Kur yoksa (null/0) çevrim yapılamaz → ana fiyat döner; fiyat sınıfı
     * değişse bile sıfır/boş fiyat oluşmaz.
     *
     * @param  float  $baseRate  Ana fiyatın para biriminin kuru (TRY'ye göre).
     * @param  float  $targetRate  Hedef para biriminin kuru (TRY'ye göre).
     */
    public function salePriceIn(string $currencyCode, ?float $baseRate = null, ?float $targetRate = null): float
    {
        $ccy = strtoupper(trim($currencyCode));
        $kendi = $this->prices->firstWhere('currency_code', $ccy);

        if ($kendi !== null) {
            return (float) $kendi->sale_price;
        }

        $ana = (float) $this->sale_price;
        $anaCcy = strtoupper((string) ($this->currency_code ?: 'TRY'));

        if ($ccy === '' || $ccy === $anaCcy || ! $baseRate || ! $targetRate || $targetRate <= 0) {
            return $ana;
        }

        return round($ana * $baseRate / $targetRate, 4);
    }

    /**
     * Ağırlıklı ortalama maliyet (WAC): alım issued anında çağrılır.
     * yeni = (eski_stok * eski_maliyet + qty * in_cost) / (eski_stok + qty)
     * Bölen sıfır/negatifse giriş maliyeti yazılır (sıfıra bölme koruması).
     */
    public function applyWeightedAverageCost(int $companyId, float $qty, float $unitCost): void
    {
        $oldStock = StockMovement::stockFor($companyId, $this->id);
        $oldCost = (float) $this->cost_price;
        $newStock = $oldStock + $qty;

        $newCost = $newStock <= 0
            ? $unitCost
            : round(($oldStock * $oldCost + $qty * $unitCost) / $newStock, 4);

        $this->update(['cost_price' => $newCost]);
    }
}
