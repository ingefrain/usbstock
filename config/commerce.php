<?php

return [
    'catalog_block_size' => 24,
    'notify_email' => env('COMMERCE_NOTIFY_EMAIL'),
    'telegram_enabled' => (bool) env('SUPPLIER_TELEGRAM_ENABLED', false),
    'environment_label' => env('SUPPLIER_ENVIRONMENT_LABEL', env('APP_ENV', 'local')),
    'sync' => [
        'max_bytes' => 8 * 1024 * 1024,
        'max_rows' => 20000,
        'max_missing_percent' => 20,
        'max_price_change_percent' => 50,
        'max_stock_drop_percent' => 80,
        'enabled' => (bool) env('SUPPLIER_SYNC_ENABLED', false),
        'auto_apply' => (bool) env('SUPPLIER_SYNC_AUTO_APPLY', false),
        'actor_id' => env('SUPPLIER_SYNC_ACTOR_ID'),
        'ftp_enabled' => (bool) env('SUPPLIER_FTP_ENABLED', false),
        'ftp_host' => env('SUPPLIER_FTP_HOST'),
        'ftp_port' => (int) env('SUPPLIER_FTP_PORT', 21),
        'ftp_user' => env('SUPPLIER_FTP_USER'),
        'ftp_password' => env('SUPPLIER_FTP_PASSWORD'),
        'ftp_path' => env('SUPPLIER_FTP_PATH'),
        // Must be explicitly selected after checking the supplier contract. Never silently downgrade TLS.
        'ftp_protocol' => env('SUPPLIER_FTP_PROTOCOL'),
        'download_attempts' => (int) env('SUPPLIER_DOWNLOAD_ATTEMPTS', 2),
        'trial_database' => env('SUPPLIER_TRIAL_DATABASE'),
        'trial_allow_plaintext' => (bool) env('SUPPLIER_TRIAL_ALLOW_PLAINTEXT', false),
        'deactivate_missing' => (bool) env('SUPPLIER_DEACTIVATE_MISSING', false),
    ],
];
