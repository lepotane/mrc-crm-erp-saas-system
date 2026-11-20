<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GibDocument extends Model
{
    protected $table = 'gib_documents';

    protected $fillable = [
        'company_id',
        'document_id',
        'document_type',
        'ettN',
        'direction',
        'status',
        'file_path',
        'xml',
        'integrator_ref',
        'response',
        'official_at',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'document_id' => 'integer',
            'response' => 'array',
            'official_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isOfficial(): bool
    {
        return $this->status === 'official';
    }
}
