<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSeries extends Model
{
    protected $fillable = ['company_id', 'type', 'year', 'next_number'];

    protected $casts = ['year' => 'integer', 'next_number' => 'integer'];
}
