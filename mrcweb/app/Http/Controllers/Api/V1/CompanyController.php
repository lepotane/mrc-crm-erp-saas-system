<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Audit;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    /**
     * Seçili firmanın bilgileri (ad, vergi no/dairesi, adres, logo)
     *
     * @response array{company: array{id: int, name: string, slug: string, tax_number: string|null, tax_office: string, email: string|null, phone: string|null, address: string|null, city: string|null, country: string, currency_code: string, logo_path: string|null, logo_url: string|null}}
     */
    public function show(Request $request)
    {
        return response()->json(['company' => $this->present($request->user()->currentCompany)]);
    }

    /**
     * Firma bilgilerini güncelle (Admin)
     *
     * Firma profilini günceller (yalnız Admin). Slug/kuruluş bilgisi sabit kalır.
     *
     * @response array{company: array{id: int, name: string, slug: string, tax_number: string|null, tax_office: string, email: string|null, phone: string|null, address: string|null, city: string|null, country: string, currency_code: string, logo_path: string|null, logo_url: string|null}}
     */
    public function update(Request $request)
    {
        $this->authorizeAdmin($request);

        $company = $request->user()->currentCompany;

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'tax_number' => ['nullable', 'string', 'max:20'],
            'tax_office' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:60'],
            'country' => ['sometimes', 'string', 'size:2'],
        ]);

        $company->fill($data)->save();

        Audit::fromRequest($request, 'company.updated', $company->id, $company, [
            'changed' => array_keys($data),
        ]);

        return response()->json(['company' => $this->present($company->refresh())]);
    }

    /**
     * Firma logosu yükle (PNG/JPG/WebP, en fazla 2 MB)
     *
     * Logo public disk'e `logos/` altında saklanır; eski logo silinir. Gövde JSON
     * değil **multipart/form-data**'dır (form alanı: `file`). Cevap güncellenmiş firmadır.
     *
     * @response array{company: array{id: int, name: string, slug: string, tax_number: string|null, tax_office: string, email: string|null, phone: string|null, address: string|null, city: string|null, country: string, currency_code: string, logo_path: string|null, logo_url: string|null}}
     * @requestMediaType multipart/form-data
     */
    #[\Dedoc\Scramble\Attributes\BodyParameter(
        name: 'file',
        description: 'Logo dosyası (form alanı: file) — PNG/JPG/WebP, en fazla 2 MB.',
        required: true,
        type: 'string',
        format: 'binary',
    )]
    public function uploadLogo(Request $request)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $company = $request->user()->currentCompany;

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $path = $request->file('file')->store('logos', 'public');
        $company->forceFill(['logo_path' => $path])->save();

        return response()->json(['company' => $this->present($company->refresh())]);
    }

    /**
     * Firma ekip üyeleri ve rolleri
     *
     * Aktif firmanın üyeleri ve firma-bazlı rolleri.
     *
     * @response array{members: array<int, array{id: int, name: string, email: string, roles: array<int, string>, joined_at: string}>}
     */
    public function members(Request $request)
    {
        $company = $request->user()->currentCompany;

        $members = $company->users()
            ->where(function ($q) {
                $q->whereNull('users.is_superadmin')->orWhere('users.is_superadmin', false);
            })
            ->where('users.is_superadmin', false)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'SuperAdmin'))
            ->orderBy('company_user.created_at')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.user_class',
                'users.job_title',
                'users.avatar_color',
            ])
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'user_class' => $u->user_class ?: 'Personel',
                    'job_title' => $u->job_title,
                    'avatar_color' => $u->avatar_color,
                    'roles' => $u->getRoleNames()->values(),
                    'joined_at' => optional($u->pivot)->created_at?->toIso8601String(),
                ];
            });

        return response()->json(['members' => $members]);
    }

    /**
     * Firma üyesinin kullanıcı sınıfını, unvanını veya rolünü güncelle (Admin)
     *
     * Yalnızca seçili firmanın yöneticisi (Admin) üyenin sınıfını ve rolünü değiştirebilir.
     *
     * @response array{member: array{id: int, name: string, email: string, user_class: string, job_title: string|null, roles: array<int, string>}}
     */
    public function updateMember(Request $request, \App\Models\User $user)
    {
        $this->authorizeAdmin($request);

        if ($user->isSuperAdmin()) {
            abort(404, __('Kullanıcı bulunamadı.'));
        }

        $company = $request->user()->currentCompany;
        if (! $company->users()->whereKey($user->id)->exists()) {
            abort(404, __('Bu kullanıcı aktif firmanın üyesi değil.'));
        }

        $data = $request->validate([
            'user_class' => ['sometimes', 'required', 'string', 'max:60'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'string', 'in:Admin,Personel'],
        ]);

        $user->fill(array_intersect_key($data, array_flip(['user_class', 'job_title'])))->save();

        if (! empty($data['role'])) {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            $user->syncRoles([$data['role']]);
        }

        return response()->json([
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_class' => $user->user_class ?: 'Personel',
                'job_title' => $user->job_title,
                'roles' => $user->getRoleNames()->values(),
            ],
        ]);
    }

    /**
     * Plan ve limit kullanımı .
     *
     * Her okuyabilir: Personel de „bu ay kaç belge kestim" bilmeli; ama plan değiştirmek
     * bu sürümde API ile değil sunucu konfigürasyonu ile olur (bkz. config/plans.php).
     *
     * @response array{plan: string, label: string, period: string, limits: array{resource: string, label: string, used: int, limit: int|null, remaining: int|null}[]}
     */
    public function plan(Request $request)
    {
        $company = $request->user()->currentCompany;

        return response()->json(PlanLimits::for($company)->snapshot($company));
    }

    protected function present(Company $company): array
    {
        $data = $company->only([
            'id', 'name', 'slug', 'tax_number', 'tax_office', 'email', 'phone',
            'address', 'city', 'country', 'currency_code', 'plan', 'logo_path',
        ]);
        $data['logo_url'] = $company->logo_path ? Storage::disk('public')->url($company->logo_path) : null;

        return $data;
    }

    protected function authorizeAdmin(Request $request): void
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
