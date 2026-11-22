<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Belge tipi + rol için kiracıya ait hesap eşlemesi.
 *
 * Roller: debit | credit | tax | counter. Kodlar veriden gelir;
 * sınıfta gömülü (varsayılan) hesap kodu bulunmaz.
 */
class AccountMapping extends Model
{
    public const ROLE_DEBIT = 'debit';

    public const ROLE_CREDIT = 'credit';

    public const ROLE_TAX = 'tax';

    public const ROLE_COUNTER = 'counter';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'account_mappings';

    protected $fillable = [
        'company_id',
        'document_type',
        'role',
        'account_code',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
