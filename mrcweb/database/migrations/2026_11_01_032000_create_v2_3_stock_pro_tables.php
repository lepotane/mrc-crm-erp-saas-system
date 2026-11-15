<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPO PRO : transferler + sayım oturumları.
 *
 * - `warehouse_transfers`: depolar arası ürün hareketi (tek işlemde out+in
 *   `stock_movements` çifti; iz için `reference = transfer:<id>`).
 * - `stock_counts` + `stock_count_lines`: sayım oturumu; satırda beklenen
 *   (snapshot) + sayılan; onayda farklar `adjust` hareketi olur
 *   (`reference = count:<id>`).
 * - `stock_movements.reference`: hareketin kaynağı (transfer/sayım izi;
 *   nullable, belge hareketlerinde boş).
 * - `products.max_stock`: tedarik önerisi üst sınırı (boşsa min*2 varsayılır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('draft'); // draft|approved|cancelled
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('expected_qty', 14, 4)->default(0);
            $table->decimal('counted_qty', 14, 4)->default(0);

            $table->unique(['stock_count_id', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('reference', 60)->nullable()->after('note');
            $table->index(['company_id', 'reference']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('max_stock', 14, 4)->nullable()->after('min_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('max_stock');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'reference']);
            $table->dropColumn('reference');
        });

        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('warehouse_transfers');
    }
};
