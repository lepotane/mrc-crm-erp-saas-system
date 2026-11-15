<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyJoinRequest extends Model
{
    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'password_encrypted',
        'phone',
        'address',
        'requested_class',
        'message',
        'cv_path',
        'cv_original_name',
        'custom_answers',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $hidden = [
        'password_hash',
        'password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'custom_answers' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
