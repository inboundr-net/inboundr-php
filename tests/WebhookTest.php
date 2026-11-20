<?php

declare(strict_types=1);

namespace Inboundr\Tests;

use Inboundr\Exception\SignatureException;
use Inboundr\Webhook;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook verification, cross-checked against an independent signer.
 *
 * The signing scheme, which the platform implements in another repository:
 *
 *     signature    = "t=" . unix . ",v1=" . hex(hmac_sha256(secret, "{unix}.{rawBody}"))
 *     id signature = "t=" . unix . ",v1=" . hex(hmac_sha256(secret, "ids.{unix}.{deliveryId}.{emailId}"))
 *
 * carried in `x-inboundr-signature` (also the legacy `x-inbound-signature`) and
 * `x-inboundr-id-signature`. sign() and signIds() implement it on their own, so
 * a pass means the SDK and the spec agree. Changing the scheme is a breaking change.
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_HXK9y2sVQ4mZ1pTn8LrJdCwB';
    private const NOW = 1_770_000_000;
    private const DELIVERY_ID = 'del_4hQx8w';
    private const EMAIL_ID = 'em_7Kq2mR';

    private static function body(string $subject = 'Re: invoice 4471'): string
    {
        return json_encode([
            'event' => 'email.received',
            'email' => [
                'id' => self::EMAIL_ID, 'threadId' => 'thr_9Fj4nP', 'messageId' => null,
                'from' => 'customer@example.com', 'to' => ['agent@mail.example.com'], 'cc' => [],
                'recipients' => ['agent@mail.example.com'], 'subject' => $subject, 'text' => 'Any update?',
                'html' => null, 'attachments' => [], 'verdicts' => ['spam' => 'PASS', 'spf' => 'PASS', 'dkim' => 'PASS'],
                'receivedAt' => '2026-02-02T03:20:00.000Z',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function sign(string $body, int $t = self::NOW, string $secret = self::SECRET): string
    {
        return "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret);
    }

    private static function signIds(int $t = self::NOW, string $emailId = self::EMAIL_ID): string
    {
        return "t=$t,v1=" . hash_hmac('sha256', 'ids.' . $t . '.' . self::DELIVERY_ID . ".$emailId", self::SECRET);
    }

    private function rejects(string $reason, \Closure $call): void
    {
        try {
            $call();
        } catch (SignatureException $e) {
            self::assertSame($reason, $e->reason);
            return;
        }
        self::fail("Expected SignatureException($reason)");
    }

    public function testASignatureFromTheSpecVerifies(): void
    {
        $event = Webhook::verify(self::body(), self::sign(self::body()), self::SECRET, now: self::NOW);
        self::assertSame(self::EMAIL_ID, $event['email']['id']);
    }

    public function testNonAsciiIsHashedAsUtf8Bytes(): void
    {
        $body = self::body('請求書 📎');
        self::assertSame('請求書 📎', Webhook::verify($body, self::sign($body), self::SECRET, now: self::NOW)['email']['subject']);
    }

    public function testHeaderArraysAreCaseInsensitiveAndAcceptPsr7Lists(): void
    {
        $body = self::body();
        Webhook::verify($body, ['X-Inboundr-Signature' => self::sign($body)], self::SECRET, now: self::NOW);
        Webhook::verify($body, ['x-inboundr-signature' => [self::sign($body)]], self::SECRET, now: self::NOW);
        $this->addToAssertionCount(2);
    }

    public function testServerArrayStyleHeadersAreRead(): void
    {
        $body = self::body();
        $server = ['HTTP_X_INBOUNDR_SIGNATURE' => self::sign($body), 'REQUEST_METHOD' => 'POST'];
        self::assertSame('email.received', Webhook::verify($body, $server, self::SECRET, now: self::NOW)['event']);
    }

    public function testObjectsWithGetAreAccepted(): void
    {
        $body = self::body();
        $bag = new class (self::sign($body)) {
            public function __construct(private readonly string $sig)
            {
            }

            public function get(string $key): ?string
            {
                return strtolower($key) === 'x-inboundr-signature' ? $this->sig : null;
            }
        };
        self::assertSame('email.received', Webhook::verify($body, $bag, self::SECRET, now: self::NOW)['event']);
    }

    public function testPsr7RequestsVerifyDirectly(): void
    {
        $body = self::body();
        $request = new ServerRequest('POST', '/inbound', ['X-Inboundr-Signature' => self::sign($body)], $body);
        self::assertSame(self::EMAIL_ID, Webhook::verifyRequest($request, self::SECRET, now: self::NOW)['email']['id']);
    }

    public function testLegacySignatureIsAFallbackAndCanonicalWins(): void
    {
        $body = self::body();
        Webhook::verify($body, [Webhook::LEGACY_SIGNATURE_HEADER => self::sign($body)], self::SECRET, now: self::NOW);
        Webhook::verify($body, [Webhook::SIGNATURE_HEADER => self::sign($body), Webhook::LEGACY_SIGNATURE_HEADER => 't=1,v1=00'], self::SECRET, now: self::NOW);
        $this->addToAssertionCount(2);
    }

    public function testSeveralV1ValuesAndUppercaseHexAreTolerated(): void
    {
        $body = self::body();
        $mac = explode('v1=', self::sign($body))[1];
        Webhook::verify($body, ' t=' . self::NOW . ' , v0=x , v1=' . str_repeat('0', 64) . ', v1=' . strtoupper($mac), self::SECRET, now: self::NOW);
        $this->addToAssertionCount(1);
    }

    public function testTamperingWrongSecretAndReencodingAreRejected(): void
    {
        $body = self::body();
        $this->rejects('invalid_signature', fn () => Webhook::verify(str_replace('4471', '4472', $body), self::sign($body), self::SECRET, now: self::NOW));
        $this->rejects('invalid_signature', fn () => Webhook::verify($body, self::sign($body, secret: 'whsec_other'), self::SECRET, now: self::NOW));
        $reencoded = json_encode(json_decode($body), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->rejects('invalid_signature', fn () => Webhook::verify($reencoded, self::sign($body), self::SECRET, now: self::NOW));
    }

    public function testTheTimestampIsSigned(): void
    {
        $body = self::body();
        $mac = explode('v1=', self::sign($body))[1];
        $this->rejects('invalid_signature', fn () => Webhook::verify($body, 't=' . (self::NOW + 1) . ",v1=$mac", self::SECRET, now: self::NOW));
    }

    public function testStaleAndFutureSignaturesAreRejectedButToleranceWidens(): void
    {
        $body = self::body();
        $this->rejects('timestamp_out_of_tolerance', fn () => Webhook::verify($body, self::sign($body, self::NOW - 301), self::SECRET, now: self::NOW));
        $this->rejects('timestamp_out_of_tolerance', fn () => Webhook::verify($body, self::sign($body, self::NOW + 301), self::SECRET, now: self::NOW));
        Webhook::verify($body, self::sign($body, self::NOW - 10_000), self::SECRET, toleranceSeconds: PHP_INT_MAX, now: self::NOW);
        $this->addToAssertionCount(1);
    }

    public function testAForgeryIsRejectedBeforeTheTimestampIsJudged(): void
    {
        $body = self::body();
        $this->rejects('invalid_signature', fn () => Webhook::verify($body, self::sign($body, self::NOW - 10_000, 'whsec_other'), self::SECRET, now: self::NOW));
    }

    public function testATruncatedMacIsNotAPrefixMatch(): void
    {
        $body = self::body();
        $this->rejects('invalid_signature', fn () => Webhook::verify($body, substr(self::sign($body), 0, -2), self::SECRET, now: self::NOW));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'garbage' => ['garbage'];
        yield 'no mac' => ['t=' . self::NOW];
        yield 'no t' => ['v1=abc'];
        yield 'exponent' => ['t=12e5,v1=' . str_repeat('a', 64)];
        yield 'not hex' => ['t=' . self::NOW . ',v1=xyz'];
        yield 'huge t' => ['t=' . str_repeat('9', 5000) . ',v1=ab'];
    }

    #[DataProvider('malformed')]
    public function testMalformedHeadersAreRejected(string $header): void
    {
        $this->rejects('malformed_header', fn () => Webhook::verify(self::body(), $header, self::SECRET, now: self::NOW));
    }

    public function testMissingSecretIsReportedFirst(): void
    {
        $this->rejects('missing_secret', fn () => Webhook::verify('', null, '', now: self::NOW));
    }

    public function testMissingHeader(): void
    {
        $this->rejects('missing_header', fn () => Webhook::verify(self::body(), null, self::SECRET, now: self::NOW));
        $this->rejects('missing_header', fn () => Webhook::verify(self::body(), [], self::SECRET, now: self::NOW));
    }

    public function testSignedEmptyBodyIsMissingBodyUnsignedIsMissingHeader(): void
    {
        $this->rejects('missing_body', fn () => Webhook::verify('', self::sign(''), self::SECRET, now: self::NOW));
        $this->rejects('missing_header', fn () => Webhook::verify('', [], self::SECRET, now: self::NOW));
    }

    public function testNonJsonAndUnknownEventsAreInvalidPayload(): void
    {
        $this->rejects('invalid_payload', fn () => Webhook::verify('not json', self::sign('not json'), self::SECRET, now: self::NOW));
        $other = json_encode(['event' => 'email.bounced', 'email' => []], JSON_THROW_ON_ERROR);
        $this->rejects('invalid_payload', fn () => Webhook::verify($other, self::sign($other), self::SECRET, now: self::NOW));
    }

    /** @return array<string, string> */
    private static function idHeaders(): array
    {
        return [
            Webhook::ID_SIGNATURE_HEADER => self::signIds(),
            Webhook::DELIVERY_ID_HEADER => self::DELIVERY_ID,
            Webhook::EMAIL_ID_HEADER => self::EMAIL_ID,
        ];
    }

    public function testVerifyIdsReturnsTheIds(): void
    {
        $ids = Webhook::verifyIds(self::idHeaders(), self::SECRET, now: self::NOW);
        self::assertSame([self::DELIVERY_ID, self::EMAIL_ID], [$ids->deliveryId, $ids->emailId]);
    }

    public function testVerifyIdsFallsBackToTheLegacyDeliveryId(): void
    {
        $headers = self::idHeaders();
        $headers[Webhook::LEGACY_DELIVERY_ID_HEADER] = $headers[Webhook::DELIVERY_ID_HEADER];
        unset($headers[Webhook::DELIVERY_ID_HEADER]);
        self::assertSame(self::DELIVERY_ID, Webhook::verifyIds($headers, self::SECRET, now: self::NOW)->deliveryId);
    }

    public function testAnIdSignatureForAnotherEmailIsRejected(): void
    {
        $headers = [Webhook::EMAIL_ID_HEADER => 'em_other'] + self::idHeaders();
        $this->rejects('invalid_signature', fn () => Webhook::verifyIds($headers, self::SECRET, now: self::NOW));
    }

    public function testBodyAndIdSignaturesCannotStandInForEachOther(): void
    {
        $forged = self::sign(self::DELIVERY_ID . '.' . self::EMAIL_ID);
        $headers = [Webhook::ID_SIGNATURE_HEADER => $forged] + self::idHeaders();
        $this->rejects('invalid_signature', fn () => Webhook::verifyIds($headers, self::SECRET, now: self::NOW));
        $body = self::DELIVERY_ID . '.' . self::EMAIL_ID;
        $this->rejects('invalid_signature', fn () => Webhook::verify($body, self::signIds(), self::SECRET, now: self::NOW));
    }

    public function testMissingIdsAreMissingHeader(): void
    {
        $this->rejects('missing_header', fn () => Webhook::verifyIds([Webhook::ID_SIGNATURE_HEADER => 't=1,v1=00'], self::SECRET, now: self::NOW));
    }

    public function testReadHeadersFoldsInLegacyNames(): void
    {
        $read = Webhook::readHeaders([Webhook::LEGACY_SIGNATURE_HEADER => 'sig', Webhook::LEGACY_DELIVERY_ID_HEADER => 'del_1', 'X-Inboundr-Email-Id' => 'em_1']);
        self::assertSame(['sig', 'del_1', 'em_1'], [$read->signature, $read->deliveryId, $read->emailId]);
    }
}
