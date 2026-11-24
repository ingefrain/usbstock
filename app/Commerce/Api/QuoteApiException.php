<?php

namespace App\Commerce\Api;

final class QuoteApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
