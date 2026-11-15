<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('customer'); // customer|supplier|both
            $table->string('code', 12)->nullable();          // firma içi otomatik sıra: 00001
            $table->string('name', 190);
            $table->string('tax_number', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 60)->nullable();
            $table->char('currency_code', 3)->default('TRY');
            $table->decimal('opening_balance', 15, 4)->default(0); // + alacak, - borç yönlü
            $table->decimal('credit_limit', 15, 4)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('title', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['party_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('parties');
    }
};
