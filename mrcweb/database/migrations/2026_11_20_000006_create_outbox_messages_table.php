<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OUTBOX ( transactional outbox pattern ).
 *
 * Olaylar önce bu tabloya yazılır, arka planda relayer tarafından kanala
 * iletilir. idempotency_key aynı olayın iki kez işlenmesini engeller;
 * durum/geçiş ve yeniden deneme zamanı indekslenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbox_messages')) {
            Schema::create('outbox_messages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id');
                $table->string('event_type', 100);
                $table->json('payload');
                $table->json('headers')->nullable();
                $table->enum('status', ['new', 'relaying', 'published', 'failed', 'dead'])->default('new');
                $table->tinyInteger('attempts')->default(0);
                $table->timestamp('next_retry_at')->nullable();
                $table->char('idempotency_key', 36)->unique();
                $table->timestamp('available_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index(['status', 'next_retry_at']);
                $table->index(['company_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
