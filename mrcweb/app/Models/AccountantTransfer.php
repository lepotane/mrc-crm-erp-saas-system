<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountantTransfer extends Model
{
    protected $table = 'accountant_transfers';

    protected $fillable = [
        'company_id',
        'voucher_ids',
        'driver',
        'status',
        'approved_by',
        'transferred_at',
        'external_ref',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'voucher_ids' => 'array',
            'approved_by' => 'integer',
            'transferred_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
