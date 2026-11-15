<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Günlük kur fotoğrafı : 1 birim döviz = kaç TRY (`rate`), hangi gün (`rate_date`),
 * hangi kaynaktan (`tcmb`|`erapi`).
 *
 * TRY satırı YOKTUR: 1 TRY = 1 TRY her zaman, tabloya yazılmaz. `ExchangeRates::rateFor`
 * TRY için 1 döner, döviz için o güne eşit/önceki en yeni satırı okur, hiç satır yoksa null.
 */
class ExchangeRate extends Model
{
    protected $fillable = ['currency_code', 'rate_date', 'rate', 'source'];

    // rate_date'de CAST YOKTUR (bilinçli): Eloquent date-cast yazarken saat ekler
    // („2026-10-30 00:00:00") ve unique (code,date) araması bulamaz → çift satır
    // hatası doğar. Tarih her zaman „Y-m-d" string tutulur, okurken substr alınır.
    protected $casts = [
        'rate' => 'decimal:6',
    ];
}
