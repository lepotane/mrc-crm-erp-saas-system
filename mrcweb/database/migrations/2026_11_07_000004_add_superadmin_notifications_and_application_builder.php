<?php

use App\Support\SuperAdminBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_superadmin')) {
                $table->boolean('is_superadmin')->default(false)->after('avatar_color');
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'approval_status')) {
                $table->string('approval_status', 24)->default('active')->after('plan');
            }
            if (! Schema::hasColumn('companies', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable()->after('approval_status');
            }
            if (! Schema::hasColumn('companies', 'allowed_modules')) {
                $table->json('allowed_modules')->nullable()->after('subscription_ends_at');
            }
            if (! Schema::hasColumn('companies', 'max_users')) {
                $table->unsignedInteger('max_users')->default(25)->after('allowed_modules');
            }
            if (! Schema::hasColumn('companies', 'accepting_applications')) {
                $table->boolean('accepting_applications')->default(true)->after('max_users');
            }
            if (! Schema::hasColumn('companies', 'application_form_schema')) {
                $table->json('application_form_schema')->nullable()->after('accepting_applications');
            }
        });

        Schema::table('company_join_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('company_join_requests', 'cv_path')) {
                $table->string('cv_path', 500)->nullable()->after('message');
            }
            if (! Schema::hasColumn('company_join_requests', 'cv_original_name')) {
                $table->string('cv_original_name', 255)->nullable()->after('cv_path');
            }
            if (! Schema::hasColumn('company_join_requests', 'custom_answers')) {
                $table->json('custom_answers')->nullable()->after('cv_original_name');
            }
        });

        if (! Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('type', 60)->default('info');
                $table->string('title', 180);
                $table->text('body')->nullable();
                $table->string('action_url', 255)->nullable();
                $table->string('color', 24)->default('#B9FF66');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'user_id', 'read_at']);
            });
        }

        // Varsayılan SüperAdmin hesabı.
        //
        // DİKKAT: Bu blok üretimde hesabı oluşturur/günceller (ilk kurulum).
        // Test ortamında ÇALIŞMAZ. 07 Kas'ta bu blok her ortamda çalışıyordu ve
        // "kullanıcı sayısı = 1" diye sayan 7 test kırılıyordu (AdminProvisioning,
        // AuthFlow, PasswordReset). Testler artık ihtiyacı doğrudan sorar:
        // Tests\TestCase::seedSuperAdmin().
        //
        // Parola config('mrc.superadmin.password') üzerinden gelir → yalnızca
        // .env'den; REPO İÇİNDE VARSAYILAN PAROLA YOKTUR. Tanımlı değilse hesap
        // kurulmaz (boş parolalı hesap "kimse giremez ama var" durumu yaratırdı).
        // Parolayı .env'ye yazdıktan sonra: php artisan mrc:create-superadmin
        if (! app()->runningUnitTests() && ! app()->environment('testing')) {
            SuperAdminBootstrap::ensure();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');

        Schema::table('company_join_requests', function (Blueprint $table) {
            foreach (['cv_path', 'cv_original_name', 'custom_answers'] as $col) {
                if (Schema::hasColumn('company_join_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            foreach (['approval_status', 'subscription_ends_at', 'allowed_modules', 'max_users', 'accepting_applications', 'application_form_schema'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_superadmin')) {
                $table->dropColumn('is_superadmin');
            }
        });
    }
};
