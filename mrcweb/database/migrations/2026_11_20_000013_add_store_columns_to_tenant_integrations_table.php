<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tsoft webhook'unda kiracıyı çözmek için mağaza adresi/kodu gerekir.
     * Webhook controller X-Tsoft-Store başlığını veya app.url host'unu
     * bu kolonlarla eşleştirir.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tenant_integrations')) {
            return;
        }

        Schema::table('tenant_integrations', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_integrations', 'domain')) {
                $table->string('domain', 190)->nullable()->after('driver');
            }
            if (! Schema::hasColumn('tenant_integrations', 'store')) {
                $table->string('store', 190)->nullable()->after('domain');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_integrations')) {
            return;
        }

        Schema::table('tenant_integrations', function (Blueprint $table): void {
            foreach (['store', 'domain'] as $column) {
                if (Schema::hasColumn('tenant_integrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
