<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İçe aktarılmış hesap planı satırı (kiracı başına).
 *
 * Kodlar veri olarak tutulur; sınıf içinde varsayılan hesap kodu yoktur.
 */
class LucaAccountPlan extends Model
{
    public const SOURCE_IMPORT = 'import';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'luca_account_plans';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'parent_code',
        'is_active',
        'source',
        'external_ref',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Bu firma için hesap planı hiç senkronize edilmiş mi? */
    public static function upToDate(int $companyId): bool
    {
        return static::query()
            ->where('company_id', $companyId)
            ->whereNotNull('synced_at')
            ->exists();
    }
}
