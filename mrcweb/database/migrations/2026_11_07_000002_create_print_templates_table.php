<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yazdırma şablonları (sürükle-bırak tasarımcı): fatura, teklif, sipariş,
 * alım faturası, fiş, irsaliye + rulo etiket. Firma başına türde tek varsayılan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name', 80);
            $table->boolean('is_default')->default(false);
            $table->json('layout');
            $table->timestamps();

            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
