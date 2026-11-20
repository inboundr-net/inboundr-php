<?php

declare(strict_types=1);

namespace Inboundr;

use Inboundr\Exception\SignatureException;
use Inboundr\Generated\Types;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Webhook signature verification.
 *
 * Every delivery carries `x-inboundr-signature: t=<unix>,v1=<hex>`, where v1 is
 * HMAC-SHA256(secret, "{t}.{rawBody}"). `x-inboundr-id-signature` signs
 * "ids.{t}.{deliveryId}.{emailId}" with the same secret, for deliveries whose
 * body was dropped. The legacy `x-inbound-*` names carry identical values and
 * are read as a fallback.
 *
 * Headers can be given as a PSR-7 message, an object with a `get()` method
 * (Symfony/Laravel `$request->headers`), a plain array (case-insensitive;
 * `getallheaders()` or PSR-7 `getHeaders()`), or `$_SERVER`.
 *
 * @phpstan-import-type EmailReceivedEvent from Types
 * @phpstan-type HeadersLike MessageInterface|object|array<array-key, mixed>
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'x-inboundr-signature';
    public const EVENT_HEADER = 'x-inboundr-event';
    public const DELIVERY_ID_HEADER = 'x-inboundr-delivery-id';
    public const EMAIL_ID_HEADER = 'x-inboundr-email-id';
    public const ID_SIGNATURE_HEADER = 'x-inboundr-id-signature';
    public const LEGACY_SIGNATURE_HEADER = 'x-inbound-signature';
    public const LEGACY_DELIVERY_ID_HEADER = 'x-inbound-delivery-id';
    public const LEGACY_EVENT_HEADER = 'x-inbound-event';
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify a delivery and return the event.
     *
     * @param string $payload The raw request body, exactly as received.
     * @param HeadersLike|string|null $signature The request's headers, or the signature header's value.
     * @return EmailReceivedEvent
     * @throws SignatureException Respond with 400.
     */
    public static function verify(
        string $payload,
        mixed $signature,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        self::requireSecret($secret);
        $header = is_string($signature) || $signature === null ? $signature : self::readHeaders($signature)->signature;
        $header = self::requireHeader($header, self::SIGNATURE_HEADER);
        if ($payload === '') {
            throw new SignatureException(
                'missing_body',
                'The request has a signature but an empty body; the host may have dropped it. '
                . 'Use Webhook::verifyIds() and fetch the email with $client->emails->get($emailId).',
            );
        }
        self::verifyMac($header, self::SIGNATURE_HEADER, $secret, static fn (int $t): string => "$t.$payload", $toleranceSeconds, $now);

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureException('invalid_payload', 'Webhook body is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($event) || ($event['event'] ?? null) !== 'email.received' || !is_array($event['email'] ?? null)) {
            throw new SignatureException('invalid_payload', 'Webhook body is not an event this version of the SDK knows.');
        }
        /** @var EmailReceivedEvent */
        return $event;
    }

    /**
     * Verify a PSR-7 server request: its body and its headers.
     *
     * @return EmailReceivedEvent
     * @throws SignatureException Respond with 400.
     */
    public static function verifyRequest(
        ServerRequestInterface $request,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): array {
        return self::verify((string) $request->getBody(), $request, $secret, $toleranceSeconds, $now);
    }

    /**
     * Verify a delivery's body and its id signature, and return the event with
     * a delivery id that is safe to deduplicate retries on.
     *
     * The body signature covers only the body, so a delivery id read straight
     * from the headers could be changed by someone replaying a captured
     * delivery. This also checks `x-inboundr-id-signature`, and that it names
     * the same email as the body.
     *
     * @param string $payload The raw request body, exactly as received.
     * @param HeadersLike $headers
     * @throws SignatureException Respond with 400.
     */
    public static function verifyDelivery(
        string $payload,
        mixed $headers,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookDelivery {
        $event = self::verify($payload, $headers, $secret, $toleranceSeconds, $now);
        $ids = self::verifyIds($headers, $secret, $toleranceSeconds, $now);
        if ($ids->emailId !== $event['email']['id']) {
            throw new SignatureException(
                'invalid_signature',
                'The id signature names a different email than the body.',
            );
        }
        return new WebhookDelivery($event, $ids->deliveryId);
    }

    /**
     * Verify the id signature of a delivery whose body was dropped.
     *
     * It proves only that Inboundr sent a delivery naming this email, so the one
     * safe use of the result is fetching the email through the API.
     *
     * @param HeadersLike $headers
     * @throws SignatureException Respond with 400.
     */
    public static function verifyIds(
        mixed $headers,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookIds {
        self::requireSecret($secret);
        $read = self::readHeaders($headers);
        if ($read->deliveryId === null || $read->deliveryId === '' || $read->emailId === null || $read->emailId === '') {
            throw new SignatureException(
                'missing_header',
                'Request is missing ' . self::DELIVERY_ID_HEADER . ' or ' . self::EMAIL_ID_HEADER . '.',
            );
        }
        $header = self::requireHeader($read->idSignature, self::ID_SIGNATURE_HEADER);
        [$deliveryId, $emailId] = [$read->deliveryId, $read->emailId];
        self::verifyMac(
            $header,
            self::ID_SIGNATURE_HEADER,
            $secret,
            static fn (int $t): string => "ids.$t.$deliveryId.$emailId",
            $toleranceSeconds,
            $now,
        );
        return new WebhookIds($deliveryId, $emailId);
    }

    /**
     * Read the delivery headers, preferring `x-inboundr-*` over the legacy names.
     * Use it for the delivery id when deduplicating retries.
     *
     * @param HeadersLike $headers
     */
    public static function readHeaders(mixed $headers): WebhookHeaders
    {
        $get = self::headerGetter($headers);
        return new WebhookHeaders(
            signature: $get(self::SIGNATURE_HEADER) ?? $get(self::LEGACY_SIGNATURE_HEADER),
            idSignature: $get(self::ID_SIGNATURE_HEADER),
            deliveryId: $get(self::DELIVERY_ID_HEADER) ?? $get(self::LEGACY_DELIVERY_ID_HEADER),
            emailId: $get(self::EMAIL_ID_HEADER),
            event: $get(self::EVENT_HEADER) ?? $get(self::LEGACY_EVENT_HEADER),
        );
    }

    /** @return \Closure(string): ?string */
    private static function headerGetter(mixed $headers): \Closure
    {
        if ($headers instanceof MessageInterface) {
            return static function (string $name) use ($headers): ?string {
                $value = $headers->getHeaderLine($name);
                return $value === '' ? null : $value;
            };
        }
        // A whole Symfony/Laravel Request: its get() reads query and body
        // input, not headers, so use its header bag instead.
        if (is_object($headers) && isset($headers->headers) && is_object($headers->headers)) {
            $headers = $headers->headers;
        }
        if (is_object($headers) && method_exists($headers, 'get')) {
            return static function (string $name) use ($headers): ?string {
                $value = $headers->get($name);
                return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
            };
        }
        if ($headers === null || $headers === false) {
            // getallheaders() returns false on some servers: no headers at all.
            $headers = [];
        }
        if (!is_array($headers)) {
            throw new SignatureException(
                'missing_header',
                'Headers must be a PSR-7 message, a header bag with get(), or an array; got ' . get_debug_type($headers) . '.',
            );
        }
        $lower = [];
        foreach ($headers as $key => $value) {
            $name = strtolower((string) $key);
            // $_SERVER style: HTTP_X_INBOUNDR_SIGNATURE.
            if (str_starts_with($name, 'http_')) {
                $name = str_replace('_', '-', substr($name, 5));
            }
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_scalar($value)) {
                $lower[$name] ??= (string) $value;
            }
        }
        return static fn (string $name): ?string => isset($lower[$name]) && $lower[$name] !== '' ? $lower[$name] : null;
    }

    private static function requireSecret(string $secret): void
    {
        if ($secret === '') {
            throw new SignatureException(
                'missing_secret',
                'No signing secret was provided. It is the whsec_ value returned when the endpoint was created.',
            );
        }
    }

    private static function requireHeader(?string $value, string $name): string
    {
        if ($value === null || $value === '') {
            throw new SignatureException(
                'missing_header',
                "Request has no $name header. If you are behind a proxy, check that it forwards it.",
            );
        }
        return $value;
    }

    /** @param \Closure(int): string $message */
    private static function verifyMac(string $header, string $name, string $secret, \Closure $message, int $tolerance, ?int $now): void
    {
        $timestamp = null;
        $macs = [];
        foreach (explode(',', $header) as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($part, 0, $eq));
            $value = trim(substr($part, $eq + 1));
            if ($key === 't') {
                // Unix seconds; bounded so a huge value can't overflow to a float.
                if (preg_match('/^[0-9]{1,15}$/', $value) !== 1) {
                    $timestamp = null;
                    break;
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-fA-F]+$/', $value) === 1) {
                $macs[] = strtolower($value);
            }
        }
        if ($timestamp === null || $macs === []) {
            throw new SignatureException('malformed_header', "Could not parse the $name header. Expected \"t=<unix>,v1=<hex>\".");
        }

        $expected = hash_hmac('sha256', $message($timestamp), $secret);
        $matched = false;
        foreach ($macs as $mac) {
            $matched = hash_equals($expected, $mac) || $matched;
        }
        if (!$matched) {
            throw new SignatureException(
                'invalid_signature',
                'Webhook signature did not match. Check the endpoint\'s secret, and pass the raw request body rather than re-encoded JSON.',
            );
        }

        $age = abs(($now ?? time()) - $timestamp);
        if ($age > $tolerance) {
            throw new SignatureException(
                'timestamp_out_of_tolerance',
                "Webhook signature is valid but was made {$age}s ago, outside the {$tolerance}s window. Check this machine's clock.",
            );
        }
    }
}
