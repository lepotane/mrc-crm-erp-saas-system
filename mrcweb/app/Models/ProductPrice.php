<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ürünün para birimi bazlı fiyatı (fiyat sınıfı).
 *
 * Bir ürünün TL / USD / EUR fiyatı FARKLI olabilir. Bu tablo her para birimi
 * için bir satır tutar; `products.sale_price` ana fiyat olarak kalır.
 *
 * Güvenlik notu: bu bir fiyat, sır DEĞİLDİR — şifrelenmez.
 */
class ProductPrice extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'currency_code',
        'sale_price',
        'cost_price',
    ];

    protected $casts = [
        'sale_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
