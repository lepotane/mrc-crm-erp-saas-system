<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ABONELİK PLANI .
 *
 * Limitler config/plans.php'de; `companies.plan` hangi satırı okuyacağını söyler.
 * Varsayılan `standart`: mevcut kurulumlar (ve local demo) limine takılmadan çalışsın,
 * plan düşürme kararı bilinçli bir işlem olsun. `NOT NULL` + varsayılan: plansız firma
 * olmamalı — limit denetimi `null` plan için "limitsiz"e düşer ama sessiz sapma üretir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('plan', 30)->default('standart')->after('currency_code');
            $table->index(['plan']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['plan']);
            $table->dropColumn('plan');
        });
    }
};
