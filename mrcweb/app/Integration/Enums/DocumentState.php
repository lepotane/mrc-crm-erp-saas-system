<?php

namespace App\Integration\Enums;

/**
 * Mali belge yaşam döngüsü (karar K6).
 * draft = gayri resmi, official = resmileştirilmiş.
 */
enum DocumentState: string
{
    case Draft = 'draft';
    case OfficialPending = 'official_pending';
    case Official = 'official';
    case Cancelled = 'cancelled';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isOfficial(): bool
    {
        return $this === self::Official;
    }
}
