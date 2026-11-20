<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENTEGRASYON KANAL TANIMLARI.
 *
 * Firma başına bir kanal (tsoft | gib | luca) kaydı. Kimlik bilgileri ve
 * webhook sırrı şifreli (encrypted) saklanır; scopes/settings JSON'dır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_integrations')) {
            Schema::create('tenant_integrations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->enum('channel', ['tsoft', 'gib', 'luca']);
                $table->string('driver', 40);
                $table->text('credentials')->nullable();   // encrypted
                $table->text('webhook_secret')->nullable(); // encrypted
                $table->json('scopes')->nullable();
                $table->json('settings')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamp('last_success_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_integrations');
    }
};
