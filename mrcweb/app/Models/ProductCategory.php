<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $fillable = [
        'company_id', 'name', 'color', 'parent_id', 'external_code', 'is_leaf',
    ];

    protected $casts = ['is_leaf' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** Üst kategori (Tsoft ParentCode hiyerarşisi). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Alt kategoriler (Tsoft HasChild bilgisiyle uyumlu). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
