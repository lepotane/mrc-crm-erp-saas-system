<?php

namespace App\Console\Commands\Integration;

use App\Integration\Sync\SyncLog;
use App\Integration\Translate\MyMemoryTranslator;
use App\Integration\Translate\ProductNameTranslator;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ürün adlarını İngilizceden Türkçeye çevirir (yalnızca MRC veritabanına yazar).
 *
 * Sayaçlar birbirini dışlar: processed = denenen satır, cached = önbellekten
 * gelen, api = MyMemory ile çevrilen, offline = çevrimdışı sözlüğe düşen,
 * failed = hata nedeniyle atlanan. Tsoft'a hiçbir yazım yapılmaz.
 */
class TranslateProductNamesCommand extends Command
{
    protected $signature = 'integration:translate-product-names
                            {--company= : Yalnızca bu firma}
                            {--limit=200 : En fazla ürün sayısı}
                            {--force : Elle değiştirilmiş Türkçe adları da güncelle}
                            {--dry-run : Hiçbir şey yazma (API çağrısı yapılır, sonuçlar gösterilir)}';

    protected $description = 'Ürün adlarını MyMemory ile çevirir, önbelleğe alır ve MRC urun.name alanını günceller';

    public function handle(MyMemoryTranslator $translator): int
    {
        $companyOption = $this->option('company');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $remaining = max(0, (int) $this->option('limit'));

        $candidates = Product::query()
            ->whereNotNull('name_en')
            ->where('name_en', '!=', '');

        if (! $force) {
            // Varsayılan: yalnızca hiç çevrilmemiş / İngilizce adı taşıyan satırlar.
            // Elle değiştirilmiş Türkçe ad asla ezilmez.
            $candidates->where(static function ($query): void {
                $query->whereNull('name')->orWhereColumn('name', 'name_en');
            });
        }

        $companyIds = $companyOption !== null
            ? [(int) $companyOption]
            : (clone $candidates)->distinct()->pluck('company_id')->all();

        if ($companyIds === []) {
            $this->warn('Çevrilecek ürünü olan firma bulunamadı.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN: veritabanına yazım yapılmayacak.');
        }

        $rows = [];
        $totals = ['processed' => 0, 'cached' => 0, 'api' => 0, 'offline' => 0, 'failed' => 0];

        foreach ($companyIds as $companyId) {
            if ($remaining <= 0) {
                break;
            }

            $stats = ['processed' => 0, 'cached' => 0, 'api' => 0, 'offline' => 0, 'failed' => 0];
            $startedAt = microtime(true);

            $products = (clone $candidates)
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->limit($remaining)
                ->get();

            foreach ($products as $product) {
                if ($remaining <= 0) {
                    break 2;
                }

                $remaining--;
                $stats['processed']++;

                try {
                    $source = trim((string) $product->name_en);
                    if ($source === '') {
                        continue;
                    }

                    // Sorgu filtresine ek güvenlik: elle değiştirilmiş adı asla ezme.
                    if (! $force && $product->name !== null && $product->name !== $source) {
                        continue;
                    }

                    $hash = hash('sha256', $source);

                    $cached = DB::table('product_name_translations')
                        ->where('company_id', $companyId)
                        ->where('source_hash', $hash)
                        ->first();

                    if ($cached !== null) {
                        $translated = (string) $cached->translated_text;
                        $stats['cached']++;
                    } else {
                        $translated = $translator->translate($source);

                        if ($translated !== null && trim($translated) !== '') {
                            $provider = 'mymemory';
                            $stats['api']++;
                        } else {
                            // API başarısız/kota bitti: deterministic çevrimdışı sözlük.
                            $translated = (new ProductNameTranslator)->toTurkish($source);
                            $provider = 'offline';
                            $stats['offline']++;
                        }
                    }

                    if (trim($translated) === '') {
                        continue;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    if ($cached === null) {
                        DB::table('product_name_translations')->insertOrIgnore([
                            'company_id' => $companyId,
                            'source_hash' => $hash,
                            'source_text' => $source,
                            'translated_text' => $translated,
                            'provider' => $provider,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // name_en korunur; yalnızca Türkçe görünen ad güncellenir.
                    if ($product->name !== $translated) {
                        $product->update(['name' => $translated]);
                    }
                } catch (Throwable) {
                    // Satır bazlı hata: kalan ürünlere devam et, adı loglama.
                    $stats['failed']++;

                    continue;
                }
            }

            if (! $dryRun) {
                SyncLog::create([
                    'company_id' => $companyId,
                    'channel' => 'translation',
                    'direction' => 'in',
                    'entity' => 'translation',
                    'status' => $stats['failed'] > 0 ? 'partial' : 'success',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    // Yalnızca sayılar: ürün adı / kaynak metin yazılmaz.
                    'preview' => json_encode($stats, JSON_UNESCAPED_UNICODE),
                ]);
            }

            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }

            $rows[] = [
                $companyId,
                $stats['processed'],
                $stats['cached'],
                $stats['api'],
                $stats['offline'],
                $stats['failed'],
            ];
        }

        $this->table(
            ['company', 'processed', 'cached', 'api', 'offline', 'failed'],
            $rows,
        );

        $this->info(sprintf(
            'Ürün adı çevirisi: %d firma, %d işlendi, %d önbellek, %d API, %d çevrimdışı, %d hata.',
            count($rows),
            $totals['processed'],
            $totals['cached'],
            $totals['api'],
            $totals['offline'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
