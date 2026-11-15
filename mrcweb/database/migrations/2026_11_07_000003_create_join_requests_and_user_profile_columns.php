<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'user_class')) {
                $table->string('user_class', 60)->default('Standart Personel')->after('address');
            }
            if (! Schema::hasColumn('users', 'job_title')) {
                $table->string('job_title', 100)->nullable()->after('user_class');
            }
            if (! Schema::hasColumn('users', 'avatar_color')) {
                $table->string('avatar_color', 24)->nullable()->after('job_title');
            }
        });

        if (! Schema::hasTable('company_join_requests')) {
            Schema::create('company_join_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->string('email', 191)->index();
                $table->string('phone', 40)->nullable();
                $table->text('address')->nullable();
                $table->string('password_hash');
                $table->string('requested_class', 60)->default('Standart Personel');
                $table->text('message')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_join_requests');

        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(
                ['phone', 'address', 'user_class', 'job_title', 'avatar_color'],
                fn ($c) => Schema::hasColumn('users', $c)
            );
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
