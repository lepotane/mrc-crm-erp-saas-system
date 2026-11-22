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
            'overlap_minutes' => (int) env('MRC_TSOFT_OVERLAP_MINUTES', 5),
            'drift_limit' => (int) env('MRC_TSOFT_DRIFT_LIMIT', 100),
            'currency' => env('MRC_TSOFT_DEFAULT_CURRENCY', 'TRY'),
            'payment_write_enabled' => env('MRC_TSOFT_PAYMENT_WRITE', false),
            'payment_push_hours' => (int) env('MRC_TSOFT_PAYMENT_PUSH_HOURS', 24),
            // Yazmalar opt-in'dir; dry_run (integrations.dry_run) yine master gate'tir.
            'catalog_write_enabled' => env('MRC_TSOFT_CATALOG_WRITE', false),
            'stock_write_enabled' => env('MRC_TSOFT_STOCK_WRITE', false),
            'order_write_enabled' => env('MRC_TSOFT_ORDER_WRITE', false),
            'warehouse_id' => env('MRC_TSOFT_WAREHOUSE_ID', null),
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

    // Ürün adı çevirisi (MyMemory — salt okunur, Tsoft'a yazmaz).
    'translation' => [
        'provider' => env('MRC_TRANSLATION_PROVIDER', 'mymemory'),
        'email' => env('MRC_TRANSLATION_EMAIL', 'info@mrc.local'),
        'delay_ms' => (int) env('MRC_TRANSLATION_DELAY_MS', 120),
    ],
];
