<?php

namespace App\Integration\Orders;

use Illuminate\Database\Eloquent\Model;

/**
 * Kiracıya ait durum eşleme satırı (integration_status_map).
 *
 * Dış kanalın durum kodu MRC durumuna, MRC durumu da kanala geri çevrilir.
 * Eşleme yoksa tahmin yapılmaz; StatusMapper güvenli yedek döndürür.
 */
final class StatusMap extends Model
{
    protected $table = 'integration_status_map';

    protected $fillable = [
        'company_id',
        'channel',
        'external_status',
        'mrc_status',
    ];
}
