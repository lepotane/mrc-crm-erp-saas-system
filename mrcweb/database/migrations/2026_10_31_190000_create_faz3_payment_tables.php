<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 10)->default('cash'); // cash|bank|card
            $table->string('account_number', 20)->nullable();
            $table->string('iban', 34)->nullable();
            $table->char('currency_code', 3)->default('TRY');
            $table->decimal('opening_balance', 15, 4)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3); // in|out
            $table->decimal('amount', 15, 4);
            $table->char('currency_code', 3)->default('TRY');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->string('method', 10)->default('cash'); // cash|bank|card|transfer
            $table->date('paid_at');
            $table->string('reference', 60)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'party_id', 'paid_at']);
            $table->index(['company_id', 'direction', 'paid_at']);
            $table->index(['company_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bank_accounts');
    }
};
