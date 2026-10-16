<?php

namespace App\Services;

use RuntimeException;

class OpenRouterProviderException extends RuntimeException
{
    /**
     * @param  array<string, int|float|string|bool|null>  $diagnostics
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        ?\Throwable $previous = null,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct($message, 0, $previous);
    }
}
