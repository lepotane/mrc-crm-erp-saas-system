<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('color', 9)->nullable(); // #RRGGBB — kategori renk paleti
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);      // Adet, Kilogram, Metre
            $table->string('code', 16);      // adet, kg, m
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('name', 190);
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->text('description')->nullable();
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->char('currency_code', 3)->default('TRY');
            $table->decimal('tax_rate', 5, 2)->default(20);
            $table->boolean('track_inventory')->default(true);
            $table->decimal('min_stock', 15, 4)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'barcode']);
            $table->index(['company_id', 'sku']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->string('address', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // in|out|adjust
            $table->decimal('quantity', 15, 4); // in: +, out: - olarak yorumlanır; adjust: net işaretli
            $table->decimal('unit_cost', 15, 4)->nullable(); // maliyet anlık 
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'created_at']);
            $table->index(['company_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('product_categories');
    }
};
