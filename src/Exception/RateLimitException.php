<?php

declare(strict_types=1);

namespace ElevenElo\Exception;

/**
 * Thrown when the daily rate limit for the API key tier has been exceeded (HTTP 429).
 *
 * The $resetAt property contains the value of the X-RateLimit-Reset header, if present.
 */
class RateLimitException extends ElevenEloException
{
    public function __construct(
        string $message,
        public readonly ?string $resetAt = null,
    ) {
        parent::__construct($message);
    }
}
