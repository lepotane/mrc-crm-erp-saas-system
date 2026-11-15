<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Çok dilli çıktı : ürün ve birim adlarının İngilizcesi.
 *
 * `name_en` boşsa gösterim Türkçe ada düşer (mevcut kayıtlar bozulmaz;
 * zorunlu alan yapılmadı). Çıktı dili `?lang=` ile seçilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name_en', 190)->nullable()->after('name');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->string('name_en', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};
