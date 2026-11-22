<?php

/*
 |--------------------------------------------------------------------------
 | Entegrasyon hata bildirimleri (Telegram köprüsü)
 |--------------------------------------------------------------------------
 |
 | Token/sohbet bilgileri ASLA koda veya depoya yazılmaz; yalnızca ortam
 | değişkenlerinden okunur. Varsayılan olarak bildirimler KAPALIDIR.
 | Köprü adresi sabittir; bu servis yalnızca ona POST atar.
 |
 */

return [
    'enabled' => env('MRC_TELEGRAM_ENABLED', false),

    // Gizli değerler — yalnızca env'den; loglara/buil'a yazılmaz.
    'token' => env('MRC_TELEGRAM_TOKEN'),
    'chat' => env('MRC_TELEGRAM_CHAT'),

    'endpoint' => 'https://tg.ortaklarmotors.com/telegram-bridge.php',

    // Aynı olay için tekrar bildirim gönderilmesini engeller (spam önleme).
    'min_interval_minutes' => 15,
];
