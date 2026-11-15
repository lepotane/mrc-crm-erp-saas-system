<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ÇOKLU PARA BİRİMİ + KUR .
 *
 * `currencies`: firmanın kullanabileceği para tanımları (ISO kod anahtardır).
 * `exchange_rates`: GÜNLÜK kur fotoğrafı — 1 birim döviz = kaç TRY (TCMB ForexBuying
 * geleneği). TRY'nin kuru her zaman 1'dir ve tabloya yazılmaz (bölme hatası olmasın).
 *
 * Neden günlük satır: kur değişir ama geçmiş belge değişmez. `documents.rate_date`
 * + `exchange_rate` ikilisi „bu belge hangi günün kuruyla kesildi" sorusunu
 * tablodan cevaplar; rapor sonradan kuru yeniden hesaplamaz.
 *
 * Referans veri migration'da ekilir: testler `RefreshDatabase` ile migration
 * çalıştırır, seeder ÇALIŞTIRMAZ — tohum burada olmazsa testte para birimi
 * doğrulaması boş kümeye düşer ve her belge 422 yer (bulunan tuzak değil, önlem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 60);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency_code', 3);
            $table->date('rate_date');
            $table->decimal('rate', 15, 6);
            $table->string('source', 20);
            $table->timestamps();

            $table->unique(['currency_code', 'rate_date']);
            $table->index(['currency_code', 'rate_date']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->date('rate_date')->nullable()->after('exchange_rate');
        });

        $now = now()->format('Y-m-d H:i:s');

        foreach ([
            ['TRY', 'Türk Lirası', '₺', 2, 1],
            ['USD', 'ABD Doları', '$', 2, 2],
            ['EUR', 'Euro', '€', 2, 3],
            ['GBP', 'İngiliz Sterlini', '£', 2, 4],
        ] as [$code, $name, $symbol, $decimals, $sort]) {
            DB::table('currencies')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'symbol' => $symbol, 'decimals' => $decimals,
                    'is_active' => true, 'sort_order' => $sort,
                    'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('rate_date');
        });

        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
