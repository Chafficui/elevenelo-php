<?php

declare(strict_types=1);

namespace ElevenElo\Exception;

/**
 * Thrown when the API key is missing or invalid (HTTP 401).
 */
class AuthenticationException extends ElevenEloException
{
}
