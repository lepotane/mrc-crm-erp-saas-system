<?php

namespace App\Console\Commands;

use App\Models\PrintJob;
use Illuminate\Console\Command;

/**
 * YAZICI KÖPRÜSÜ TEMİZLİĞİ : 24 saatten eski `failed` işleri siler.
 *
 * `printed` işler zaten ACK anında silinir; burada kalan yalnız terminal
 * başarısızlardır. Kullanıcı o arada mobil PDF'e düşer (kayıp fiş yok:
 * belge durur, yeniden kuyruğa alınabilir).
 */
class BridgePruneCommand extends Command
{
    protected $signature = 'bridge:prune {--hours=24 : Kaç saatten eski başarısız işler silinsin}';

    protected $description = 'Eski başarısız yazdırma işlerini temizle';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $silinen = PrintJob::where('status', PrintJob::STATUS_FAILED)
            ->where('created_at', '<', now()->subHours($hours))
            ->delete();

        $this->info("Temizlendi: {$silinen} eski iş.");

        return self::SUCCESS;
    }
}
