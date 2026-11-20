<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALAN SAHİPLİĞİ (field ownership).
 *
 * Bir alanın (ör. stok, fiyat) kaynağının MRC mi yoksa TSoft mu olduğunu
 * firma + varlık + alan bazında belirler; çakışan alanlarda yetki bu tabloya
 * bakılarak çözülür.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_field_owner')) {
            Schema::create('integration_field_owner', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('entity', 50);
                $table->string('field', 50);
                $table->enum('source', ['mrc', 'tsoft'])->default('mrc');
                $table->timestamps();

                $table->unique(['company_id', 'entity', 'field']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_field_owner');
    }
};
