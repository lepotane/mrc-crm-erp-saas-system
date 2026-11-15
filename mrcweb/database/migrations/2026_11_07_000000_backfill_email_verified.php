<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-posta doğrulaması : bu noktaya kadar açılmış tüm hesaplar
 * yönetici eliyle kuruldu (kayıt ucu / davet / mrc:create-admin) — e-postaları
 * güvenilir kabul edilir ve doğrulanmış işaretlenir. Bundan SONRA açılan
 * hesaplar `null` başlar ve doğrulama postası alır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update([
            'email_verified_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Geri alınmaz: hangi satırın sonradan doğrulandığı bilinemez.
    }
};
