<?php

namespace App\Commerce;

use Mpdf\AssetFetcher;
use Mpdf\Exception\AssetFetchingException;

final class BlockedPdfAssets extends AssetFetcher
{
    public function __construct() {}

    public function fetchDataFromPath($path, $originalSrc = null)
    {
        throw new AssetFetchingException('External PDF assets are disabled.');
    }
}
