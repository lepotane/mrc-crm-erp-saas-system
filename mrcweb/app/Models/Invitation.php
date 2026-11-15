<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    protected $fillable = [
        'company_id',
        'invited_by',
        'email',
        'role',
        'token',
        'token_hash',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        // Token APP_KEY ile şifrelenir: veritabanı sızsa bile davet bağlantısı
        // kullanılamaz. Arama için sha256 özeti `token_hash` kolonunda durur.
        'token' => 'encrypted',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /** Token'ın arama/lookup özeti (şifreli kolon üzerinde sorgu yapılamaz). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Arama özetini (token_hash) otomatik tutar — token şifreli saklandığı için
     * sorgu özet üzerinden yapılır; özet elle yazılmazsa davet "bulunamaz" olurdu.
     */
    protected static function booted(): void
    {
        static::saving(function (self $davet): void {
            if (! empty($davet->token) && empty($davet->token_hash)) {
                $davet->token_hash = static::hashToken((string) $davet->token);
            }
        });
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function status(): string
    {
        if ($this->accepted_at) {
            return 'kabul edildi';
        }

        return $this->isExpired() ? 'süresi geçti' : 'bekliyor';
    }
}
