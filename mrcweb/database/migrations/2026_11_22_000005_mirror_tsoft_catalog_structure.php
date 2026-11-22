<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tsoft katalog yapısının MRC tarafında aynalanması (YALNIZCA OKUMA akışı).
 *
 * - products: Tsoft görsel bağlantıları (tek kapak + çoklu dizi).
 * - product_categories: hiyerarşi (parent_id), dış kategori kodu ve yaprak bilgisi.
 * - units: Tsoft StockUnitId eşlemesi (birim adı/code zaten mevcut).
 *
 * Hiçbir Tsoft yazma ucu kullanılmaz; bu kolonlar yalnızca yerel veri kalitesi
 * ve birebir yapı eşlemesi içindir. Mevcut kayıtlar boş kalır (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'image_url')) {
                $table->string('image_url', 500)->nullable()->after('barcode');
            }
            if (! Schema::hasColumn('products', 'images')) {
                $table->json('images')->nullable()->after('image_url');
            }
        });

        Schema::table('product_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('product_categories', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('product_categories', 'external_code')) {
                $table->string('external_code', 32)->nullable()->after('name');
            }
            if (! Schema::hasColumn('product_categories', 'is_leaf')) {
                $table->boolean('is_leaf')->default(true)->after('external_code');
            }

            // MySQL'de NULL değerler unique kısıtlamaya girmez; aynı kodun
            // birden fazla kiracıda tekrar kullanılması sorun değildir.
            $table->unique(['company_id', 'external_code'], 'product_categories_company_external_code_unique');
        });

        Schema::table('units', function (Blueprint $table) {
            if (! Schema::hasColumn('units', 'external_code')) {
                $table->string('external_code', 32)->nullable()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique('product_categories_company_external_code_unique');
            $table->dropColumn(['parent_id', 'external_code', 'is_leaf']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'images']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('external_code');
        });
    }
};
