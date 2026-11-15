<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firma başına modül/eklenti durumu .
 *
 * Satır YOKSA modül açıktır (lazy default — backfill yok). Satır VARSA
 * enabled/config kararı odur. Kapatma VERİYİ silmez; satır ve modülün
 * kayıtları durur, yeniden açma kayıpsız döner.
 */
class Module extends Model
{
    public const SOURCE_CORE = 'core';

    public const SOURCE_LOCAL = 'local';

    protected $fillable = [
        'company_id', 'key', 'enabled', 'config', 'version', 'source', 'installed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
        'installed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
