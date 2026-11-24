<?php

return [
    'enabled' => (bool) env('USBSTOCK_API_ENABLED', false),
    'bearer_token' => env('USBSTOCK_API_BEARER_TOKEN'),
    'consumer' => 'grupotalento',
    // Explicit grants. An empty list denies every quote, including issued quotes.
    'allowed_quote_uuids' => array_values(array_filter(array_map('trim', explode(',', (string) env('USBSTOCK_API_ALLOWED_QUOTE_UUIDS', ''))))),
    'environment' => env('USBSTOCK_API_ENVIRONMENT', 'local'),
    'base_url' => env('USBSTOCK_API_BASE_URL', 'http://127.0.0.1:8001'),
    'pdf_enabled' => (bool) env('USBSTOCK_API_PDF_ENABLED', false),
    'read_rate_limit' => (int) env('USBSTOCK_API_READ_RATE_LIMIT', 60),
    'pdf_rate_limit' => (int) env('USBSTOCK_API_PDF_RATE_LIMIT', 30),
    'ip_rate_limit' => (int) env('USBSTOCK_API_IP_RATE_LIMIT', 120),
];
