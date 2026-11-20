<?php

/*
 |--------------------------------------------------------------------------
 | Entegrasyon kanal ayarları (Tsoft / GİB / Luca)
 |--------------------------------------------------------------------------
 |
 | Tüm kanal davranışları tek yerden yönetilir. Kiracıya özel kimlik bilgileri
 | ASLA buraya yazılmaz — `tenant_integrations` tablosunda Crypt ile saklanır.
 |
 | dry_run=true iken adapter'lar dış servise YAZMAZ, yalnızca loglar.
 | İlk canlı denemeler mutlaka dry_run ile başlar.
 |
 */

return [
    'dry_run' => env('MRC_INTEGRATION_DRY_RUN', true),

    'channels' => [
        'tsoft' => [
            'enabled' => env('MRC_TSOFT_ENABLED', false),
            'poll_minutes' => (int) env('MRC_TSOFT_POLL_MINUTES', 5),
            'max_per_minute' => (int) env('MRC_TSOFT_MAX_PER_MINUTE', 60),
        ],
        'gib' => [
            'enabled' => env('MRC_GIB_ENABLED', false),
            'out_dir' => 'integrations/gib/out',
            'in_dir' => 'integrations/gib/in',
        ],
        'luca' => [
            'enabled' => env('MRC_LUCA_ENABLED', false),
            'out_dir' => 'integrations/luca/out',
            'csv_rows_per_file' => (int) env('MRC_LUCA_CSV_ROWS', 400),
            'csv_encoding' => env('MRC_LUCA_CSV_ENCODING', 'Windows-1254'),
        ],
    ],

    'outbox' => [
        'batch' => (int) env('MRC_OUTBOX_BATCH', 100),
        'max_attempts' => 5,
        'backoff_minutes' => [1, 5, 30, 120, 720],
    ],

    'webhook' => [
        'tolerance_seconds' => (int) env('MRC_WEBHOOK_TOLERANCE', 300),
        'dedupe_hours' => (int) env('MRC_WEBHOOK_DEDUPE_HOURS', 24),
    ],
];
