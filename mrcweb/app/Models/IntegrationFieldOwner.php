<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Alan sahipliği kaydı: firma + varlık + alan için kaynak (mrc | tsoft).
 */
class IntegrationFieldOwner extends Model
{
    public const SOURCE_MRC = 'mrc';

    public const SOURCE_TSOFT = 'tsoft';

    protected $table = 'integration_field_owner';

    protected $fillable = ['company_id', 'entity', 'field', 'source'];
}
