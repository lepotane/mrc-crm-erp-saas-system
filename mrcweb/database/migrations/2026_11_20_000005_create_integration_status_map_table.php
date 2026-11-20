<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DURUM EŞLEME TABLOSU.
 *
 * Dış kanalın durum değerini (external_status) MRC durumuna (mrc_status)
 * çevirir; kanal bazında tek bir eşleme geçerlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_status_map')) {
            Schema::create('integration_status_map', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('channel', 20);
                $table->string('external_status', 80);
                $table->string('mrc_status', 80);
                $table->timestamps();

                $table->unique(['company_id', 'channel', 'external_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_status_map');
    }
};
