<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = ['company_id', 'name', 'name_en', 'code', 'is_default'];

    /**
     * Çıktı dilindeki ad : İngilizce istendiyse ve girilmişse
     * İngilizcesi, yoksa Türkçe ad.
     */
    public function localizedName(?string $lang = null): string
    {
        $lang ??= app()->getLocale();

        if ($lang === 'en' && filled($this->name_en)) {
            return (string) $this->name_en;
        }

        return (string) $this->name;
    }

    protected $casts = ['is_default' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
