<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOK DURUMU (snapshot).
 *
 * SKU + depo bazında güncel stok ve sürüm numarası tutulur. MySQL'de NULL
 * içeren unique anahtar tekrarlanan satırlara izin verdiğinden depo boş
 * geçen kayıtlar için ek olarak (company_id, sku) indeksi eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_stock_state')) {
            Schema::create('integration_stock_state', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('sku', 100);
                $table->bigInteger('warehouse_id')->nullable();
                $table->decimal('quantity', 20, 4)->default(0);
                $table->integer('version')->default(0);
                $table->string('source', 30);
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'sku', 'warehouse_id']);
                $table->index(['company_id', 'sku']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_stock_state');
    }
};
