<?php

namespace App\Support;

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

/**
 * OpenAPI güvenlik belgelenmesi: `auth:sanctum` takili her uç kök düzey `security`
 * ile kapsanir, auth takili olmayanlar (kayit/giris) `security: []` olarak
 * işaretlenir.
 *
 * Scramble'in varsayilan semasi `http` adini alir ve açiklamasi boştur. Buradaki
 * tek katma değer, semanin ADI (bearerAuth) ve KULLANIM NOTU: masaüstü istemci ve
 * Postman belgeden okuduğunda anahtarın nereden üretildiğini, hangi başlıkla
 * gönderildiğini ve yetkisinin üretim anında sabitlendiğini görür.
 *
 * NEDEN sınıf-string: `config/scramble.php` production'da da okunur (Scramble
 * yoksa dosya `[]` döner) ve `config:cache` config değerlerini `var_export` ile
 * yazar. SecurityScheme NESNESİ cache'e yazılsa geri yükleme `__set_state`
 * hatasıyla çökerdi; bu yüzden config'te yalnız sınıf adı durur, nesne üretimin
 * sırasında burada kurulur.
 */
class ApiDocsSecurityStrategy extends MiddlewareAuthSecurityStrategy
{
    public function __construct(array $middleware = ['auth', 'auth:*'], ?SecurityScheme $scheme = null)
    {
        if ($scheme === null) {
            $scheme = SecurityScheme::http('bearer')->as('bearerAuth');

            $scheme->setDescription(
                'Ayarlar → API Erişim Anahtarları ekranından üretilen erişim anahtarı. '.
                'Başlık biçimi: Authorization: Bearer <anahtar>. Anahtarın yetkisi (abilities) '.
                'üretim anında sabitlenir; salt okuma anahtarıyla yazma isteği 403 döner.'
            );
        }

        parent::__construct($middleware, $scheme);
    }
}
