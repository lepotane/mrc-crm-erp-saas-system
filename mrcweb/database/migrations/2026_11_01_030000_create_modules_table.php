<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODÜLLER + EKLENTİLER .
 *
 * Firma başına modül anahtarları: çekirdek modüller kodda tanımlıdır
 * (`ModuleRegistry::CORE`), kurulu eklentiler satır olarak durur. Satır YOKSA
 * modül AÇIK varsayılır (lazy default): eski firmalara backfill gerekmez,
 * yeni firma kurulumunda tohum yazılmaz. Satır VARSA enabled/config kararı odur.
 *
 * Kapatılan modül API'den (`module_disabled` 403), menüden ve pano ızgarasından
 * kaybolur; VERİSİ durur (satır silinmez, kayıtlar silinmez) — yeniden açma
 * kayıpsız döner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->string('version', 20)->default('1.0.0');
            $table->string('source', 10)->default('core'); // core|local
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
