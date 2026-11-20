<?php

namespace App\Integration\Enums;

/**
 * Kanal başına somut uygulama seçimi. Mağaza kaydında tutulur; kodda sabit değildir.
 */
enum Driver: string
{
    // Tsoft
    case Rest1 = 'rest1';
    case Admin2026 = 'admin2026-01';

    // Luca
    case File = 'file';
    case Rest = 'rest';
    case Soap = 'soap';

    // GIB
    case UblFile = 'ubl-file';
}
