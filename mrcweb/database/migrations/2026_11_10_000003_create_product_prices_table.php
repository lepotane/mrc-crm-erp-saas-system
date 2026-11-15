<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ÇOKLU PARA BİRİMİ FİYATI.
 *
 * Sorun: üründe TEK fiyat vardı (sale_price + currency_code). Aynı ürünün TL,
 * USD ve EUR fiyatı farklı olabiliyor — bu yüzden fiyat listeleri ürün kartına
 * sığmıyordu.
 *
 * Çözüm:
 *   - `product_prices`: ürün başına para birimi başına bir fiyat satırı
 *     (satış + maliyet). Ürünün `sale_price`/`currency_code` alanları ANA fiyat
 *     olarak kalır (rapor/CSV/dışa aktarım uyumu bozulmaz).
 *   - `document_items.price_currency_code`: kalemin fiyatı HANGİ fiyat
 *     sınıfından geldi (belgenin para birimi her zaman geçerlidir; sınıf
 *     bilgisi kalem bazında tutulur → çıktı alırken kalem/topluk değiştirilebilir).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('currency_code', 3);
                $table->decimal('sale_price', 15, 4)->default(0);
                $table->decimal('cost_price', 15, 4)->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'currency_code']);
                $table->index(['company_id', 'currency_code']);
            });
        }

        Schema::table('document_items', function (Blueprint $table) {
            if (! Schema::hasColumn('document_items', 'price_currency_code')) {
                $table->string('price_currency_code', 3)->nullable()->after('unit_price');
            }
        });

        // Mevcut ürünlerin ana fiyatı, fiyat tablosuna da yazılır: liste
        // ekranı "tek fiyat" değil "ana fiyat + diğer para birimleri" görsün.
        if (Schema::hasTable('products')) {
            DB::table('products')
                ->whereNotNull('sale_price')
                ->orderBy('id')
                ->chunk(200, function ($urunler) {
                    foreach ($urunler as $urun) {
                        $var = DB::table('product_prices')
                            ->where('product_id', $urun->id)
                            ->where('currency_code', $urun->currency_code ?: 'TRY')
                            ->exists();

                        if ($var) {
                            continue;
                        }

                        DB::table('product_prices')->insert([
                            'company_id' => $urun->company_id,
                            'product_id' => $urun->id,
                            'currency_code' => $urun->currency_code ?: 'TRY',
                            'sale_price' => $urun->sale_price ?: 0,
                            'cost_price' => $urun->cost_price,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            if (Schema::hasColumn('document_items', 'price_currency_code')) {
                $table->dropColumn('price_currency_code');
            }
        });

        Schema::dropIfExists('product_prices');
    }
};
