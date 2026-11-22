<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HESAP PLANI SENKRONIZASYONU.
 *
 * Luca'dan (veya manuel içe aktarımdan) gelen hesap planı kiracı başına
 * `luca_account_plans` tablosunda tutulur; belge tipleri için muhasebe
 * eşlemeleri ise `account_mappings` tablosunda saklanır.
 *
 * Hesap kodları koda gömülmez (120/600/391 yalnızca yorum örneğidir):
 * hiçbir varsayılan kod dayatılmaz, eşleme yoksa hata verilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('luca_account_plans')) {
            Schema::create('luca_account_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id'); // foreign key yok: kiracılar ayrı şemada olabilir
                $table->string('code', 40);
                $table->string('name', 190);
                $table->string('parent_code', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('source', 20)->default('import');
                $table->string('external_ref', 190)->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'code']);
                $table->index(['company_id', 'parent_code']);
            });
        }

        if (! Schema::hasTable('account_mappings')) {
            Schema::create('account_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id'); // foreign key yok
                $table->string('document_type', 50);
                $table->string('role', 30); // debit | credit | tax | counter
                $table->string('account_code', 40);
                $table->string('source', 20)->default('manual');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'document_type', 'role']);
                $table->index(['company_id', 'account_code']);
            });
        }
    }

    public function down(): void
    {
        // Ters sıra: eşlemeler, hesap planından önce kaldırılır.
        Schema::dropIfExists('account_mappings');
        Schema::dropIfExists('luca_account_plans');
    }
};
