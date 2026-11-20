<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GibInboundFile extends Model
{
    protected $table = 'gib_inbound_files';

    protected $fillable = [
        'company_id',
        'ettN',
        'document_type',
        'file_path',
        'received_at',
        'matched_document_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'matched_document_id' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function matchedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'matched_document_id');
    }
}
