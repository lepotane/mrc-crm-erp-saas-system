<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ürün adı çeviri önbelleği (MyMemory + çevrimdışı sözlük).
 *
 * Kaynak İngilizce adın sha256 özeti kiracı bazında benzersizdir; aynı ad bir
 * daha çevrilmez. Ham ürün adı yalnızca hata ayıklama amacıyla tutulur;
 * SyncLog'a ASLA ürün adı yazılmaz. Tsoft'a yazım yapılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_name_translations')) {
            Schema::create('product_name_translations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->char('source_hash', 64);
                $table->text('source_text');
                $table->text('translated_text');
                $table->string('provider', 32)->default('mymemory');
                $table->timestamps();

                $table->unique(
                    ['company_id', 'source_hash'],
                    'product_name_translations_identity_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_name_translations');
    }
};
