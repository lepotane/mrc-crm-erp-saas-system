<?php

namespace App\Integration\Support;

use App\Models\Warehouse;

/**
 * Tsoft ile eşleşen tek depoyu çözümleyen paylaşılan yardımcı.
 *
 * İzolasyon sözleşmesi: yalnızca bu depo Tsoft ile senkronize olur. Diğer
 * depolar (ör. gelecekteki "Mobil Araç Deposu") MRC içinde kalır; Tsoft'a
 * ne okunur ne yazılır. Hedef çözümlenemezse fail-closed davranılır.
 */
final class TsoftWarehouse
{
    /**
     * Tsoft'un eşleştiği depo id'si.
     *
     * Önce yapılandırılmış warehouse_id, firma için geçerliyse kullanılır;
     * değilse firmanın varsayılan deposuna düşülür; o da yoksa null döner.
     */
    public static function targetId(int $companyId): ?int
    {
        $configured = config('integrations.channels.tsoft.warehouse_id');

        if ($configured !== null && $configured !== '' && (int) $configured > 0) {
            $id = (int) $configured;

            if (Warehouse::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->exists()) {
                return $id;
            }
        }

        $default = Warehouse::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        return $default === null ? null : (int) $default;
    }

    /**
     * Verilen depo Tsoft hedefiyse true.
     *
     * Hedef çözümlenemediğinde (null) veya depo id'si verilmediğinde false
     * döner; böylece belirsiz durumda yazma/okuma yapılmaz (fail-closed).
     */
    public static function isTsoftWarehouse(int $companyId, ?int $warehouseId): bool
    {
        if ($warehouseId === null) {
            return false;
        }

        $target = self::targetId($companyId);

        return $target !== null && $target === $warehouseId;
    }
}
