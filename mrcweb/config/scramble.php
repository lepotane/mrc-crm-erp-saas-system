<?php

/*
|--------------------------------------------------------------------------
| Scramble (OpenAPI ureteci) — YALNIZ gelistirme ortami
|--------------------------------------------------------------------------
| Yayin paketi `composer install --no-dev` ile alindigi icin sunucuda Scramble
| BULUNMAZ . Bu dosya config/ altinda durdugu
| icin cerceve onu her ortama yukler; sinif referanslari sunucuda "class not
| found" verirdi. Bu yuzden once Scramble'in kurulu olup olmadigi sorulur.
|
| OpenAPI belgesi local'da uretilir (`npm run openapi`) ve public/docs/ altindaki
| STATIK dosyalar olarak sunucuya tasinir; /api-docs uclari o ciktilari sunar.
| Sunucuda asagidaki degerlerin hicbiri okunmaz.
*/

if (! class_exists(\Dedoc\Scramble\Scramble::class)) {
    return [];
}

return [
    /*
     * Dokumantecek yollar. 'api' verince /api oneki silinip yollar /v1/... olarak
     * kalir, /api ise servers alanina tasinir (asagidaki 'servers'). Boylece
     * belgedeki her yol + sunucu adresi, sozlesmedeki taban yol ile (APP_URL/api/v1)
     * birebir ayni olur.
     */
    'api_path' => 'api',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     *
     * NOT: Bu dosya derleme akisinin araci: `npm run openapi` once buraya yazar,
     * sonra Postman koleksiyonunu ve okunabilir HTML'i buradan uretip
     * public/docs/ altina koyar. Sunucuda Scramble bulunmaz (composer install
     * --no-dev); doküman public/docs/ altindaki statik ciktilardan okunur.
     */
    'export_path' => 'docs/openapi/api.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    /*
     * OpenAPI version of the resulting document.
     */
    'openapi_version' => \Dedoc\Scramble\OpenApiVersion::V3_1,

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', '1.0.0'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         *
         * Sozlesmenin ozeti: ayrintili hal  ve
            `api` (entegrasyon/masaüstü istemci için üretilen, 90 gün). Yetenekler
            (`abilities`) üretim anında sabitlenir: `*` tam yetki, `write` okuma + işlem,
            `read` yalnız okuma — `read` anahtarıyla yazma istekleri
            `403 "Bu erişim anahtarının bu işlem için yetkisi yok."` ile reddedilir.

            **Hata gövdesi her zaman tek sözdizimdedir:** `{"message": "…"}`, alan
            hatalarında ek olarak `{"errors": {"alan": ["…"]}}`. `message` kullanıcının
            diline göre çevrilmiştir ve olduğu gibi gösterilebilir.

            **Firma bağlamı:** işlemler seçili firma bağlamında yürür
            (`POST /companies/select`). Başka firmanın kaydı **404** döner — kaydın
            varlığı bile paylaşılmaz.

            **Hız sınırları:** erişim anahtarı başına 120 istek/dk, IP başına sert tavan
            600 istek/dk, giriş/kayıt uçlarında IP + e-posta başına 10 deneme/dk.
            Aştığınızda `429` döner; `Retry-After` ve `X-RateLimit-*` başlıklarına bakın.
            Hız sınırı kimlik denetiminden **önce** çalışır; yanlış anahtarla yapılan
            denemeler de sayaca girer.

            **Rapor uçları salt okunurdur.** Taslak (`draft`) ve iptal edilmiş (`cancelled`)
            belgeler hiçbir raporda sayılmaz. Toplamlar para birimi bazında
            `by_currency` nesnesinde ayrıca verilir (`{}` boş küme olabilir).

            Bu belge `),
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    /*
     * OpenAPI `servers` alani. Scramble bu diziyi `aciklama => url` eslemesi olarak
     * okur (Generator::foreach ($servers as $description => $url)); bu yuzden burada
     * liste/nesne DEGIL, anahtari aciklama olan tek bir girdi vardir.
     *
     * Uclar `/v1/...` ile baslar (api_path 'api' oldugu icin /api oneki sunucuya tasinir),
     * boylece tam adres APP_URL + /api/v1/... olur — sozlesmedeki taban yol ile ayni.
     */
    'servers' => [
        'MRC kurulumu (APP_URL)' => rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api',
    ],

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    /*
     * Scramble'in kendi doküman uclarini (docs/api, docs/api.json) KAPATIYORUZ:
     * varsayilan zincir 'web' ortayazi (oturum/CSRF) kullanir ve biz dokümani
     * /api-docs altindaki kendi kontrolcumuzden sunuyoruz (config('api.docs') +
     * ApiDocsAccess). Yayinda Scramble zaten vendor'da yok.
     */
    'middleware' => [],

    'extensions' => [
        \App\Support\PaginatedParametersExtension::class,
    ],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */

    /*
     * Guvenlik belgelenmesi: `auth:sanctum` takili her uc belgede kok duzey
     * `security` ile kapsanir, auth takli olmayanlar (kayit/giris) `security: []`
     * olarak isaretlenir. Boylece masaustu istemci ve Postman hangi ucta
     * Authorization beklenedigini belgeden okur.
     *
     * NEDEN sinif-string (nesne degil): `config:cache` config degerlerini
     * `var_export` ile yazar; SecurityScheme NESNESI geri yuklenirken
     * `__set_state` hatasiyla cokerdi. Semanin adi/aciklamasi bu yuzden
     * App\Support\ApiDocsSecurityStrategy icinde, uretim aninda kurulur.
     */
    'security_strategy' => \App\Support\ApiDocsSecurityStrategy::class,
];
