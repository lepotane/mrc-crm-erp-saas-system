<?php

namespace App\Console\Commands;

use App\Support\ExchangeRates;
use Illuminate\Console\Command;

/**
 * Günlük kur çekme : `schedule:run` altında her sabah koşar.
 *
 * Başarıda kaynak + kur sayısı basar; kaynakların ikisi de düşerse tabloya
 * dokunmaz (son geçerli kur durur) ve hata koduyla çıkar — cron e-postası /
 * log buradan anlaşılır. Aynı gün iki koşu çift satır doğurmaz (updateOrCreate).
 */
class FetchCurrencyRates extends Command
{
    protected $signature = 'currency:fetch {--date= : Kur günü (Y-m-d, varsayılan bugün; geriye dönük doldurma için)}';

    protected $description = 'TCMB + yedek kaynaktan günlük döviz kurlarını çeker ';

    public function handle(): int
    {
        $result = ExchangeRates::fetchDaily($this->option('date'));

        if ($result['source'] === null) {
            $this->error('Kur çekilemedi (TCMB + yedek düştü). Son geçerli kur kullanılıyor.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Kurlar yazıldı: kaynak=%s, para=%d%s',
            $result['source'],
            count($result['rates']),
            $result['deviated'] ? ' (UYARI: kaynaklar ayrıştı, loga bakın)' : ''
        ));

        return self::SUCCESS;
    }
}
