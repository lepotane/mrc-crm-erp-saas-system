<?php

namespace App\Dashboard;

use App\Models\Company;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Modules\ModuleRegistry;

/**
 * Widget görünürlük + yerleşim çözümleyici .
 *
 * Cascade (önce eşleşen kazanır):
 *  1. kullanıcı satırı (scope=user, subject=kullanıcı id)
 *  2. rol satırları (scope=role, subject=rol adı; kullanıcının rolleri sırayla)
 *  3. firma satırı (scope=company, subject=NULL)
 *  4. katalog varsayılanı
 *
 * „Yokluk = miras"tır: satırın olmaması „kapalı" demek değildir, bir üst karar
 * geçer. Havuz kuralı ayrıca uygulanır: katalogda rolüne kapalı widget kullanıcıya
 * ASLA gösterilmez (kullanıcı satırı bile olsa — havuz dışına kayıt yazılamaz).
 */
class DashboardLayout
{
    /**
     * Çözümlenmiş ızgara: görünür widget'lar (sıralı) + her birinin verisi.
     *
     * @return array<int, array{key: string, title_key: string, size: string, sort: int, options: array, data: array}>
     */
    public static function grid(Company $company, User $user): array
    {
        $roles = $user->getRoleNames()->all();
        $out = [];

        foreach (WidgetRegistry::catalog($company) as $key => $def) {
            // Havuz: katalogda rolü yoksa kullanıcı ne yaparsa yapsın görünmez.
            if (array_intersect($roles, $def['roles']) === []) {
                continue;
            }

            // Kapalı modülün widget'ı ızgaraya girmez (satırlar durur, veri korunur).
            if (! ModuleRegistry::isEnabled($company->id, (string) ($def['module'] ?? 'pano'))) {
                continue;
            }

            $row = static::rowFor($company->id, $user->id, $roles, $key);

            $enabled = $row ? (bool) $row->enabled : (bool) $def['default_enabled'];

            if (! $enabled) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'title_key' => $def['title_key'],
                'size' => $def['size'],
                'sort' => $row ? (int) $row->sort_order : 0,
                'options' => $row?->options ?? [],
                'data' => WidgetRegistry::data($key, $company),
            ];
        }

        usort($out, fn ($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['key'], $b['key']));

        return array_values($out);
    }

    /** Katalog + her seviyedeki mevcut satırlar (Admin düzenleme ekranı besler). */
    public static function catalogWithState(Company $company): array
    {
        $rows = DashboardWidget::where('company_id', $company->id)->get();

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->widget_key][$row->scope][$row->subject ?? '-'] = [
                'enabled' => (bool) $row->enabled,
                'sort' => (int) $row->sort_order,
                'options' => $row->options ?? [],
            ];
        }

        $out = [];

        foreach (WidgetRegistry::catalog($company) as $key => $def) {
            $out[] = [
                'key' => $key,
                'title_key' => $def['title_key'],
                'description_key' => $def['description_key'],
                'default_enabled' => (bool) $def['default_enabled'],
                'roles' => $def['roles'],
                'size' => $def['size'],
                'state' => $byKey[$key] ?? [],
            ];
        }

        return $out;
    }

    protected static function rowFor(int $companyId, int $userId, array $roles, string $key): ?DashboardWidget
    {
        $user = DashboardWidget::where('company_id', $companyId)
            ->where('scope', DashboardWidget::SCOPE_USER)
            ->where('subject', (string) $userId)
            ->where('widget_key', $key)
            ->first();

        if ($user !== null) {
            return $user;
        }

        foreach ($roles as $role) {
            $roleRow = DashboardWidget::where('company_id', $companyId)
                ->where('scope', DashboardWidget::SCOPE_ROLE)
                ->where('subject', $role)
                ->where('widget_key', $key)
                ->first();

            if ($roleRow !== null) {
                return $roleRow;
            }
        }

        return DashboardWidget::where('company_id', $companyId)
            ->where('scope', DashboardWidget::SCOPE_COMPANY)
            ->where('widget_key', $key)
            ->first();
    }
}
