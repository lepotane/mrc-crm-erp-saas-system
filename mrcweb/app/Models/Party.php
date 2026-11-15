<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $fillable = [
        'company_id', 'type', 'code', 'name', 'tax_number', 'phone', 'email',
        'address', 'city', 'currency_code', 'opening_balance', 'credit_limit',
        'notes', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:4',
        'credit_limit' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    /**
     * Firma içi sıra numarası: 00001, 00002 ...
     */
    public static function nextCode(int $companyId): string
    {
        $max = (int) (static::query()
            ->where('company_id', $companyId)
            ->selectRaw('MAX(CAST(code AS UNSIGNED)) AS m')
            ->value('m') ?? 0);

        return sprintf('%05d', $max + 1);
    }
}
