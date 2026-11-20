<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HARİCİ Kimlik EŞLEME TABLOSU.
 *
 * MRC kaydı ile dış sistem kaydı arasındaki birebir eşlemeyi tutar
 * (ürün, sipariş, cari...). Beş kolonlu unique anahtarın otomatik adı
 * MySQL'in 64 karakterlik indeks adı sınırını aştığı için açık ad verilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_id_map')) {
            Schema::create('integration_id_map', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('mrc_type', 50);
                $table->bigInteger('mrc_id');
                $table->string('external_type', 50);
                $table->string('external_key', 190);
                $table->timestamps();

                $table->unique(
                    ['company_id', 'mrc_type', 'mrc_id', 'external_type', 'external_key'],
                    'integration_id_map_lookup_unique'
                );
                $table->index(
                    ['company_id', 'external_type', 'external_key'],
                    'integration_id_map_external_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_id_map');
    }
};
