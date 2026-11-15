<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASAÜSTÜ YAZICI KÖPRÜSÜ .
 *
 * `devices`: firmanın PC'lerindeki bridge agent'ları. Eşleşme tek kullanımlık
 * kodla (`pairing_code_hash`, 15 dk); agent cihaz anahtarı (`token_hash`,
 * rotation destekli) ile konuşur. `last_seen_at` nabızdır (her poll tazelenir).
 *
 * `print_jobs`: yazdırma kuyruğu. Durum makinesi: queued → claimed → printed
 * (satır SİLİNİR — kuyruktan düşer) ya da claimed → queued (yeniden deneme) /
 * failed (5 deneme sonrası terminal). ACK yalnız güncel `claim_token` ile kabul
 * edilir — çift baskı bu kilitle önlenir. `printed` satırı tutulmaz; `failed`
 * satırlar `bridge:prune` (24 saat) ile temizlenir, kullanıcı o arada mobil
 * PDF'e düşer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('printers')->nullable();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'token_hash']);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('printer_name', 120);
            $table->string('kind', 10)->default('escpos'); // escpos|pdf
            $table->longText('payload');
            $table->string('status', 12)->default('queued'); // queued|claimed|failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('devices');
    }
};
