<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İKİ FAKTÖRLÜ GİRİŞ .
 *
 * `two_factor_secret` + `two_factor_confirmed_at` ikilisi „etkin" demektir:
 * kurulumda üretilen sır, kullanıcı telefonundaki uygulamayla DOĞRULANMADAN
 * etkin sayılmaz (aksi hâlde yanlış yazılmış sırla kullanıcı kendini kilitlerdi).
 * `two_factor_recovery_codes` tek kullanımlık yedek kodların ÖZETLERİDİR —
 * düz kod bir kez gösterilir, sonra yalnız özeti durur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
