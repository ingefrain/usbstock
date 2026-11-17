<?php

// Loaded only by a PHPUnit test running in a separate PHP process.

namespace App\Commerce;

function curl_init(string $url): object
{
    $GLOBALS['supplier_curl_history'] ??= [];
    $GLOBALS['supplier_curl_double'] = (object) ['url' => $url, 'options' => [], 'number' => count($GLOBALS['supplier_curl_history']) % 3 + 1, 'closed' => false];
    $GLOBALS['supplier_curl_history'][] = $GLOBALS['supplier_curl_double'];

    return $GLOBALS['supplier_curl_double'];
}

function curl_setopt_array(object $handle, array $options): bool
{
    $handle->options = $options;

    return true;
}

function curl_exec(object $handle): bool
{
    $scenario = $GLOBALS['supplier_curl_scenario'] ?? [];
    $chunk = $scenario['bytes'] ?? "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST-A,0,,10";
    if (($scenario['failure_on'] ?? 0) === $handle->number) {
        if (! $handle->options[CURLOPT_NOBODY]) {
            ($handle->options[CURLOPT_WRITEFUNCTION])($handle, substr($chunk, 0, 12));
        }

        return false;
    }
    if ($handle->options[CURLOPT_NOBODY]) {
        return true;
    }

    return ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $chunk) === strlen($chunk);
}

function curl_getinfo(object $handle, int $option): int
{
    $scenario = $GLOBALS['supplier_curl_scenario'] ?? [];
    if ($option === CURLINFO_FILETIME_T) {
        return $handle->number === 3 ? ($scenario['modified_after'] ?? 1700000000) : ($scenario['modified_before'] ?? 1700000000);
    }
    $size = strlen($scenario['bytes'] ?? "SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST-A,0,,10");

    return $handle->number === 3 ? ($scenario['size_after'] ?? $size) : ($scenario['size_before'] ?? $size);
}

function curl_errno(object $handle): int
{
    return $GLOBALS['supplier_curl_scenario']['errno'] ?? CURLE_WRITE_ERROR;
}

function curl_close(object $handle): void
{
    $handle->closed = true;
}
