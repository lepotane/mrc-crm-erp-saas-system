<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GİB BELGELERİ.
 *
 * e-Fatura/e-Arşiv belgelerinin taslağı, resmîleşme durumu, dosya yolu ve
 * XML'i tutulur. ettN benzersizdir (NULL birden çok kez geçebilir).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gib_documents')) {
            Schema::create('gib_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->bigInteger('document_id')->nullable();
                $table->string('document_type', 40);
                $table->string('ettN', 100)->nullable();
                $table->enum('direction', ['out', 'in'])->default('out');
                $table->enum('status', ['draft', 'official_pending', 'official', 'cancelled'])->default('draft');
                $table->string('file_path', 500)->nullable();
                $table->longText('xml')->nullable();
                $table->string('integrator_ref', 190)->nullable();
                $table->json('response')->nullable();
                $table->timestamp('official_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'ettN']);
                $table->index(['document_id']);
                $table->index(['status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gib_documents');
    }
};
