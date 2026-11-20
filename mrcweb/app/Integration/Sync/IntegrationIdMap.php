<?php

namespace App\Integration\Sync;

use Illuminate\Database\Eloquent\Model;

/** MRC kaydı ile dış sistem kaydı arasındaki birebir eşleme. */
class IntegrationIdMap extends Model
{
    protected $table = 'integration_id_map';

    protected $fillable = [
        'company_id', 'mrc_type', 'mrc_id', 'external_type', 'external_key',
    ];
}
