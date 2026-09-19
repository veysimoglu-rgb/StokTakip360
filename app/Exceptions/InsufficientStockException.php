<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an order asks for more than is in stock and the user has not
 * yet explicitly confirmed saving it anyway. Carries the per-product
 * shortages so the UI can list them.
 */
class InsufficientStockException extends RuntimeException
{
    /**
     * @param  array<int, array{product: string, requested: float, available: float, shortfall: float}>  $shortages
     */
    public function __construct(string $message, public readonly array $shortages = [])
    {
        parent::__construct($message);
    }
}
