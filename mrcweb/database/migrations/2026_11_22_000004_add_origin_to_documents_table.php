<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BELGE KÖKENİ (MRC ↔ TSOFT).
 *
 * `mrc`: MRC arayüzünde üretilen belgeler; Tsoft yeniden kurulumunda KORUNUR.
 * `tsoft`: Dış kanaldan içe alınan belgeler; yeniden kurulumda silinebilir.
 *
 * Varsayılan `tsoft`'tur: mevcut kayıtlar geçmişte dış kanaldan gelmiş sayılır
 * ve yeniden kurulum davranışı değişmez; yalnızca açıkça `mrc` işaretlenenler
 * korunur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'origin')) {
                $table->string('origin', 16)->default('tsoft')->after('serial');
            }

            $table->index(['company_id', 'origin'], 'documents_company_origin_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_company_origin_idx');
            $table->dropColumn('origin');
        });
    }
};
