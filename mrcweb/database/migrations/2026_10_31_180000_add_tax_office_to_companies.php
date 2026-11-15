<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma profiline „vergi dairesi”.
 *
 * e-fatura/e-arşiv çıktılarında „Vergi no” ile birlikte zorunlu alan; belge PDF’inde
 * firma künyesinde basılır. nullable: v1’de zorunlu değildir (yurtdışı mükellef /
 * tekil kullanıcı senaryoları).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_office', 120)->nullable()->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('tax_office');
        });
    }
};
