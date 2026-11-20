<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BAŞARISIZ SENKRONİZASYONLAR.
 *
 * Yeniden denemeye uygun hatalar burada birikir; resolved_at dolgulu
 * kayıtlar çözülmüş sayılır ve yeniden deneme kuyruğundan çıkarılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('failed_syncs')) {
            Schema::create('failed_syncs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('channel', 20);
                $table->string('entity', 60);
                $table->string('payload_ref', 190)->nullable();
                $table->text('error');
                $table->integer('attempts')->default(0);
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'resolved_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_syncs');
    }
};
