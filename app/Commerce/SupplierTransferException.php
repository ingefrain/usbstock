<?php

namespace App\Commerce;

final class SupplierTransferException extends \RuntimeException
{
    public function __construct(public readonly string $phase, public readonly string $reason, public readonly bool $retryable = false)
    {
        parent::__construct('No se pudo completar la transferencia del proveedor.');
    }
}
