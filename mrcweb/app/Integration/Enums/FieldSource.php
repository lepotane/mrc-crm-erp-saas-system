<?php

namespace App\Integration\Enums;

/**
 * Sunum alanlarının hangi sistemden geleceğini belirler (karar K3).
 * Kimlik alanları (sku/barkod) bu bayrağa bakılmaksızın eşleme anahtarıdır.
 */
enum FieldSource: string
{
    case Mrc = 'mrc';
    case Tsoft = 'tsoft';
}
