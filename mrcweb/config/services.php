<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Online tahsilat — PayTR ve iyzico 
    |--------------------------------------------------------------------------
    | Burada DURAN ŞEY ADRESLER, sırlar değil. Mağaza no / anahtar / salt / secret key
    | firma başına `payment_credentials` tablosunda şifreli durur: her firma kendi
    | sanal POS hesabıyla tahsilat yapar (paranın bize akması doğru olmazdı).
    |
    | `callback_path` sağlayıcıya VERİLEN adrestir ve tüm kiracılar için ORTAKTIR:
    | bildirim hangi firmaya ait, link satırından çözülür. PayTR'da bu adres mağaza
    | panelinde ("Bildirim URL") tanımlanır; kod ile gönderilmez.
    |
    | PayTR'da sandbox SUNUCUSU yoktur; deneme aynı adreste test_mode=1 ile yapılır.
    */

    'paytr' => [
        'base_url' => env('PAYTR_BASE_URL', 'https://www.paytr.com'),
        'callback_path' => env('PAYTR_CALLBACK_PATH', '/api/paytr/callback'),
    ],

    'iyzico' => [
        'base_url' => env('IYZICO_BASE_URL', 'https://api.iyzipay.com'),
        'sandbox_base_url' => env('IYZICO_SANDBOX_BASE_URL', 'https://sandbox-api.iyzipay.com'),
        'callback_path' => env('IYZICO_CALLBACK_PATH', '/api/iyzico/callback'),
    ],

];
