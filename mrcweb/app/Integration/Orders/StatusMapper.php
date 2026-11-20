<?php

namespace App\Integration\Orders;

use App\Integration\Enums\Channel;
use App\Integration\Enums\SyncDirection;

/**
 * Durum çevirisi yalnızca kiracının integration_status_map tablosundan okunur.
 *
 * Eşleme bulunamazsa dış durum kimliği ASLA tahmin edilmez: içe alma için
 * 'draft' yedeği, dışa yazma için null döner ve uyarı sync_logs'a yazılır.
 */
final class StatusMapper
{
    /** Dış durum → MRC durum. Eşleme yoksa 'draft'. */
    public function toMrc(int $companyId, string $externalStatus): string
    {
        $mapped = StatusMap::query()
            ->where('company_id', $companyId)
            ->where('channel', Channel::Tsoft->value)
            ->where('external_status', $externalStatus)
            ->orderBy('id')
            ->value('mrc_status');

        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }

        $this->warn($companyId, SyncDirection::In, 'external_status');

        return 'draft';
    }

    /** MRC durum → dış durum. Eşleme yoksa null (dış yazım atlanır). */
    public function toExternal(int $companyId, string $mrcStatus): ?string
    {
        $mapped = StatusMap::query()
            ->where('company_id', $companyId)
            ->where('channel', Channel::Tsoft->value)
            ->where('mrc_status', $mrcStatus)
            ->orderBy('id')
            ->value('external_status');

        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }

        $this->warn($companyId, SyncDirection::Out, 'mrc_status');

        return null;
    }

    /** Uyarıda ham durum değeri veya kişisel veri yazılmaz. */
    private function warn(int $companyId, SyncDirection $direction, string $field): void
    {
        IntegrationAudit::log(
            $companyId,
            $direction,
            'status_map',
            null,
            'warning',
            ['reason' => 'unmapped_status', 'field' => $field],
        );
    }
}
