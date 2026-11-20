<?php

namespace App\Integration\Orders;

use App\Integration\Enums\Channel;
use App\Integration\Enums\SyncDirection;
use Illuminate\Support\Facades\DB;

/**
 * integration_id_map ve sync_logs tablolarına tek noktadan yazma/okuma.
 *
 * Idempotency anahtarı her zaman (company_id, external_type, external_key)
 * üçlüsüdür; mrc_type/mrc_id yalnızca eşlemenin hedefini tutar.
 */
final class IntegrationAudit
{
    public const TYPE_ORDER = 'order';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_RETURN = 'return';

    public const MRC_DOCUMENT = 'document';

    public const MRC_PAYMENT = 'payment';

    public static function findMap(
        int $companyId,
        string $externalType,
        string $externalKey,
        bool $lock = false,
    ): ?object {
        $query = DB::table('integration_id_map')
            ->where('company_id', $companyId)
            ->where('external_type', $externalType)
            ->where('external_key', $externalKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('id')->first();
    }

    public static function remember(
        int $companyId,
        string $mrcType,
        int $mrcId,
        string $externalType,
        string $externalKey,
    ): void {
        DB::table('integration_id_map')->updateOrInsert(
            [
                'company_id' => $companyId,
                'mrc_type' => $mrcType,
                'mrc_id' => $mrcId,
                'external_type' => $externalType,
                'external_key' => $externalKey,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string,mixed>|null  $preview  yalnızca maskelenmiş / kişisel veri içermeyen alanlar
     */
    public static function log(
        int $companyId,
        SyncDirection $direction,
        string $entity,
        ?string $externalRef,
        string $status,
        ?array $preview = null,
        ?int $durationMs = null,
    ): void {
        DB::table('sync_logs')->insert([
            'company_id' => $companyId,
            'channel' => Channel::Tsoft->value,
            'direction' => $direction->value,
            'entity' => mb_substr($entity, 0, 60),
            'external_ref' => $externalRef === null ? null : mb_substr($externalRef, 0, 190),
            'duration_ms' => $durationMs,
            'status' => mb_substr($status, 0, 20),
            'preview' => $preview === null
                ? null
                : json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
