<?php

declare(strict_types=1);

namespace Inboundr\Exception;

/** A 429. `retryAfterSeconds` comes from the `retry-after` header. */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        mixed $body,
        ?string $requestId,
        public readonly ?float $retryAfterSeconds,
    ) {
        parent::__construct($message, 429, $body, $requestId);
    }
}
