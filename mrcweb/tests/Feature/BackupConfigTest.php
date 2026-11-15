<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Yedek yapılandırmasının sözleşmesi .
 *
 * Bu testler "yedek var sanıp yedeğin olmaması" sınıfı hataları kilitler:
 * - Spatie'nin döküm motoru (mysqldump binary) KULLANILMaz → source.databases boş;
 *   dökümü `php artisan db:dump-php` (saf PHP) yapar ve zip'e dosya olarak girer.
 * - Zip webroot dışındaki `backups` diskine yazılır (tarayıcıdan inemez) ve
 *   kendi kendini yedeklemez.
 * - Zamanlama dört adımın sırasını korur (döküm → zip → kota → denetim).
 */
class BackupConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_dokum_binary_kullanimaz_source_databases_bos_birakilir(): void
    {
        // config/backup.php'de databases boş olmalı: paketin döküm motoru
        // spatie/db-dumper → mysqldump binary'si çağırır, paylaşımlı hostta güvenilmez.
        $this->assertSame(
            [],
            config('backup.backup.source.databases'),
            'Spatie veritabanı dökümü kapalı kalmalı (db:dump-php kullanılıyor)'
        );
    }

    public function test_dokum_dizini_yedek_kaynaginda_zip_hedefi_haricinda(): void
    {
        $include = config('backup.backup.source.files.include');
        $exclude = config('backup.backup.source.files.exclude');

        // db:dump-php çıktısı zip'e girmeli, yoksa veritabanı yedeksiz kalır.
        $this->assertContains(storage_path('app/db-dumps'), $include);
        // Kullanıcı yüklemeleri (firma logoları).
        $this->assertContains(storage_path('app/public'), $include);
        // Yapılandırma da kaybolmasın (sunucu silinirse .env yeniden kurulumu kolaylaştırır).
        $this->assertContains(base_path('.env'), $include);

        // Yedekin kendisi ve geçici dizini yedeğe girmemeli (kendi kendini yedekleme).
        $this->assertContains(storage_path('app/backups'), $exclude);
        $this->assertContains(storage_path('app/backup-temp'), $exclude);
        $this->assertContains(base_path('vendor'), $exclude);

        // public/storage symlink'i izlenmez: hedef zaten include listesinde.
        $this->assertFalse(config('backup.backup.source.files.follow_links'));
    }

    public function test_zip_webroot_disindaki_backups_diskine_yazilir(): void
    {
        $this->assertSame(['backups'], config('backup.backup.destination.disks'));

        $disk = config('filesystems.disks.backups');
        $this->assertIsArray($disk, 'backups diski tanımlı olmalı');
        $this->assertSame('local', $disk['driver']);

        $kok = (string) ($disk['root'] ?? '');
        $this->assertStringContainsString(storage_path('app'), $kok);
        $this->assertStringNotContainsString(public_path(), $kok, 'Yedek webroot içinde olmamalı');
        $this->assertSame('private', $disk['visibility'] ?? 'private');

        // ⚠️ Zip adı ÖNEKSİZ olmalı: Spatie adı `Y-m-d-H-i-s.zip` kalıbıyla çözer,
        // önek konursa tarih çözümlemesi bozulup dosya mtimesine düşüyor (ölçüldü).
        $this->assertSame('', (string) config('backup.backup.destination.filename_prefix'),
            'filename_prefix boş kalmalı; yedek tarihi dosya adından okunabilsin');
    }

    public function test_yedek_ayarlari_mrc_degerleriyle_dolu(): void
    {
        $mrc = config('backup.mrc');

        $this->assertIsArray($mrc, 'config/backup.php içinde mrc bölümü olmalı');

        $dokum = $mrc['db_dump'] ?? null;
        $this->assertIsArray($dokum, 'mrc.db_dump bölümü olmalı');
        $this->assertStringContainsString('db-dumps', (string) $dokum['dir']);
        $this->assertNotSame('', (string) $dokum['prefix']);
        $this->assertGreaterThan(0, (int) $dokum['min_free_mb'], 'disk doluyken yarım döküm üretilmemeli');

        // Saklama kuralı (kullanıcı kararı): günlük yedek, 7 günlük kayan pencere.
        // Tek anahtar hem dökümleri hem zip'leri yönetir.
        $this->assertSame(7, (int) $mrc['keep_days'], '7 günlük kayan saklama penceresi');

        // Zip temizliğini kendi stratejimiz yapar: paketin DefaultStrategy'si
        // kademeli arşiv tuttuğu (haftanın biri, ayın biri...) için istenmiyor.
        $this->assertSame(
            \App\Backup\KeepLastNDaysStrategy::class,
            config('backup.cleanup.strategy'),
            'saklama stratejisi MRC stratejisi olmalı'
        );

        // Kota eşikleri: denetim eşiği, temizleme eşiğinden yüksek olmalı ki
        // temizleme çalıştığını göstermeden uyarı gelmesin.
        $temizlik = (int) config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than');
        $izleme = config('backup.monitor_backups')[0]['health_checks'][\Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class] ?? null;

        $this->assertGreaterThan(0, $temizlik);
        $this->assertIsInt($izleme);
        $this->assertGreaterThanOrEqual($temizlik, (int) $izleme);

        // Denetim diski, yedeğin yazıldığı diskle aynı olmalı (yoksa "yedek yok" der).
        $this->assertSame(config('backup.backup.destination.disks'), config('backup.monitor_backups')[0]['disks']);
    }

    public function test_bildirimler_yalniz_kotu_haberler_icin_kanal_acar(): void
    {
        $bildirim = config('backup.notifications.notifications');

        // Spatie v9: olay sınıfları haritadan SİLINMEZ (silinirse başarılı yedekte
        // NotificationCouldNotBeSent fırlar ve komut başarısız sayılır). Başarı
        // olayları haritada kalır, kanal listesi boş bırakılır.
        foreach (
            [
                \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class,
                \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class,
                \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class,
                \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class,
                \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class,
                \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class,
            ] as $sinif
        ) {
            $this->assertArrayHasKey($sinif, $bildirim, 'haritada kalmalı: '.$sinif);
        }

        foreach (
            [
                \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class,
                \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class,
                \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class,
            ] as $sinif
        ) {
            $this->assertSame([], $bildirim[$sinif], 'başarı bildirimi kapalı olmalı: '.$sinif);
        }
    }

    /**
     * En kritik kilit: Spatie'nin kendi Config nesnesi ÇÖZÜMLENEBİLİR olmalı.
     *
     * Boş/eksik BACKUP_NOTIFY_EMAIL, v9'da `InvalidConfig: is not a valid email
     * address.` üretir ve bu hata yedeğin kendisini (backup:run / backup:clean /
     * schedule:list) çalışmaz hale getirir. Test, bildirim kapalıyken bile
     * yapılandırmanın geçerli kaldığını kanıtlar.
     */
    public function test_spatie_yedek_yapilandirmasi_hata_firlatmadan_cozumlenir(): void
    {
        $config = app(\Spatie\Backup\Config\Config::class);

        $this->assertInstanceOf(\Spatie\Backup\Config\Config::class, $config);

        // mail.to her durumda geçerli bir e-posta olmalı (boş dize Spatie'de yasak)
        $this->assertNotFalse(filter_var($config->notifications->mail->to, FILTER_VALIDATE_EMAIL));

        // bildirim adresi tanımsızsa kanal listesi boş olmalı (kimseye mail gitmez)
        if (trim((string) env('BACKUP_NOTIFY_EMAIL', '')) === '') {
            $this->assertSame([], $config->notifications->notifications[\Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class]);
        }
    }

    public function test_zamanlama_dort_yedek_adimini_sirayla_icerir(): void
    {
        Artisan::call('schedule:list');
        $cikti = Artisan::output();

        foreach (['db:dump-php', 'backup:run', 'backup:clean', 'backup:monitor', 'sanctum:prune-expired'] as $komut) {
            $this->assertStringContainsString($komut, $cikti, 'zamanlamada eksik: '.$komut);
        }
    }
}
