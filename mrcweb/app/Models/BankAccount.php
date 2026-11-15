<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    public const TYPE_CASH = 'cash';
    public const TYPE_BANK = 'bank';
    public const TYPE_CARD = 'card';

    public const TYPES = [
        self::TYPE_CASH,
        self::TYPE_BANK,
        self::TYPE_CARD,
    ];

    protected $fillable = [
        'company_id', 'name', 'type', 'account_number', 'iban',
        'currency_code', 'opening_balance', 'is_default', 'is_active', 'note',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:4',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Güncel bakiye: açılış + tahsilatlar − ödemeler (v1 tek para birimi varsayımı).
     */
    public function balance(): float
    {
        $in = round((float) ($this->getAttribute('in_total') ?? $this->payments()->where('direction', 'in')->sum('amount')), 4);
        $out = round((float) ($this->getAttribute('out_total') ?? $this->payments()->where('direction', 'out')->sum('amount')), 4);

        return round((float) $this->opening_balance + $in - $out, 4);
    }
}
