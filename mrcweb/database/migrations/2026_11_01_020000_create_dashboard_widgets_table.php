<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DASHBOARD WIDGET'LARI .
 *
 * Üç seviyeli yapılandırma TEK tabloda (kapsam sütunuyla):
 *  - scope=company, subject=NULL   → firma varsayılanı
 *  - scope=role,    subject=rol adı → rol havuzu (Admin/Personel ne görebilir)
 *  - scope=user,    subject=kullanıcı id → kişisel yerleşim
 *
 * Çözümleme cascade: kullanıcı satırı → rol satırı (kullanıcının rolleri sırayla)
 * → firma satırı → katalog varsayılanı. Satır YOKSA o seviye „karışmaz",
 * bir üst varsayılan geçer (yokluk = miras, „kapalı" demek değildir).
 *
 * Unique kısıtı YOKTUR (subject NULL satırlarda NULL≠NULL sayılır, MySQL/SQLite
 * çift kayda izin verirdi): tekillik `updateOrCreate` + API doğrulamasıyla korunur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 10); // company|role|user
            $table->string('subject', 80)->nullable(); // rol adı | kullanıcı id (metin)
            $table->string('widget_key', 40);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'scope', 'subject', 'widget_key']);
            $table->index(['company_id', 'widget_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
