<?php

return [
    'notify_email' => env('COMMERCE_NOTIFY_EMAIL'),
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
        'ftp_tls' => (bool) env('SUPPLIER_FTP_TLS', true),
    ],
];
