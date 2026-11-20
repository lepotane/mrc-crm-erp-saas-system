<?php

namespace App\Integration\Sync;

use App\Integration\Enums\Channel;
use App\Models\TenantIntegration;

/** Komutların aktif Tsoft firmalarını tek yerden çözmesi için ortak yardımcı. */
trait InteractsWithActiveTsoftTenants
{
    /**
     * @return array<int,int>
     */
    protected function activeTsoftCompanyIds(?int $companyId): array
    {
        if ($companyId !== null) {
            return [$companyId];
        }

        return TenantIntegration::query()
            ->where('channel', Channel::Tsoft->value)
            ->where('status', TenantIntegration::STATUS_ACTIVE)
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }
}
