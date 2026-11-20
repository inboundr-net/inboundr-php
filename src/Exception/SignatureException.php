<?php

declare(strict_types=1);

namespace Inboundr\Exception;

/**
 * A webhook request that could not be verified. Respond with 400.
 *
 * `reason` is for logs: missing_secret, missing_header, malformed_header,
 * invalid_signature, timestamp_out_of_tolerance, missing_body or
 * invalid_payload. Don't use it to decide whether to trust a request; a forged
 * one can choose its own failure.
 */
final class SignatureException extends InboundrException
{
    /**
     * @param 'missing_secret'|'missing_header'|'malformed_header'|'invalid_signature'|'timestamp_out_of_tolerance'|'missing_body'|'invalid_payload' $reason
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
