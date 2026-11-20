<?php

namespace App\Console\Commands;

use App\Integration\Outbox\OutboxRelay;
use Illuminate\Console\Command;

/**
 * OUTBOX RELAY : bekleyen entegrasyon olaylarını kuyruk işçilerine verir.
 */
class OutboxRelayCommand extends Command
{
    protected $signature = 'integration:outbox-relay {--limit=100 : Tek seferde alınacak mesaj sayısı}';

    protected $description = 'Bekleyen entegrasyon outbox mesajlarini iletir';

    public function handle(OutboxRelay $relay): int
    {
        $sonuc = $relay->run(max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'İşlendi: %d, başarısız: %d',
            $sonuc['processed'],
            $sonuc['failed']
        ));

        return self::SUCCESS;
    }
}
