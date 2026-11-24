<?php

$reviewRoot = dirname(__DIR__, 2).'/storage/app/private/qa/visual-gsap';
$reviewPort = 4392;
$reviewCookie = 'usbstock_visual_gsap_review';
$reviewCanInitialize = PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'prepare.php';

$app = require dirname(__DIR__).'/commercial-review/bootstrap.php';

// These values are consumed only by this disposable preview instance.
config(['commerce.visual_snapshot' => true]);

return $app;
