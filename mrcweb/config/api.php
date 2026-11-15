<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API v1 sözleşme sabitleri 
    |--------------------------------------------------------------------------
    | Masaüstü istemci ve entegrasyonlar bu değerlere göre davranır.
    | cPanel veya sunucu yayınında .env ile ezilebilir.
    */

    'rate_limit' => [
        // Erişim anahtarı başına dakikalık istek bütçesi (throttle:api, yumuşak).
        'api' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
        // IP başına sert tavan: rastgele token’larla bütçe dağıtmayı engeller.
        'ip_hard' => (int) env('API_IP_HARD_LIMIT_PER_MINUTE', 600),
        // Giriş/kayıt uçları: e-posta + IP üzerinden brute-force denemesi (throttle:auth).
        'auth' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 10),
    ],

    // PAT (personal access token) yetenek kümesi. '*' tam yetki, 'read' yalnız
    // okuma (salt GET), 'write' okuma + işlemler.
    'token_abilities' => ['*', 'read', 'write'],

    // Oluşturulan erişim anahtarlarının geçerlilik süresi (gün). 0 = süresiz.
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 90),

    // Giriş ekranının ürettiği oturum anahtarları (name='auth'). Çıkış yapılmazsa
    // bile süre dolunca kendiliğinden düşer; süresi dolan kayıtlar
    // `php artisan sanctum:prune-expired --hours=24` ile temizlenir .
    'session_token_ttl_days' => (int) env('API_SESSION_TOKEN_TTL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | API dokümanı (/api-docs) — Faz 5
    |--------------------------------------------------------------------------
    | OpenAPI belgesi Scramble ile ÜRETİLİR (yalnız dev bağımlılığı), yayınlanan
    | paket `composer install --no-dev` aldığı için sunucuda Scramble YOKTUR.
    | Bu yüzden belge her ortamda STATIK artifact olarak sunulur: local'da
    | `npm run openapi` üretir (public/docs/api.json + postman_collection.json +
    | index.html), yayın paketinde bu dosyalar zip ile taşınır.
    | Erişim: `enabled` kapalıysa uçlar 404 döner (varsayılan: yalnız APP_ENV=local).
    | Yayında açacaksan MRC_DOCS_KEY ver ve bağlantıyı yalnız kendi ekibine aç
    | (?key=... ile veya X-Docs-Key başlığıyla girilir); anahtar boşsa giriş yapmış
    | kullanıcı yeterlidir.
    */
    'docs' => [
        'enabled' => filter_var(env('MRC_DOCS_ENABLED', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOLEAN),

        // Statik artifact'ların dizini (public altında; public/build gibi üretilir, commit edilmez).
        'path' => env('MRC_DOCS_PATH', 'docs'),

        // Boşsa oturum açmış kullanıcı yeterlidir; doluysa ?key=<değer> istenir.
        'key' => env('MRC_DOCS_KEY', ''),
    ],
];
