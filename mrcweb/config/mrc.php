<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Süper Yönetici (SuperAdmin) hesabı
    |--------------------------------------------------------------------------
    |
    | Platformun sahibi olan tek hesap. Firma/tenant yönetiminden ayrıdır: üye
    | listelerinde görünmez, modül kısıtlamalarından muaftır, pending/suspended
    | denetimine girmez (bkz. EnsureSuperAdmin, CompanyContext).
    |
    | GÜVENLİK: Burada HİÇBİR varsayılan parola yoktur. Parola yalnızca
    | ortam değişkeninden (`.env`) gelir; tanımlı değilse komut/migration
    | açık hata verir ve hesabın parolası rastgele (kullanılamaz) bırakılır.
    | Böylece depo ele geçse bile "bilinen" bir yönetici parolası yoktur.
    |
    |   MRC_SUPERADMIN_EMAIL=...
    |   MRC_SUPERADMIN_PASSWORD=...
    |
    | Parolayı değiştirmek: `.env`i güncelle + `php artisan mrc:create-superadmin`
    |
    */

    'superadmin' => [
        'email' => env('MRC_SUPERADMIN_EMAIL') ?: 'superadmin@example.com',
        'password' => env('MRC_SUPERADMIN_PASSWORD'), // parola YOKSA boş → üretilmez
        'name' => env('MRC_SUPERADMIN_NAME') ?: 'Super Admin',
        'user_class' => env('MRC_SUPERADMIN_CLASS') ?: 'Süper Yönetici',
        'job_title' => env('MRC_SUPERADMIN_TITLE') ?: 'System Administrator',
        'avatar_color' => env('MRC_SUPERADMIN_COLOR') ?: '#B9FF66',
    ],

];
