<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ÖDEME SAĞLAYICI HESAPLARI .
 *
 * Neden firma başına satır: MRC çok kiracılı. Her firma kendi sanal POS hesabını
 * (kendi para akışını) kullanır; bizim tek bir merkez hesabımız ödeme linklerinde
 * kullanılamaz — para firmaya değil bize geçmiş olurdu.
 *
 * `credentials` şifreli kolon (encrypted:array cast): merchant_key / secret_key gibi
 * kasayı açan değerler düz metin durmaz. Yedekten açılsa bile alan okunmaz.
 *
 * `bank_account_id`: sanal POS tahsilatının firmadaki hangi kasa/banka hesabına
 * işleneceği. Köprü boş bırakılırsa online tahsilat Payments olarak kaydedilir ama
 * Kasa/Banka ekranında bir hesaba bağlanmaz (para nerede sorusu cevapsız kalırdı).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 10); // paytr|iyzico
            $table->text('credentials');    // encrypted:array (alan seti sağlayıcıya göre)
            $table->boolean('is_sandbox')->default(true);
            $table->boolean('is_active')->default(false);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_credentials');
    }
};
