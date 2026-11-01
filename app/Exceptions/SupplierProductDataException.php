<?php

namespace App\Exceptions;

use RuntimeException;

class SupplierProductDataException extends RuntimeException
{
    /** @param array<int, string> $missingFields */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $missingFields = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<int, string> $missingFields */
    public static function priceUnavailable(array $missingFields = ['price']): self
    {
        return new self(
            'The supplier did not provide a reliable price and currency for this product.',
            'supplier_price_unavailable',
            $missingFields,
        );
    }
}
