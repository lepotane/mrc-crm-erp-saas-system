<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SENKRONİZASYON FARKLARI (sync center).
 *
 * Tsoft YALNIZCA OKUNUR; bu tablo "Tsoft'ta değişen/nedir" sorularının
 * cevabını tutar. MRC tarafına yazım yalnızca kullanıcı onayıyla (import)
 * yapılır, bu tablodan Tsoft'a ASLA yazılmaz.
 *
 * Snapshot alanlarında kişisel veri saklanmaz: yalnızca kanonik ürün/fiyat/
 * stok/sipariş alanları (ad, SKU, tutar, miktar) yazılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_diffs')) {
            Schema::create('sync_diffs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // product | price | stock | order
                $table->string('entity_type', 30);
                $table->string('external_key', 190);
                // new_remote | new_local | changed_remote | changed_local
                $table->string('diff_type', 30);
                $table->json('mrc_snapshot')->nullable();
                $table->json('external_snapshot')->nullable();
                $table->text('summary')->nullable();
                // pending | imported | ignored
                $table->string('status', 20)->default('pending');
                $table->timestamp('detected_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['company_id', 'entity_type', 'external_key'],
                    'sync_diffs_identity_unique'
                );
                $table->index(['company_id', 'status'], 'sync_diffs_company_status_idx');
                $table->index('detected_at', 'sync_diffs_detected_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_diffs');
    }
};
