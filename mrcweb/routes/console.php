<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Zamanlanmış işler .
// cPanel'de tek cron satırı yeter: * * * * * php artisan schedule:run
// Saatler sunucu saat dilimine göredir (cPanel'de genelde UTC); gece
// trafiğinin en az olduğu aralığa göre .env ile kaydırılabilir.
Schedule::command('sanctum:prune-expired --hours=24')->daily(); // süresi düşen erişim anahtarı kayıtları
Schedule::command('currency:fetch')->dailyAt('04:00')->withoutOverlapping(); // V2-9: günlük kur (TCMB → yedek)

/*
 | Yedek zinciri (Mimari A): sıra önemli.
 |
 |  02:00 db:dump-php  → veritabanı dökümü, saf PHP (ifsnop/mysqldump-php):
 |                        storage/app/db-dumps/mrc-*.sql.gz. 14 günden eski
 |                        dökümleri kendisi siler (MRC_DB_DUMP_KEEP_DAYS).
 |  02:30 backup:run   → Spatie YALNIZ dosya yedeği: logolar + dökümler + .env
 |                        → storage/app/backups/mrc-*.zip (config/backup.php).
 |                        Dökümü zip'e aldığı için dökümden SONRA koşmalı.
 |  03:00 backup:clean → eski zip'leri siler (kota: 1000 MB üstü).
 |  03:30 backup:monitor → "yedek var mı" denetimi; 2 günden yeni yoksa ve
 |                        klasör 1500 MB'ı aştıysa BACKUP_NOTIFY_EMAIL'e yazar.
 |
 | spatie/laravel-backup v9'da backup:run temizleme/denetleme YAPMAZ; bu dört
 | satır bu yüzden ayrı. withoutOverlapping(): bir gece iş uzarsa aynı işin
 | ikinci kopyası binmez (paylaşımlı hostte iç içe cron en sık bozulma nedeni).
 */
Schedule::command('db:dump-php')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('bridge:prune')->dailyAt('04:30')->withoutOverlapping(); // V2-6: eski başarısız baskı işleri

