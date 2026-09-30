<?php

namespace App\Exceptions;

use RuntimeException;

class SupplierServiceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
