<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DENETİM KAYDI .
 *
 * „Kim, ne zaman, hangi kayda ne yaptı" sorusunun cevabı başka hiçbir tabloda
 * yoktur: payments satırı paranın hareketini söyler ama KİMİN yazdığını söylemez;
 * soft-delete geri alınabilir ama KİMİN sildiğini söylemez. Bu tablo o boşluğu kapatır.
 *
 * `company_id` NULL olabilir: giriş/çıkış gibi kullanıcı-düzeyi olayların firması
 * yoktur (kullanıcı o anda firma bağlamında değildir). `auditable` NULL olabilir:
 * davet iptali gibi „kayıt silindi" olaylarında hedef artık yoktur ama OLAY vardır.
 * `meta` olayın özetidir (seri no, tutar, eski→yeni durum) — tam kayıt değil;
 * tam kayıt ilgili tablodan okunur. SIRLAR ASLA meta'ya yazılmaz (ödeme anahtarları,
 * şifreler, 2FA sırları bu tabloya giremez — kodda ayrı denetlenir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'action']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
