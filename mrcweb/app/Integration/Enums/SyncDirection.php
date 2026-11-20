<?php

namespace App\Integration\Enums;

enum SyncDirection: string
{
    case In = 'in';   // dış sistem -> MRC
    case Out = 'out'; // MRC -> dış sistem
}
