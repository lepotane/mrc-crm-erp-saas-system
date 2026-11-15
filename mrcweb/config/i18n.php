<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dil (i18n) ayarları
    |--------------------------------------------------------------------------
    | Ana dil Türkçe; ikinci dil İngilizce. Kullanıcı tercihi `users.locale`
    | alanında saklanır ve Ayarlar ekranından değiştirilir. Tercih yoksa
    | X-Locale / Accept-Language başlığı, o da yoksa default kullanılır.
    */

    'default' => env('APP_LOCALE', 'tr'),

    // API ve SPA'nın kabul ettiği desteklenen yerel ayarlar.
    'supported' => ['tr', 'en'],

    // Kullanıcı tercihi olmayan isteklerde bakılacak başlıklar.
    'header' => 'X-Locale',

    // Sayı/tarih biçimlendirme için BCP-47 karşılıkları (SPA + raporlar).
    'formats' => [
        'tr' => 'tr-TR',
        'en' => 'en-US',
    ],
];
