<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminCompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->seedSuperAdmin();

        $this->company = Company::create([
            'name' => 'Hedef Otomotiv A.Ş.',
            'slug' => 'hedef-otomotiv',
            'plan' => 'standart',
            'approval_status' => 'active',
            'subscription_ends_at' => null,
            'owner_id' => $this->superAdmin->id,
        ]);
        $this->company->users()->attach($this->superAdmin->id);
    }

    public function test_superadmin_overview_returns_available_plans_and_effective_limits(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/sa-core/overview');

        $response->assertOk();
        $response->assertJsonStructure([
            'stats',
            'companies',
            'available_plans',
            'core_modules',
            'plugins',
        ]);

        $this->assertNotEmpty($response->json('available_plans'));
    }

    public function test_superadmin_can_update_plan_status_duration_modules_and_custom_limits(): void
    {
        $payload = [
            'plan' => 'pro',
            'approval_status' => 'suspended',
            'subscription_ends_at' => now()->addDays(45)->toDateString(),
            'allowed_modules' => ['pano', 'cariler', 'urunler'],
            'custom_limits' => [
                'users' => 5,
                'products' => 3,
                'documents_per_month' => 10,
                'payment_links_per_month' => null,
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/v1/sa-core/companies/{$this->company->id}", $payload);

        $response->assertOk();
        $this->company->refresh();

        $this->assertSame('pro', $this->company->plan);
        $this->assertSame('suspended', $this->company->approval_status);
        $this->assertNotNull($this->company->subscription_ends_at);
        $this->assertContains('cariler', $this->company->allowed_modules);
        $this->assertNotContains('stok', $this->company->allowed_modules);
        $this->assertFalse(ModuleRegistry::isEnabled($this->company->id, 'stok'));
        $this->assertTrue(ModuleRegistry::isEnabled($this->company->id, 'cariler'));

        // Custom limits verification
        $this->assertSame(3, $this->company->custom_limits['products']);
        $this->assertSame(5, $this->company->custom_limits['users']);
        $this->assertNull($this->company->custom_limits['payment_links_per_month']);

        // PlanLimits verification
        $planLimits = PlanLimits::for($this->company);
        $this->assertSame(3, $planLimits->limitFor('products'));
        $this->assertSame(5, $planLimits->limitFor('users'));
        $this->assertSame(10, $planLimits->limitFor('documents_per_month'));
        $this->assertNull($planLimits->limitFor('payment_links_per_month'));
    }

    public function test_duration_preset_unlimited_clears_subscription_ends_at(): void
    {
        $this->company->update(['subscription_ends_at' => now()->addDays(10)]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/v1/sa-core/companies/{$this->company->id}", [
                'duration_preset' => 'unlimited',
            ]);

        $response->assertOk();
        $this->company->refresh();
        $this->assertNull($this->company->subscription_ends_at);
        $this->assertFalse($this->company->isSubscriptionExpired());
    }

    public function test_duration_preset_expired_sets_expired_date(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/v1/sa-core/companies/{$this->company->id}", [
                'duration_preset' => 'expired',
            ]);

        $response->assertOk();
        $this->company->refresh();
        $this->assertTrue($this->company->isSubscriptionExpired());
    }
}
