<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Para birimi tanımı : ISO kod + ad + sembol + ondalık + etkin bayrağı.
 *
 * Doğrulama listeleri (`in:TRY,USD,EUR` gibi gömülü diziler) bu tablodan okunur:
 * yeni para birimi eklenince 6 controller'da kural değişmez — `Currency::activeCodes()`
 * tek kaynaktır. Liste seyrek değişir, o yüzden 1 saat önbelleklidir; admin
 * değişikliğinde önbellek düşürülür (`forgetActiveCodes()`).
 */
class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'decimals' => 'integer',
        'sort_order' => 'integer',
    ];

    /** Doğrulamada kullanılacak etkin kodlar (sıralı). */
    public static function activeCodes(): array
    {
        return Cache::remember('currencies:active-codes', 3600, fn () => static::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->pluck('code')
            ->all());
    }

    public static function forgetActiveCodes(): void
    {
        Cache::forget('currencies:active-codes');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
