<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aktif firmayı (tenant = spatie team) Role/Permission sorgularına bağlar.
 * auth:sanctum sonrası çalışır; kullanıcının firmaya üyeliği, firma onay durumu
 * ve abonelik/kullanım süresi sıkı biçimde doğrulanır.
 */
class CompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user instanceof User) {
            if ($user->isSuperAdmin()) {
                if (! $user->current_company_id) {
                    $firstId = Company::orderBy('id')->value('id');
                    $user->forceFill(['current_company_id' => $firstId])->save();
                }
            } else {
                if ($user->current_company_id && ! $user->companies()->whereKey($user->current_company_id)->exists()) {
                    // Üyelik yoksa (başka firmaya erişim girişimi veya düşen üyelik) bağlamı sıfırla
                    $fallbackId = $user->companies()->orderBy('companies.id')->value('companies.id');
                    $user->forceFill(['current_company_id' => $fallbackId])->save();
                }
            }

            if ($user->current_company_id) {
                /** @var Company|null $company */
                $company = Company::find($user->current_company_id);

                if ($company && ! $user->isSuperAdmin()) {
                    $relPath = trim($request->path(), '/');
                    $exemptPaths = ['api/v1/me', 'api/v1/auth/logout', 'api/v1/companies', 'api/v1/companies/select'];

                    if (! in_array($relPath, $exemptPaths, true)) {
                        if ($company->approval_status === 'pending') {
                            return response()->json([
                                'message' => __('Firmanız henüz sistem yöneticisi tarafından onaylanmadı. Onaylandığında erişim açılacaktır.'),
                                'code' => 'company_pending_approval',
                            ], 403);
                        }

                        if ($company->approval_status === 'suspended' || $company->approval_status === 'rejected') {
                            return response()->json([
                                'message' => __('Firma hesabınızın erişimi kısıtlanmıştır. Sistem yöneticisiyle iletişime geçin.'),
                                'code' => 'company_suspended',
                            ], 403);
                        }

                        if ($company->isSubscriptionExpired()) {
                            return response()->json([
                                'message' => __('Firmanızın kullanım süresi dolmuştur. Süre uzatımı için yöneticiyle iletişime geçin.'),
                                'code' => 'subscription_expired',
                            ], 403);
                        }
                    }
                }

                app(PermissionRegistrar::class)->setPermissionsTeamId((int) $user->current_company_id);
            }
        }

        return $next($request);
    }
}
