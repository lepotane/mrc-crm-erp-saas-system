<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // quote|sales_order|invoice|purchase
            $table->smallInteger('year'); // örn 2026
            $table->integer('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'type', 'year']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // quote|sales_order|invoice|purchase
            $table->string('serial', 32); // örn TEK-26-00001
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3)->default('TRY');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('grand_total', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_from_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'serial']);
            $table->index(['company_id', 'type', 'status']);
            $table->index(['company_id', 'party_id']);
            $table->index(['company_id', 'issue_date']);
        });

        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->string('name', 190);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(20);
            $table->decimal('line_subtotal', 15, 4)->default(0);
            $table->decimal('line_discount', 15, 4)->default(0);
            $table->decimal('line_tax', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            $table->decimal('cogs', 15, 4)->nullable(); // satış satırında issue anındaki ortalama maliyet
            $table->timestamps();

            $table->index(['company_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_items');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_series');
    }
};
