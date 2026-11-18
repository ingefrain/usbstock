<?php

$reviewRoot = dirname(__DIR__, 2).'/storage/app/private/taxonomy-20261118/corporate-pricing/qa';
$reviewPort = 4390;
$reviewCookie = 'usbstock_pricing_review';
$reviewCanInitialize = PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'qa-prepare.php';

return require dirname(__DIR__).'/commercial-review/bootstrap.php';
