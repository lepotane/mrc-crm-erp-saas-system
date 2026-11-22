<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIŞ KANAL SİPARİŞ METADATASI.
 *
 * Tsoft (ve benzeri kanallar) üzerinden gelen siparişlerin KANAL numarası
 * (`external_no`) ve ödeme/kargo gibi kişisel veri içermeyen alanları
 * (`channel_meta`) belge üzerinde saklanır. Veriler yalnızca beyaz listeye
 * alınmış, opak/teknik değerlerdir; ad, e-posta, telefon, adres YOKTUR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'external_no')) {
                $table->string('external_no', 120)->nullable()->after('serial');
            }
            if (! Schema::hasColumn('documents', 'channel_meta')) {
                $table->json('channel_meta')->nullable()->after('notes');
            }

            $table->index(['company_id', 'external_no'], 'documents_company_external_no_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_company_external_no_idx');
            $table->dropColumn(['external_no', 'channel_meta']);
        });
    }
};
