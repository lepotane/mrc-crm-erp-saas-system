<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ÖDEME LİNKLERİ .
 *
 * Satıcının müşterisine gönderdiği, sağlayıcıda (PayTR / iyzico) barınan ödeme sayfasının
 * bizdeki kaydı. Para bizim sunucumuzdan geçmez; biz yalnız "şu tutarı şu cariden bekliyorum"
 * der, sağlayıcının bildirimini imzasını doğrulayarak kabul ederiz.
 *
 * `token` = PayTR'deki merchant_oid. Kiracı çözümü buradan yapılır: bildirimde oturum
 * yoktur (PayTR bunu açıkça söyler), firma link satırından okunur.
 *
 * `payment_id`: bildirim ödendiğinde üretilen Payments satırı. Mükerrer bildirimlerde
 * (ağ tekrarları) ikinci kayıt açmamak için tek doğruluk noktası link satırıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->string('provider', 10); // paytr|iyzico
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 4);
            $table->char('currency_code', 3)->default('TRY');
            $table->string('status', 12)->default('pending'); // pending|paid|failed|cancelled
            $table->string('provider_token', 190)->nullable();
            $table->string('pay_url', 400)->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('failure_reason', 190)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_notification_at')->nullable();
            $table->unsignedInteger('notification_count')->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'document_id']);
            $table->index(['provider', 'provider_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
