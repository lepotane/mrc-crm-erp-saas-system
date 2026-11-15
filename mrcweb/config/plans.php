<?php

/*
|--------------------------------------------------------------------------
| Abonelik planları ve firmaya bağlı limitler 
|--------------------------------------------------------------------------
|
| Neden burada: limitler kodun içine gömülürse her fiyat değişikliğinde deploy
| gerekir; ayrıca limit hangi uçta denetleniyorsa orada AYNI sayı okunmalı
| (belge, davet, ödeme linki, ürün). TEK kaynak bu dosya.
|
| `MRC_PLAN` firmanın başlangıç planıdır (local dev için `standart` bol gelir;
| prod'da `ucretsiz` bırakılıp satışta yükseltilir). Plan DEĞİŞTİRMEK bu sürümde
| API ile değil sunucu konfigürasyonu ile olur — ödeme altyapısı (PayTR/iyzico)
| kendi abonelik tahsilatını ayrı bir iş olarak tutar (§9.2 risk listesi).
|
| Limitler takvim ayı bazındadır (`documents_per_month`, `payment_links_per_month`);
| `users` ve `products` toplam sayıdır. `null` = limitsiz.
|
| Not: `users` sayımına bekleyen davetler de dahildir — aksi halde 2 kişilik planda
| 9 davet gönderilip sonra kabul ettirilemezdi (limit dolan bir kapıdan aşılır).
*/

return [

    'default' => env('MRC_PLAN', 'standart'),

    'plans' => [

        'ucretsiz' => [
            'label' => 'Ücretsiz',
            'limits' => [
                'users' => 2,
                'products' => 100,
                'documents_per_month' => 50,
                'payment_links_per_month' => 10,
            ],
        ],

        'standart' => [
            'label' => 'Standart',
            'limits' => [
                'users' => 10,
                'products' => 2000,
                'documents_per_month' => 2000,
                'payment_links_per_month' => 300,
            ],
        ],

        'pro' => [
            'label' => 'Pro',
            'limits' => [
                'users' => 50,
                'products' => 20000,
                'documents_per_month' => 20000,
                'payment_links_per_month' => 3000,
            ],
        ],

        // Sınırsız kanton: kiraya verme testleri + iç kullanım (MRC_PLAN=sinirsiz).
        'sinirsiz' => [
            'label' => 'Sınırsız',
            'limits' => [
                'users' => null,
                'products' => null,
                'documents_per_month' => null,
                'payment_links_per_month' => null,
            ],
        ],
    ],

    /*
    | Bir kaynak limitine çarpıldığında kullanıcıya gösterilecek mesaj anahtarı.
    | Çeviri zorunlu (§13.3): hata gövdesi daima kullanıcının dilinde olmalı.
    */
    'messages' => [
        'users' => 'Bu planın kullanıcı limitine ulaştınız (:usage/:limit). Yeni üye davet edemezsiniz.',
        'products' => 'Bu planın ürün limitine ulaştınız (:usage/:limit).',
        'documents_per_month' => 'Bu ayki belge limitine ulaştınız (:usage/:limit). Ayın :day gününde limit yenilenir.',
        'payment_links_per_month' => 'Bu ayki ödeme linki limitine ulaştınız (:usage/:limit).',
    ],
];
