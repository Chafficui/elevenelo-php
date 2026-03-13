<?php

declare(strict_types=1);

namespace ElevenElo\Exception;

/**
 * Thrown for unexpected API errors (any non-2xx response not covered above).
 *
 * The $statusCode property holds the HTTP status code.
 */
class ApiException extends ElevenEloException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
    ) {
        parent::__construct($message);
    }
}
