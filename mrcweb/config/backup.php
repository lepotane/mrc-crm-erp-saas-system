<?php

/*
 |--------------------------------------------------------------------------
 | Bildirim adresi  — Spatie v9 tuzakları nedeniyle burada hesaplanır
 |--------------------------------------------------------------------------
 | İki kural, ikisi de canlı testte gerçek hata olarak görüldü:
 |
 |  1) `notifications.mail.to` boş ya da geçersiz OLAMAZ: aksi halde
 |     `InvalidConfig:  is not a valid email address.` hatası yedeğin KENDİSİNİ
 |     de çalışmaz hale getirir (backup:run / backup:clean / schedule:list).
 |  2) Bildirim sınıfları haritadan SİLİNMEZ; istemiyorsak kanal listesi `[]`
 |     bırakılır. Sınıf silinirse başarılı yedekte `NotificationCouldNotBeSent`
 |     fırlar ve yedek başarılı olduğu halde komut başarısız sayılır.
 |
 | Sonuç: BACKUP_NOTIFY_EMAIL tanımlı VE GEÇERLİYSE yalnız arıza bildirimleri
 | maillenir; tanımsızsa hiçbir mail gitmez (doğulamayı geçen bir yer tutucu
 | adres yazılır, kanal listesi boş kalır). config:cache bu satırları bir kez
 | değerlendirir; bu yüzden .env değişikliği sonrası `php artisan config:clear`.
 */
$bildirimAdresi = trim((string) env('BACKUP_NOTIFY_EMAIL', ''));
$adresGecerli = filter_var($bildirimAdresi, FILTER_VALIDATE_EMAIL) !== false;

$mailKanallari = $adresGecerli ? ['mail'] : [];
$mailAlicisi = $adresGecerli ? $bildirimAdresi : 'yedek@mrc.local';

return [

    /*
    |--------------------------------------------------------------------------
    | Yedek 
    |--------------------------------------------------------------------------
    | Paylaşımlı cPanel hostingde `mysqldump` binary'si güvenilmezdir (PHP CLI
    | sürümü ile PATH uyumsuzluğu, `exec`/`shell_exec` kapalı olabilir). Bu yüzden
    | veritabanı dökümünü kendimiz yapıyoruz:
    |
    |   1) `php artisan db:dump-php`  → ifsnop/mysqldump-php (saf PHP, binary/exec
    |      gerektirmez) ile storage/app/db-dumps/mrc-*.sql.gz üretir.
    |   2) `php artisan backup:run`   → Spatie yalnız DOSYA yedeği yapar: yüklenen
    |      dosyalar (logolar) + 1)deki dökümler + .env → tek zip, storage/app/backups.
    |
    | Bu yüzden aşağıdaki `source.databases` BOŞTUR: Spatie'nin döküm motoru
    | (spatie/db-dumper → mysqldump binary) kullanılmaz.
    |
    | Zamanlama routes/console.php içinde: 02:00 db:dump-php · 02:30 backup:run ·
    | 03:00 backup:clean · 03:30 backup:monitor.
    */

    'backup' => [
        /*
         * Yedeğin adı (izleme/raporlama için).
         */
        'name' => env('APP_NAME', 'MRC'),

        'source' => [
            'files' => [
                /*
                 * Yedeğe girecek kaynaklar: kullanıcı yüklemeleri (firma logoları),
                 * db:dump-php çıktısı ve .env (sunucu silinirse yapılandırma da
                 * kaybolmasın). Uygulama KODU yedeğe alınmaz: kod depodan/zip'ten
                 * geliyor; vendor ve node_modules devasa yer kaplardı.
                 */
                'include' => [
                    storage_path('app/public'),
                    storage_path('app/db-dumps'),
                    base_path('.env'),
                ],

                /*
                 * Bunlar dışarıda: geçici/üretilmiş dizinler ve yedekin kendisi
                 * (kendi kendini yedeklemesin).
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('app/backup-temp'),
                    storage_path('app/backups'),
                    storage_path('framework'),
                    storage_path('logs'),
                    public_path('build'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 * public/storage → storage/app/public symlink'i izlenmez; hedef
                 * dizin zaten source'ta var (iki kez yazılmaz).
                 */
                'follow_links' => false,

                /*
                 * Okunamayan klasörlerde yedeğin patlamasını engelle (paylaşımlı
                 * hostta komşu hesap klasörleri görünebiliyor).
                 */
                'ignore_unreadable_directories' => true,

                'relative_path' => base_path(),
            ],

            /*
             * BOŞ: veritabanı dökümü `php artisan db:dump-php` ile yapılır
             * (mysqldump binary'si paylaşımlı hostta güvenilmez), çıkan .sql.gz
             * dosyası yukarıdaki include listesi üzerinden zip'e girer.
             */
            'databases' => [
            ],
        ],

        /*
         * Yukarıdaki `source.databases` boş olduğu için bu dört ayar İŞLEMEZ
         * (Spatie'nin döküm motoru hiç çalışmaz). Dokunmayın; dökümün adı,
         * sıkıştırması ve saklama süresi `php artisan db:dump-php` içinde tutulur.
         */
        'database_dump_compressor' => null,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            /*
             * Zip sıkıştırması: içeriğin büyük kısmı zaten .sql.gz (gzip'li döküm),
             * bu yüzden 9 yerine 6 — paylaşımlı hostta CPU/uzun yürütme (max_execution_time)
             * riskini azaltır, dosya boyutu hemen hemen aynı kalır.
             */
            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 6,

            /*
             * Zip adı: 2026-10-31-02-30-00.zip ( klasörü zaten MRC/ → kimlik orada).
             *
             * ⚠️ ÖNEK VERMEYİN (ölçüldü, 31 Eki 2026): Spatie dosya adını
             * `Y-m-d-H-i-s.zip` kalıbıyla çözümler; başına `mrc-` gibi önek konursa
             * çözümleme başarısız olur ve paket DOSYA MTİMESİNE düşer → "bu yedek
             * hangi günün?" cevabı dosya kopyalandığında kaybolur. Saklama
             * stratejimiz (KeepLastNDaysStrategy) tarihi addan kendisi okur, ama
             * `backup:monitor` ve paketin kendi stratejisi ada muhtaçtır.
             */
            'filename_prefix' => (string) env('BACKUP_FILENAME_PREFIX', ''),

            /*
             * Zip'lerin yazıldığı disk: `backups` (config/filesystems.php) →
             * storage/app/backups. Burası webroot dışı ve yedek source'unda
             * 'exclude' içinde; zip kendi kendini yedeklemez.
             */
            'disks' => [
                'backups',
            ],
        ],

        /*
         * Zip hazırlanırken kullanılan geçici dizin (source'ta exclude içinde).
         */
        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * Şifreli zip istenirse .env'de BACKUP_ARCHIVE_PASSWORD tanımlanır; boş
         * bırakılırsa şifreleme yok (restore sırasında şifre aranamaz).
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        /*
         * Tek deneme: paylaşımlı hostta yarım kalmış işi üst üste bindirmek
         * yerine ertesi geceki zamanlamayı beklemek daha güvenli.
         */
        'tries' => 1,

        'retry_delay' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bildirimler
    |--------------------------------------------------------------------------
    | Yalnız KÖTÜ haberler gönderilir (başarılı yedek postası zamanla çöp olur ve
    | gerçek arıza sesinin arasında kaybolur). Kanal listesi dosyanın başındaki
    | `$mailKanallari` değerinden gelir: BACKUP_NOTIFY_EMAIL boşsa hiçbir kanal
    | yok → mail gitmez, ama harita bozulmadığı için yedek çalışmaya devam eder.
    */
    'notifications' => [
        'notifications' => [
            \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class => $mailKanallari,
            \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class => $mailKanallari,
            \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class => $mailKanallari,

            // Başarı olayları haritada KALIR (silerse Spatie v9 istisna fırlatır),
            // kanalları boş bırakılır → bildirim gönderilmez.
            \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class => [],
            \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class => [],
            \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => \Spatie\Backup\Notifications\Notifiable::class,

        'mail' => [
            // cPanel kurulumunda .env'de BACKUP_NOTIFY_EMAIL doldurulur
            // (ör. muhasebe@firma.test). Doldurulmazsa yer tutucu adrese asla
            // mail gitmez: kanal listesi boş.
            'to' => $mailAlicisi,

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        /*
         * Slack/Discord kullanılmıyor (webhook boş → bildirim gönderilmez).
         * Entegrasyon istenirse laravel/slack-notification-channel kurulur.
         */
        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sağlık denetimi (`php artisan backup:monitor`, 03:30)
    |--------------------------------------------------------------------------
    | Amaç: "yedek alınıyor sanarken aslında alınmamış olması" yakalanır.
    | - MaximumAgeInDays 2: yedek 2 günden eskiyse uyarı (cron köprüsü bir gece
    |   kaçarsa diye 1 gün yerine 2 gün tolerans; sunucu saat dilimi farkı da
    |   bu payın içinde erir).
    | - MaximumStorageInMegabytes 1500: temizleme eşiğinin (aşağıda 1000 MB)
    |   üstünde kalıyorsa temizleme çalışmıyor demektir → uyarı.
    */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'MRC'),
            'disks' => ['backups'],
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => (int) env('BACKUP_MAX_AGE_DAYS', 2),
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => (int) env('BACKUP_MONITOR_MAX_MB', 1500),
            ],
        ],
    ],

    'cleanup' => [
        /*
         * Saklama kuralı (kullanıcı kararı, 31 Eki 2026): yedek GÜNLÜK alınır,
         * 7 GÜNLÜK kayan pencere saklanır — 8. gün 1. günün dosyası silinir,
         * yeni günün dosyası yazılır. Bu davranış kendi stratejimizde:
         * `App\Backup\KeepLastNDaysStrategy` (gün sayısı `backup.mrc.keep_days`).
         *
         * Paketin DefaultStrategy'si KADEMELİ arşiv tutar (7 gün hepsi → 16 gün
         * günde bir → 8 hafta haftada bir → ay → yıl); yani 7. günden eski
         * yedekleri de saklamaya devam ederdi. Kademeli arşiv istemiyoruz.
         */
        'strategy' => \App\Backup\KeepLastNDaysStrategy::class,

        /*
         * Bu sayılar kendi stratejimiz tarafından OKUNMAZ; anahtarlar yine de
         * tanımlı olmalı (paket yapılandırmayı her koşulda çözümler). Paketin
         * DefaultStrategy'sine dönülürse aynı kuralı versin diye 7 gün + sonrası
         * sıfır olarak tutulur.
         */
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 0,
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 0,
            'keep_yearly_backups_for_years' => 0,

            /*
             * Paylaşımlı hosting disk kotası: yaş kuralı yetersiz kalırsa (yedekler
             * büyümüşse) en eskiden başlayarak bu eşiğin altına inilir. 0 = kapalı.
             * Kendi stratejimiz de bu anahtarı kullanır.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_CLEANUP_MAX_MB', 1000),
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | MRC'ye özel ayarlar (Spatie bu bölümü OKUMAZ)
    |--------------------------------------------------------------------------
    | Paket yalnız backup / notifications / monitor_backups / cleanup anahtarlarını
    | kullanır; dökümü ve saklama kuralını kendimiz yönettiğimiz için kendi
    | değerlerimiz ayrı durur. `keep_days` İKİSİ İÇİN DE tek anahtardır:
    | `db:dump-php` dökümleri ve `backup:clean` zip'leri aynı pencereyi paylaşır.
    */
    'mrc' => [
        // Kaç günlük yedek saklanacağı (8. gün 1. günün dosyası silinir). 0 = hiç silme.
        'keep_days' => (int) env('MRC_BACKUP_KEEP_DAYS', 7),

        'db_dump' => [
            // Dökümlerin yazıldığı dizin (webroot dışı). backup:run bu dizini zip'e katar.
            'dir' => storage_path('app/db-dumps'),

            // Döküm dosya adı: {prefix}-Y-m-d-His.sql.gz (biz kendi dökümümüzü kendi
            // adımızla yazıyoruz; Spatie'nin ad çözümlemesine bağımlı değil).
            'prefix' => (string) env('MRC_DB_DUMP_PREFIX', 'mrc'),

            // Bundan az boş yer varsa döküm üretilmez (yarım dosya yerine erken hata).
            'min_free_mb' => (int) env('MRC_DB_DUMP_MIN_FREE_MB', 100),
        ],
    ],

];
