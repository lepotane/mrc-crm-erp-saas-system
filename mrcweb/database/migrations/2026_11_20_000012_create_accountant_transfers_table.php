<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUHASEBECİ AKTARIMLARI.
 *
 * Bir veya birden çok fişin (voucher_ids JSON) muhasebe yazılımına
 * aktarımı; onay, aktarım zamanı ve dış referans izlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accountant_transfers')) {
            Schema::create('accountant_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->json('voucher_ids');
                $table->enum('driver', ['file', 'rest', 'soap'])->default('file');
                $table->string('status', 20)->default('pending');
                $table->bigInteger('approved_by')->nullable();
                $table->timestamp('transferred_at')->nullable();
                $table->string('external_ref', 190)->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accountant_transfers');
    }
};
