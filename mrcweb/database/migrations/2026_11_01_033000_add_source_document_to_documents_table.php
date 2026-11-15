<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İADE FATURASI .
 *
 * `documents.source_document_id`: iadenin dayandığı satış/alış faturası
 * (dönüşüm zinciri `converted_from_id`den ayrıdır — iade, tür değiştirme değil,
 * ters yönlü yeni belgedir). Kaynak silinirse bağ düşer (kayıt durur), bu yüzden
 * nullOnDelete. Kaynak zaten silinemez (hareket görmüş belge silinemez kuralı),
 * pratikte bağ kopmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('source_document_id')->nullable()->after('converted_from_id')
                ->constrained('documents')->nullOnDelete();
            $table->index(['company_id', 'source_document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'source_document_id']);
            $table->dropForeign(['source_document_id']);
            $table->dropColumn('source_document_id');
        });
    }
};
