<?php

namespace App\Http\Controllers\Api\V1;

use App\Dashboard\DashboardLayout;
use App\Dashboard\WidgetRegistry;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard widget ızgarası .
 *
 * - `GET /dashboard/widgets` — o anki kullanıcı/firma için çözümlenmiş ızgara
 *   (görünür widget + verisi). Her üye okuyabilir (Personel kendi havuzunu görür).
 * - `GET /dashboard/widget-catalog` — YALNIZ Admin: katalog + seviye satırları.
 * - `PUT /dashboard/layout` — kullanıcı kendi yerleşimini yazar (havuz içinden;
 *   katalog dışı/havuz dışı anahtar 422).
 * - `PATCH /dashboard/scopes` — YALNIZ Admin: firma varsayılanı + rol havuzları.
 */
class DashboardController extends Controller
{
    use Concerns\ResolvesTenant;

    /**
     * Çözümlenmiş ızgara (sıralı widget + verisi).
     *
     * @response array{data: array<int, array{key: string, title_key: string, size: string, sort: int, options: array, data: array}>}
     */
    public function widgets(Request $request)
    {
        $company = $this->tenantCompany($request);

        return response()->json([
            'data' => DashboardLayout::grid($company, $request->user()),
        ]);
    }

    /**
     * Katalog + mevcut seviye satırları (Admin düzenleme ekranı).
     *
     * Üyeler kataloğun TANIMINI görür (kendi yerleşimlerini düzenlemek için şart)
     * ama seviye satırlarını (`state`) göremez — başkasının/rolün ayarı sızmaz.
     *
     * @response array{data: array<int, array{key: string, title_key: string, description_key: string, default_enabled: bool, roles: array, size: string, state: array}>}
     */
    public function catalog(Request $request)
    {
        $company = $this->tenantCompany($request);

        if (! $request->user()->hasRole('Admin')) {
            // Üye yalnız açık modüllerin tanımını görür (kapalı modül yerleşime giremez).
            //
            // DİKKAT: `key` alanı Admin dalıyla AYNI şekilde dönmeli. 07 Kas'ta
            // üye dalında `key` yoktu; istemci `c.key` okuyup `undefined`
            // gönderdiği için üyenin "Yerleşimi Kaydet" isteği 422 alıyordu.
            $acik = [];
            foreach (WidgetRegistry::catalog($company) as $key => $def) {
                if (! ModuleRegistry::isEnabled($company->id, (string) ($def['module'] ?? 'pano'))) {
                    continue;
                }

                $acik[] = array_merge(
                    array_diff_key($def, ['data' => true]),
                    ['key' => $key]
                );
            }

            return response()->json(['data' => $acik]);
        }

        return response()->json([
            'data' => DashboardLayout::catalogWithState($company),
        ]);
    }

    /**
     * Kendi yerleşimini yaz (havuz içinden; katalog dışı anahtar reddedilir).
     *
     * @response array{message: string}
     */
    public function layout(Request $request)
    {
        $company = $this->tenantCompany($request);
        $user = $request->user();

        $data = $request->validate([
            'widgets' => ['required', 'array', 'max:20'],
            'widgets.*.key' => ['required', 'string', Rule::in(WidgetRegistry::keys($company))],
            'widgets.*.enabled' => ['sometimes', 'boolean'],
            'widgets.*.sort' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'widgets.*.options' => ['sometimes', 'array'],
        ]);

        $roles = $user->getRoleNames()->all();
        $catalog = WidgetRegistry::catalog($company);

        foreach ($data['widgets'] as $i => $w) {
            // Havuz dışı kayıt YAZILAMAZ: kullanıcı kendine admin-özel widget açamaz.
            if (array_intersect($roles, $catalog[$w['key']]['roles']) === []) {
                throw ValidationException::withMessages([
                    "widgets.{$i}.key" => [__('Bu widget rolünüze kapalı.')],
                ]);
            }

            DashboardWidget::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'scope' => DashboardWidget::SCOPE_USER,
                    'subject' => (string) $user->id,
                    'widget_key' => $w['key'],
                ],
                [
                    'enabled' => $w['enabled'] ?? true,
                    'sort_order' => $w['sort'] ?? $i,
                    'options' => $w['options'] ?? null,
                ]
            );
        }

        return response()->json(['message' => __('Panel yerleşimi kaydedildi.')]);
    }

    /**
     * Firma varsayılanı + rol havuzlarını yaz (Admin).
     *
     * @response array{message: string}
     */
    public function scopes(Request $request)
    {
        $this->ensureAdmin($request);

        $company = $this->tenantCompany($request);

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:60'],
            'rows.*.scope' => ['required', 'string', Rule::in([DashboardWidget::SCOPE_COMPANY, DashboardWidget::SCOPE_ROLE])],
            'rows.*.subject' => ['nullable', 'string', 'max:80'],
            'rows.*.widget_key' => ['required', 'string', Rule::in(WidgetRegistry::keys($company))],
            'rows.*.enabled' => ['sometimes', 'boolean'],
            'rows.*.sort' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'rows.*.options' => ['sometimes', 'array'],
        ]);

        foreach ($data['rows'] as $i => $row) {
            if ($row['scope'] === DashboardWidget::SCOPE_ROLE && empty($row['subject'])) {
                throw ValidationException::withMessages([
                    "rows.{$i}.subject" => [__('Rol düzeyi için rol adı gerekli.')],
                ]);
            }

            DashboardWidget::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'scope' => $row['scope'],
                    'subject' => $row['scope'] === DashboardWidget::SCOPE_ROLE ? $row['subject'] : null,
                    'widget_key' => $row['widget_key'],
                ],
                [
                    'enabled' => $row['enabled'] ?? true,
                    'sort_order' => $row['sort'] ?? 0,
                    'options' => $row['options'] ?? null,
                ]
            );
        }

        return response()->json(['message' => __('Panel havuzları kaydedildi.')]);
    }

    protected function ensureAdmin(Request $request): void
    {
        if (! $request->user()->hasRole('Admin')) {
            abort(403, __('Bu işlem için yönetici yetkisi gereklidir.'));
        }
    }
}
