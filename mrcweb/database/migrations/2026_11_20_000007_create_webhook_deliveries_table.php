<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GELEN WEBHOOK TESLİMATLARI.
 *
 * event_id ile tekrar eden teslimatlar engellenir; payload_hash içerik
 * değişikliğini tespit etmeye yarar. İşlem sonucu ve deneme sayısı izlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 190)->unique();
                $table->string('channel', 20);
                $table->string('topic', 100)->nullable();
                $table->timestamp('received_at');
                $table->timestamp('processed_at')->nullable();
                $table->string('result', 30)->nullable();
                $table->string('payload_hash', 64)->nullable();
                $table->tinyInteger('attempts')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['channel', 'received_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
