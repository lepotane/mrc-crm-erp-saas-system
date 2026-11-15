<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Başvuru formunda gönderilen parolanın şifreli hâli.
 *
 * Neden: onay (approve) akışında, e-posta zaten kayıtlı bir hesaba aitse
 * başvuruyu onaylayan yönetici o hesabı parola doğrulaması olmadan firmaya
 * ekliyordu — hesap ele geçirme yolu. Doğru çözüm: başvuru sahibi, formda
 * girdiği parolanın hesabın parolasıyla aynı olduğunu kanıtlar.
 *
 * Parola düz metin saklanmaz: Laravel `Crypt` (APP_KEY) ile şifrelenir.
 * Onaydan sonra alan temizlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_join_requests')) {
            return;
        }

        Schema::table('company_join_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('company_join_requests', 'password_encrypted')) {
                $table->text('password_encrypted')->nullable()->after('password_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_join_requests')) {
            return;
        }

        Schema::table('company_join_requests', function (Blueprint $table) {
            if (Schema::hasColumn('company_join_requests', 'password_encrypted')) {
                $table->dropColumn('password_encrypted');
            }
        });
    }
};
