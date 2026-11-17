<?php

// Loaded only by a PHPUnit test running in a separate PHP process.

namespace App\Commerce;

function curl_init(string $url): object
{
    $GLOBALS['supplier_curl_double'] = (object) ['url' => $url, 'options' => []];

    return $GLOBALS['supplier_curl_double'];
}

function curl_setopt_array(object $handle, array $options): bool
{
    $handle->options = $options;

    return true;
}

function curl_exec(object $handle): bool
{
    $chunk = "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST-A,0,,10";

    return ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $chunk) === strlen($chunk);
}

function curl_close(object $handle): void {}
