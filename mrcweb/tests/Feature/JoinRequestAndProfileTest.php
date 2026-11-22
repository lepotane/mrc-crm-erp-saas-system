<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyJoinRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class JoinRequestAndProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Süper yönetici hesabı artık migration'da seed edilmiyor (test ortamında
        // "kullanıcı sayısı" testlerini bozuyordu). Bu test sınıfı onu kullanıyor.
        $this->seedSuperAdmin();
    }

    public function test_company_lookup_security_and_fuzzy_matching(): void
    {
        $owner = User::factory()->create();

        Company::create([
            'name' => 'Test Firma A.Ş.',
            'slug' => 'ortaklar-motors',
            'tax_number' => '1111111111',
            'owner_id' => $owner->id,
        ]);

        Company::create([
            'name' => 'Abcd Yazılım Teknoloji Ltd. Şti.',
            'slug' => 'abcd-yazilim',
            'tax_number' => '2222222222',
            'owner_id' => $owner->id,
        ]);

        // 1. Short prefix "abc" for "Abcd Yazılım" MUST NOT suggest "Abcd Yazılım" (security guard)
        $resShort = $this->postJson('/api/v1/public/company-lookup', [
            'query' => 'abc',
        ]);
        $resShort->assertOk()
            ->assertJsonPath('status', 'need_more_detail');

        // 2. Multi-word close match "abc yazılım" SHOULD suggest "Abcd Yazılım Teknoloji Ltd. Şti."
        $resMulti = $this->postJson('/api/v1/public/company-lookup', [
            'query' => 'abc yazılım',
        ]);
        $resMulti->assertOk()
            ->assertJsonPath('status', 'suggestion')
            ->assertJsonPath('company.name', 'Abcd Yazılım Teknoloji Ltd. Şti.');

        // 3. Kısmi eşleşme "test firm" SHOULD suggest "Test Firma A.Ş."
        $resFuzzy = $this->postJson('/api/v1/public/company-lookup', [
            'query' => 'test firm',
        ]);
        $resFuzzy->assertOk()
            ->assertJsonPath('status', 'suggestion')
            ->assertJsonPath('company.name', 'Test Firma A.Ş.');

        // 4. Exact normalized match "Test Firma A.Ş." SHOULD return exact
        $resExact = $this->postJson('/api/v1/public/company-lookup', [
            'query' => 'Test Firma A.Ş.',
        ]);
        $resExact->assertOk()
            ->assertJsonPath('status', 'exact')
            ->assertJsonPath('company.name', 'Test Firma A.Ş.');
    }

    public function test_join_request_submission_and_admin_approval_flow(): void
    {
        $admin = User::factory()->create();
        $company = Company::create([
            'name' => 'Test Firma A.Ş.',
            'slug' => 'ortaklar-motors-2',
            'tax_number' => '1234567890',
            'owner_id' => $admin->id,
        ]);

        $admin->update(['current_company_id' => $company->id]);
        $company->users()->attach($admin->id);

        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Personel', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $admin->assignRole('Admin');

        // Submit public join request
        $submitRes = $this->postJson('/api/v1/public/join-requests', [
            'company_id' => $company->id,
            'first_name' => 'Ahmet',
            'last_name' => 'Yılmaz',
            'email' => 'ahmet.yilmaz@ornek.com',
            'phone' => '0555 123 45 67',
            'address' => 'Kadıköy / İstanbul',
            'password' => 'Guv3nliSifre!',
            'requested_class' => 'Satış Temsilcisi',
            'message' => 'Satış ekibi başvurusu',
        ]);

        $submitRes->assertCreated();
        $requestId = $submitRes->json('request_id');
        $this->assertNotNull($requestId);

        // Admin lists join requests
        $listRes = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/join-requests');
        $listRes->assertOk()
            ->assertJsonCount(1, 'join_requests');

        // Admin approves the join request
        $approveRes = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/join-requests/{$requestId}/approve", [
            'role' => 'Personel',
            'user_class' => 'Satış Temsilcisi',
        ]);

        $approveRes->assertOk();

        $newUser = User::where('email', 'ahmet.yilmaz@ornek.com')->first();
        $this->assertNotNull($newUser);
        $this->assertEquals('Ahmet Yılmaz', $newUser->name);
        $this->assertEquals('Satış Temsilcisi', $newUser->user_class);
        $this->assertEquals($company->id, $newUser->current_company_id);
        $this->assertTrue($company->users()->where('users.id', $newUser->id)->exists());

        $joinReq = CompanyJoinRequest::find($requestId);
        $this->assertEquals('approved', $joinReq->status);
    }

    public function test_profile_update_and_custom_barcode_prefix_and_qr(): void
    {
        $user = User::factory()->create();
        $company = Company::create([
            'name' => 'Test Firma A.Ş.',
            'slug' => 'test-firma-as',
            'owner_id' => $user->id,
        ]);
        $user->update(['current_company_id' => $company->id]);
        $company->users()->attach($user->id);

        Role::findOrCreate('Admin', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->assignRole('Admin');

        $profileRes = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Kerem Ortak',
            'email' => $user->email,
            'phone' => '0532 000 00 00',
            'address' => 'İstanbul',
            'user_class' => 'Yönetici',
            'job_title' => 'Kurucu Ortak',
            'avatar_color' => '#B9FF66',
        ]);

        $profileRes->assertOk()
            ->assertJsonPath('user.user_class', 'Yönetici')
            ->assertJsonPath('user.job_title', 'Kurucu Ortak');

        $product = Product::create([
            'company_id' => $company->id,
            'name' => 'Fren Balatası',
            'sku' => 'FRN-001',
            'unit' => 'Adet',
            'sale_price' => 450,
            'purchase_price' => 300,
            'vat_rate' => 20,
        ]);

        // Assign barcode with custom prefix '869'
        $assignRes = $this->actingAs($user, 'sanctum')->postJson("/api/v1/products/{$product->id}/barcode/assign", [
            'prefix' => '869',
            'force' => true,
        ]);
        $assignRes->assertOk();
        $barcode = $assignRes->json('barcode');
        $this->assertStringStartsWith('869', $barcode);
        $this->assertEquals(13, strlen($barcode));

        // Render QR SVG
        $qrRes = $this->actingAs($user, 'sanctum')->get("/api/v1/products/{$product->id}/barcode?type=qr&format=svg");
        $qrRes->assertOk();
        $this->assertStringContainsString('<svg', $qrRes->getContent());
    }

    public function test_superadmin_stealth_security_company_approval_notifications_and_cv_builder(): void
    {
        // 1. SuperAdmin hesabı testte açıkça kurulur (TestCase::seedSuperAdmin).
        //    Gerçek parola testte veya depoda BULUNMAZ.
        $adminEmail = (string) config('mrc.superadmin.email', 'superadmin@example.com');
        $superAdmin = User::where('email', $adminEmail)->first();
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->isSuperAdmin());

        // 2. SuperAdmin login works with exact credentials
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $adminEmail,
            'password' => self::SUPERADMIN_PAROLA,
        ]);
        $loginRes->assertOk()
            ->assertJsonPath('user.is_superadmin', true);

        // 3. Normal user gets stealth 404 when probing /api/v1/sa-core/overview
        $normalUser = User::factory()->create();
        $this->actingAs($normalUser, 'sanctum')
            ->getJson('/api/v1/sa-core/overview')
            ->assertNotFound();

        // 4. "Firma Kur" with require_approval: true creates a pending company and notifies SuperAdmin
        $regRes = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mehmet Kaya',
            'email' => 'mehmet@yenifirma.com',
            'password' => 'Guv3nliSifre123!',
            'company_name' => 'Kaya Lojistik A.Ş.',
            'require_approval' => true,
        ]);
        $regRes->assertCreated()
            ->assertJsonPath('pending_approval', true);

        $pendingCompany = Company::where('name', 'Kaya Lojistik A.Ş.')->first();
        $this->assertNotNull($pendingCompany);
        $this->assertEquals('pending', $pendingCompany->approval_status);

        // Pending company owner cannot log in before SuperAdmin approval
        $this->postJson('/api/v1/auth/login', [
            'email' => 'mehmet@yenifirma.com',
            'password' => 'Guv3nliSifre123!',
        ])->assertStatus(403);

        // 5. SuperAdmin approves the company, sets subscription_ends_at & allowed_modules
        $saUpdateRes = $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/sa-core/companies/{$pendingCompany->id}", [
                'approval_status' => 'active',
                'subscription_ends_at' => now()->addDays(90)->toDateString(),
                'max_users' => 25,
                'allowed_modules' => ['parties', 'products', 'documents', 'ik-basvuru-pro', 'ekip'],
            ]);
        $saUpdateRes->assertOk()
            ->assertJsonPath('company.approval_status', 'active');

        // Now the approved company owner can log in
        $approvedLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'mehmet@yenifirma.com',
            'password' => 'Guv3nliSifre123!',
        ]);
        $approvedLogin->assertOk();

        // 6. Notifications API: SuperAdmin has unread notification from company registration
        $notifRes = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/notifications');
        $notifRes->assertOk();
        $this->assertGreaterThanOrEqual(1, $notifRes->json('unread_count'));

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        // 7. Public companies list & custom application form schema
        $companyOwner = User::where('email', 'mehmet@yenifirma.com')->first();
        Role::findOrCreate('Admin', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($pendingCompany->id);
        $companyOwner->assignRole('Admin');

        $schemaUpdate = $this->actingAs($companyOwner, 'sanctum')
            ->putJson('/api/v1/join-requests/form-schema', [
                'accepting_applications' => true,
                'schema' => [
                    ['id' => 'phone', 'label' => 'Cep Telefonu', 'type' => 'tel', 'required' => true, 'enabled' => true],
                    ['id' => 'cv_file', 'label' => 'Özgeçmiş (CV)', 'type' => 'file', 'required' => false, 'enabled' => true],
                ],
            ]);
        $schemaUpdate->assertOk()
            ->assertJsonPath('accepting_applications', true);

        $pubCompanies = $this->getJson('/api/v1/public/companies?q=Kaya');
        $pubCompanies->assertOk();
        $this->assertNotEmpty($pubCompanies->json('companies'));

        // 8. SuperAdmin is completely invisible in company members list
        $membersRes = $this->actingAs($companyOwner, 'sanctum')
            ->getJson('/api/v1/company/members');
        $membersRes->assertOk();
        $emails = collect($membersRes->json('members'))->pluck('email')->all();
        $this->assertNotContains($adminEmail, $emails);
    }
}
