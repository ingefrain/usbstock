<?php

namespace App\Commerce;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class CartAvailabilityException extends HttpException
{
    public function __construct(public readonly int $maximum)
    {
        parent::__construct(409);
    }
}
