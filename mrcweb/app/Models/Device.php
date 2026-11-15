<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bridge agent cihazı : firmanın PC'si + bağlı yazıcı listesi.
 * `printers` JSON dizisidir (örn. ["Termal-80", "Ofis HP"]).
 */
class Device extends Model
{
    protected $fillable = [
        'company_id', 'name', 'printers', 'pairing_code_hash', 'pairing_expires_at',
        'token_hash', 'last_seen_at',
    ];

    protected $casts = [
        'printers' => 'array',
        'pairing_expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /** Nabza göre çevrimiçi sayılır (2 dakika eşiği — istemci göstergesi). */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subMinutes(2));
    }
}
