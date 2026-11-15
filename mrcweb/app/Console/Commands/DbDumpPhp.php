<?php

namespace App\Console\Commands;

use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * Veritabanı dökümü: `mysqldump` binary'si OLMADAN, saf PHP ile .
 *
 * Neden binary değil: paylaşımlı cPanel hostingde `shell_exec`/`exec` kapalı
 * olabiliyor ve PATH'teki php ile cron'daki php farklı sürücüde olabiliyor;
 * `mysqldump` bulunsa bile yanlış PHP sürümüyle çağrılıp sessizce başarısız
 * oluyor. ifsnop/mysqldump-php PDO ile bağlanır, binary ve `exec` istemez.
 * Spatie'nin kendi döküm motoru (spatie/db-dumper) binary kullandığı için
 * config/backup.php'de `source.databases` BOŞ bırakıldı; buradaki dosya zip'e girer.
 *
 * Çıktı: storage/app/db-dumps/mrc-2026-10-31-020000.sql.gz (gzip'li, webroot dışı).
 * Zamanlama routes/console.php içinde (02:00), zip yedeği 02:30'da bu dizini okur.
 *
 * Saklama kuralı (kullanıcı kararı 31 Eki 2026): günlük alınır, 7 günlük kayan
 * pencere tutulur — 8. gün 1. günün dosyası silinir, yenisi yazılır. Aynı gün
 * sayısı zip yedekleri için de geçerlidir (`App\Backup\KeepLastNDaysStrategy`);
 * tek anahtar: `backup.mrc.keep_days` (.env: MRC_BACKUP_KEEP_DAYS).
 *
 * Kullanım:
 *   php artisan db:dump-php                 // tek döküm + 7 günden eskisini sil
 *   php artisan db:dump-php --keep=30       // 30 günden eskisini sil
 *   php artisan db:dump-php --keep=0        // hiçbir dökümü silme
 *   php artisan db:dump-php --connection=mysql --dir=storage/app/db-dumps
 */
class DbDumpPhp extends Command
{
    protected $signature = 'db:dump-php
        {--keep= : Bu günden eski dökümler silinir (0 = hiç silme; varsayılan: MRC_BACKUP_KEEP_DAYS = 7 gün)}
        {--connection= : Dökülecek veritabanı bağlantısı (varsayılan: config database.default)}
        {--dir= : Döküm dizini (varsayılan: config backup.mrc.db_dump.dir)}';

    protected $description = 'Veritabanını saf PHP ile dökümler: storage/app/db-dumps/mrc-*.sql.gz (mysqldump binary gerekmez)';

    public function handle(): int
    {
        $baglanti = (string) ($this->option('connection') ?: config('database.default'));
        $ayar = config('database.connections.'.$baglanti);

        if (! is_array($ayar)) {
            $this->error('Bilinmeyen veritabanı bağlantısı: '.$baglanti);

            return self::FAILURE;
        }

        $dizin = (string) ($this->option('dir') ?: config('backup.mrc.db_dump.dir', storage_path('app/db-dumps')));
        $keep = $this->option('keep') !== null
            ? (int) $this->option('keep')
            : (int) config('backup.mrc.keep_days', 7);

        // 1) Önce budama: paylaşımlı hostta dökümün en sık görülme nedeni disk
        //    kotasıdır. Yer açmadan yazmaya çalışmak dökümü ORTASINDA keser,
        //    yani elinde ne eski ne kullanılır bir yedek kalır.
        $silinen = $this->eskiDokumleriSil($dizin, $keep);

        if ($silinen > 0) {
            $this->line($silinen.' eski döküm silindi (keep='.$keep.' gün).');
        }

        // 2) Sürücü kontrolü: testler sqlite ile çalışır; sqlite dökümü bu
        //    komutun işi değil (yalnız üretim MySQL/MariaDB için yazıldı).
        $surucu = (string) ($ayar['driver'] ?? '');

        if (! in_array($surucu, ['mysql', 'mariadb'], true)) {
            $this->warn(sprintf(
                'db:dump-php atlandı: "%s" bağlantısının sürücüsü "%s". Yalnız mysql/mariadb dökülür.',
                $baglanti,
                $surucu,
            ));
            $this->warn('Üretimde DB_CONNECTION=mysql olmalıdır.');

            return self::SUCCESS;
        }

        if (! $this->dizinKaydet($dizin)) {
            return self::FAILURE;
        }

        if (! $this->diskKontrol($dizin)) {
            return self::FAILURE;
        }

        $dosya = $this->dosyaYolu($dizin);
        $baslangic = microtime(true);

        try {
            $this->dokum($dosya, $baglanti, $ayar);
        } catch (Throwable $e) {
            // Yarım kalmış dosya geri yüklemede kullanılamaz; yanıltmasın diye silinir.
            if (is_file($dosya)) {
                @unlink($dosya);
            }

            $this->error('Döküm başarısız: '.$e->getMessage());
            $this->line('Kontrol listesi: DB_* anahtarları doğru mu · veritabanı var mı · diskte yer var mı.');

            return self::FAILURE;
        }

        $boyut = is_file($dosya) ? (int) filesize($dosya) : 0;

        if ($boyut <= 0) {
            $this->error('Döküm tamamlandı ama dosya boş görünüyor: '.$dosya);
            @unlink($dosya);

            return self::FAILURE;
        }

        $saklanan = $this->dokumListesi($dizin);

        $this->info('Veritabanı dökümü alındı.');
        $this->table(['Alan', 'Değer'], [
            ['Bağlantı', $baglanti.' ('.$ayar['database'].')'],
            ['Dosya', str_replace(base_path().DIRECTORY_SEPARATOR, '', $dosya)],
            ['Boyut', $this->insaniBoyut($boyut).' (gzip)'],
            ['Süre', number_format(microtime(true) - $baslangic, 1).' sn'],
            ['Dizindeki döküm', count($saklanan).' adet'.($saklanan === [] ? '' : ' · en yenisi '.basename((string) end($saklanan)))],
        ]);

        $this->line('');
        $this->line('Geri yükleme (phpMyAdmin içe aktarma .sql.gz dosyasını doğrudan alır):');
        $this->line('  gunzip -c '.$dosya.' | mysql -u '.($ayar['username'] ?? 'kullanici').' -p '.($ayar['database'] ?? 'veritabani'));

        return self::SUCCESS;
    }

    /**
     * Dökümü PDO üzerinden yapar; binary/exec kullanmaz.
     *
     * @param  array<string, mixed>  $ayar  config database.connections kaydı
     */
    private function dokum(string $dosya, string $baglanti, array $ayar): void
    {
        $dsn = $this->dsnKur($baglanti, $ayar);

        $dump = new Mysqldump(
            $dsn,
            (string) ($ayar['username'] ?? ''),
            (string) ($ayar['password'] ?? ''),
            [
                // Çıktı gzip'li yazılır: döküm zip'in içinde de sıkıştırılacak,
                // ama tek başına taşınabilir/okunabilir olması için burada da gzip.
                'compress' => Mysqldump::GZIP,
                // Geri yükleme sırasında eski tablolar temiz düşsün.
                'add-drop-table' => true,
                // InnoDB için kilit yerine tutarlı anlık görüntü (canlı sitede yazma
                // bloklanmasın); paket zaten tek işlem içinde başlatır.
                'single-transaction' => true,
                'default-character-set' => 'utf8mb4',
                // Tablo/veri sayısı büyürse bellek limitine takılmamak için satır satır.
                'extended-insert' => true,
            ],
            [
                // Kalıcı bağlantı cron köprüsünde sarkabilir; açıkça kapalı.
                PDO::ATTR_PERSISTENT => false,
            ],
        );

        $dump->start($dosya);
    }

    /**
     * config/database.php ayarından PDO DSN üretir (unix_socket verildiyse onu kullanır).
     *
     * @param  array<string, mixed>  $ayar
     */
    private function dsnKur(string $baglanti, array $ayar): string
    {
        $dsn = $baglanti === 'mysql' || $baglanti === 'mariadb' ? 'mysql' : $baglanti;
        $dsn .= ':';

        if (! empty($ayar['unix_socket'])) {
            $dsn .= 'unix_socket='.$ayar['unix_socket'].';';
        } else {
            $dsn .= 'host='.($ayar['host'] ?? '127.0.0.1').';';

            if (! empty($ayar['port'])) {
                $dsn .= 'port='.$ayar['port'].';';
            }
        }

        $dsn .= 'dbname='.$ayar['database'].';charset=utf8mb4';

        return $dsn;
    }

    /**
     * Dosya adı: {prefix}-Y-m-d-His.sql.gz (saniye de var: aynı gece iki döküm
     * alınırsa biri diğerinin üstüne yazmasın).
     */
    private function dosyaYolu(string $dizin): string
    {
        $prefix = (string) config('backup.mrc.db_dump.prefix', 'mrc');

        return rtrim($dizin, '/\\').DIRECTORY_SEPARATOR
            .$prefix.'-'.now()->format('Y-m-d-His').'.sql.gz';
    }

    /**
     * keep günden eski dökümleri siler; silinen dosya sayısını döndürür.
     * keep <= 0 ise hiçbir şey silmez. Adlandırma değişse de (*.sql.gz olduğu
     * sürece) eski artıklar temizlenir; yeni döküm o gün yazıldığı için etkilenmez.
     */
    private function eskiDokumleriSil(string $dizin, int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $sinir = time() - ($keep * 86400);
        $silinen = 0;

        foreach ($this->dokumListesi($dizin) as $dosya) {
            if (filemtime((string) $dosya) < $sinir && @unlink((string) $dosya)) {
                $silinen++;
            }
        }

        return $silinen;
    }

    /**
     * Dizindeki dökümler, eskiden yeniye.
     *
     * @return list<string>
     */
    private function dokumListesi(string $dizin): array
    {
        if (! is_dir($dizin)) {
            return [];
        }

        $list = glob(rtrim($dizin, '/\\').DIRECTORY_SEPARATOR.'*.sql.gz') ?: [];
        sort($list);

        return array_values($list);
    }

    /**
     * Döküm dizini yoksa oluşturur; yazılamıyorsa anlaşılır hata verir.
     */
    private function dizinKaydet(string $dizin): bool
    {
        if (! is_dir($dizin) && ! @mkdir($dizin, 0o775, true) && ! is_dir($dizin)) {
            $this->error('Döküm dizini oluşturulamadı: '.$dizin);
            $this->line('cPanel dosya yöneticisinden bu klasörü açın ve yazma izni verin (755).');

            return false;
        }

        if (! is_writable($dizin)) {
            $this->error('Döküm dizinine yazılamıyor: '.$dizin);

            return false;
        }

        return true;
    }

    /**
     * Disk boşluğu kontrolü: yarım dosya üretmek yerine erken ve net durur.
     * (Sunucu boşluk bilgisini veremiyorsa kontrol atlanır, döküm engellenmez.)
     */
    private function diskKontrol(string $dizin): bool
    {
        $istenenMb = (int) config('backup.mrc.db_dump.min_free_mb', 100);

        if ($istenenMb <= 0) {
            return true;
        }

        // Sunucu disk bilgisini vermiyorsa (disable_functions ya da tanımsız
        // işlev — bazı paylaşımlı hostlarda disk_free_space DERLENMEMİŞTİR)
        // kontrol atlanır, döküm engellenmez.
        if (! function_exists('disk_free_space')) {
            $this->warn('Disk işlevi yok; boşluk kontrolü atlandı.');

            return true;
        }

        $bosluk = @disk_free_space($dizin);

        if ($bosluk === false) {
            $this->warn('Disk boşluğu okunamadı; kontrol atlandı.');

            return true;
        }

        if ($bosluk < $istenenMb * 1024 * 1024) {
            $this->error(sprintf(
                'Diskte yedek için yeterli yer yok: %s boş, en az %d MB gerekli. Eski dökümleri silin (--keep=2) ya da MRC_DB_DUMP_MIN_FREE_MB düşürün.',
                $this->insaniBoyut((int) $bosluk),
                $istenenMb,
            ));

            return false;
        }

        return true;
    }

    private function insaniBoyut(int $bayt): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $birim) {
            if ($bayt < 1024 || $birim === 'GB') {
                return round($bayt, 1).' '.$birim;
            }

            $bayt /= 1024;
        }

        return $bayt.' B';
    }
}
