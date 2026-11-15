<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SüperAdmin hesapları hiçbir firma üye listesinde yer almamalıdır (gizlilik & güvenlik)
        $superAdminIds = DB::table('users')
            ->where('is_superadmin', true)
            ->pluck('id')
            ->all();

        if (! empty($superAdminIds)) {
            DB::table('company_user')
                ->whereIn('user_id', $superAdminIds)
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Geri alma işlemine gerek yoktur
    }
};
