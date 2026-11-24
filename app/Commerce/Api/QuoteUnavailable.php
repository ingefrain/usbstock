<?php

namespace App\Commerce\Api;

use RuntimeException;

final class QuoteUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cotización no encontrada');
    }
}
