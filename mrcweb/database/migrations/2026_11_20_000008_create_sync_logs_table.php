<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SENKRONİZASYON GÜNLÜKLERİ.
 *
 * Her içe/dışa aktarma denemesinin süresi, durumu ve dış referansı tutulur.
 * preview alanına yalnızca maskelenmiş veri yazılır (ham veri saklanmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_logs')) {
            Schema::create('sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('channel', 20);
                $table->enum('direction', ['in', 'out']);
                $table->string('entity', 60);
                $table->string('external_ref', 190)->nullable();
                $table->integer('duration_ms')->nullable();
                $table->string('status', 20);
                $table->text('preview')->nullable(); // masked data only
                $table->timestamp('created_at')->nullable();

                $table->index(['company_id', 'channel', 'created_at']);
                $table->index(['status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
