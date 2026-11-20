<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GİB GELEN DOSYALAR.
 *
 * Entegratörden gelen belge dosyaları kaydedilir; ettN üzerinden mevcut
 * bir belgeyle eşleştirilip eşleşme durumu izlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gib_inbound_files')) {
            Schema::create('gib_inbound_files', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('ettN', 100)->nullable();
                $table->string('document_type', 40)->nullable();
                $table->string('file_path', 500);
                $table->timestamp('received_at');
                $table->bigInteger('matched_document_id')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['company_id', 'status']);
                $table->index(['ettN']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gib_inbound_files');
    }
};
