<?php

declare(strict_types=1);

namespace Inboundr\Exception;

/**
 * The API answered with a non-2xx status. `body` is the decoded JSON, or the
 * raw text when it wasn't JSON; a 400's body holds the validation tree naming
 * the field that failed.
 */
class ApiException extends InboundrException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly mixed $body,
        public readonly ?string $requestId,
    ) {
        parent::__construct($message, $status);
    }
}
