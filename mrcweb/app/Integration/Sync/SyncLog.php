<?php

namespace App\Integration\Sync;

use Illuminate\Database\Eloquent\Model;

/** Senkronizasyon denemelerinin günlüğü (yalnızca maskeli önizleme). */
class SyncLog extends Model
{
    protected $table = 'sync_logs';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'channel', 'direction', 'entity', 'external_ref',
        'duration_ms', 'status', 'preview',
    ];
}
