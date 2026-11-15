<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAPOR İNDEKSLERİ .
 *
 * Cari bakiye + yaşlandırma raporunun sorguları üç desende koşuyor:
 *   documents WHERE company_id + party_id IN (…) + status IN (…)      → toplu bakiye/döküm
 *   documents WHERE company_id + status IN (…) + due_date …           → yaşlandırma kovaları
 *   payments  WHERE company_id + party_id IN (…) + direction          → toplu tahsilat/ödeme
 * Mevcut indeksler bunların yalnız bir kısmını karşılıyordu; MySQL kalanı sırayı
 * kendisi seçip büyük firmada tam taramaya düşüyor.
 *
 * Ek: documents(company_id, party_id, status) ve documents(company_id, status, due_date)
 * (vade + durum birlikte okunur), payments(company_id, direction, party_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['company_id', 'party_id', 'status'], 'documents_company_party_status_index');
            $table->index(['company_id', 'status', 'due_date'], 'documents_company_status_due_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['company_id', 'direction', 'party_id'], 'payments_company_direction_party_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_company_party_status_index');
            $table->dropIndex('documents_company_status_due_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_company_direction_party_index');
        });
    }
};
