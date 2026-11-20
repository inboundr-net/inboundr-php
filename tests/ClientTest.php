<?php

declare(strict_types=1);

namespace Inboundr\Tests;

use Inboundr\Exception\ApiException;
use Inboundr\Exception\InboundrException;
use Inboundr\Exception\RateLimitException;
use Inboundr\Inboundr;
use Inboundr\Tests\Support\MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    public function testAMissingKeyThrowsUpFront(): void
    {
        $previous = getenv(Inboundr::API_KEY_ENV_VAR);
        putenv(Inboundr::API_KEY_ENV_VAR);
        unset($_ENV[Inboundr::API_KEY_ENV_VAR], $_SERVER[Inboundr::API_KEY_ENV_VAR]);
        try {
            $this->expectException(InboundrException::class);
            new Inboundr();
        } finally {
            if ($previous !== false) {
                putenv(Inboundr::API_KEY_ENV_VAR . "=$previous");
            }
        }
    }

    public function testTheKeyFallsBackToTheEnvironment(): void
    {
        putenv(Inboundr::API_KEY_ENV_VAR . '=inb_from_env');
        try {
            $mock = new MockClient(fn () => MockClient::json(['data' => []]));
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            (new Inboundr(httpClient: $mock, requestFactory: $factory, streamFactory: $factory))->domains->list();
            self::assertSame('Bearer inb_from_env', $mock->requests[0]->getHeaderLine('Authorization'));
        } finally {
            putenv(Inboundr::API_KEY_ENV_VAR);
        }
    }

    public function testUrlHeadersAndUserAgent(): void
    {
        [$client, $mock] = MockClient::client(fn () => MockClient::json(['data' => []]), 'https://example.test/');
        $client->domains->list();
        $request = $mock->requests[0];
        self::assertSame('https://example.test/api/v1/domains', (string) $request->getUri());
        self::assertSame('Bearer inb_test_2mR9Kq4nPj', $request->getHeaderLine('Authorization'));
        self::assertStringStartsWith('inboundr-php/', $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Content-Type'));
    }

    public function testQueryDropsNullsAndEncodes(): void
    {
        [$client, $mock] = MockClient::client(fn () => MockClient::json(['data' => [], 'nextCursor' => null]));
        $client->emails->list(['to' => 'a+b@x.dev', 'limit' => 5]);
        parse_str($mock->requests[0]->getUri()->getQuery(), $query);
        self::assertSame(['to' => 'a+b@x.dev', 'limit' => '5'], $query);
    }

    public function testAnAddressInAPathIsPercentEncoded(): void
    {
        [$client, $mock] = MockClient::client(fn () => new Response(204));
        $client->suppressions->delete('user+tag@example.com');
        self::assertSame('/api/v1/suppressions/user%2Btag%40example.com', $mock->requests[0]->getUri()->getPath());
    }

    public function testSendPostsJson(): void
    {
        [$client, $mock] = MockClient::client(fn () => MockClient::json(['id' => 'em_1', 'messageId' => null, 'threadId' => 'thr_1'], 201));
        $sent = $client->emails->send(['from' => 'a@x.dev', 'to' => ['b@x.dev'], 'subject' => 's', 'text' => 'é']);
        $request = $mock->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['from' => 'a@x.dev', 'to' => ['b@x.dev'], 'subject' => 's', 'text' => 'é'], json_decode((string) $request->getBody(), true));
        self::assertSame('thr_1', $sent['threadId']);
    }

    public function testMoveAndReorderSendTheirBodies(): void
    {
        [$client, $mock] = MockClient::client(fn () => MockClient::json(['order' => ['sr_2', 'sr_1']]));
        self::assertSame(['sr_2', 'sr_1'], $client->shieldRules->move('sr_1', 'down')['order']);
        $client->shieldRules->reorder([3 => 'sr_2', 7 => 'sr_1']);
        self::assertSame('{"direction":"down"}', (string) $mock->requests[0]->getBody());
        self::assertSame('{"order":["sr_2","sr_1"]}', (string) $mock->requests[1]->getBody());
    }

    public function testA204ReturnsNothingAndDownloadsReturnBytes(): void
    {
        [$client, $mock] = MockClient::client(fn (RequestInterface $r) => str_contains($r->getUri()->getPath(), 'attachments')
            ? new Response(200, ['Content-Type' => 'application/pdf'], '%PDF')
            : new Response(204));
        $client->emails->delete('em_1');
        self::assertSame('%PDF', $client->emails->downloadAttachment('em_1', 2));
        self::assertSame('download=1', $mock->requests[1]->getUri()->getQuery());
        self::assertStringEndsWith('/emails/em_1/attachments/2', $mock->requests[1]->getUri()->getPath());
    }

    public function testListAllFollowsTheCursorKeepsKeysAndStops(): void
    {
        $pages = [
            ['data' => [['id' => 'em_1'], ['id' => 'em_2']], 'nextCursor' => 'c1'],
            ['data' => [['id' => 'em_3']], 'nextCursor' => null],
        ];
        [$client, $mock] = MockClient::client(function () use (&$pages) {
            return MockClient::json(array_shift($pages));
        });
        $all = iterator_to_array($client->emails->listAll(['direction' => 'inbound', 'limit' => 2]));
        self::assertSame(['em_1', 'em_2', 'em_3'], array_column($all, 'id'));
        self::assertSame([0, 1, 2], array_keys($all));
        self::assertStringNotContainsString('cursor', $mock->requests[0]->getUri()->getQuery());
        self::assertStringContainsString('cursor=c1', $mock->requests[1]->getUri()->getQuery());
        self::assertStringContainsString('direction=inbound', $mock->requests[1]->getUri()->getQuery());
    }

    public function testBreakingOutOfListAllStopsFetching(): void
    {
        [$client, $mock] = MockClient::client(fn () => MockClient::json(['data' => [['id' => 'a'], ['id' => 'b']], 'nextCursor' => 'more']));
        foreach ($client->emails->listAll() as $email) {
            break;
        }
        self::assertCount(1, $mock->requests);
    }

    public function testListAllDeliveriesPages(): void
    {
        $pages = [['data' => [['id' => 'del_1']], 'nextCursor' => 'c'], ['data' => [['id' => 'del_2']], 'nextCursor' => null]];
        [$client, $mock] = MockClient::client(function () use (&$pages) {
            return MockClient::json(array_shift($pages));
        });
        self::assertSame(['del_1', 'del_2'], array_column(iterator_to_array($client->endpoints->listAllDeliveries('ep_1', ['status' => 'dead'])), 'id'));
        self::assertStringEndsWith('/endpoints/ep_1/deliveries', $mock->requests[0]->getUri()->getPath());
    }

    public function testA404CarriesStatusBodyAndRequestId(): void
    {
        [$client] = MockClient::client(fn () => MockClient::json(['error' => 'Email not found'], 404, ['x-request-id' => 'req_1']));
        try {
            $client->emails->get('em_x');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame([404, ['error' => 'Email not found'], 'req_1', 'Email not found'], [$e->status, $e->body, $e->requestId, $e->getMessage()]);
        }
    }

    public function testA400TreeIsKeptInTheMessage(): void
    {
        [$client] = MockClient::client(fn () => MockClient::json(['error' => ['errors' => ['Unrecognized key: "endpointId"']]], 400));
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('endpointId');
        $client->addresses->create(['domainId' => 'd', 'localPart' => 'a']);
    }

    public function testA429CarriesRetryAfterOrNull(): void
    {
        foreach ([['2', 2.0], [null, null]] as [$header, $expected]) {
            [$client] = MockClient::client(fn () => MockClient::json(['error' => 'slow'], 429, $header === null ? [] : ['retry-after' => $header]));
            try {
                $client->domains->list();
                self::fail('expected RateLimitException');
            } catch (RateLimitException $e) {
                self::assertSame($expected, $e->retryAfterSeconds);
            }
        }
    }

    public function testANonJsonErrorBodyIsKeptAsText(): void
    {
        [$client] = MockClient::client(fn () => new Response(502, [], '<html>Bad gateway</html>'));
        try {
            $client->domains->list();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('<html>Bad gateway</html>', $e->body);
        }
    }

    public function testARedirectNamesItsTarget(): void
    {
        [$client] = MockClient::client(fn () => new Response(301, ['location' => 'https://inboundr.net/api/v1/domains']), 'http://inboundr.net');
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('redirect to https://inboundr.net/api/v1/domains');
        $client->domains->list();
    }

    public function testANonJsonSuccessThrowsInsteadOfReturningText(): void
    {
        [$client] = MockClient::client(fn () => new Response(200, ['Content-Type' => 'text/html'], '<html>Wi-Fi login</html>'));
        $this->expectException(InboundrException::class);
        $this->expectExceptionMessage('Expected JSON');
        iterator_to_array($client->emails->listAll());
    }

    public function testTransportFailuresAreWrapped(): void
    {
        [$client] = MockClient::client(function (RequestInterface $request) {
            throw new class ('connection refused', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        });
        $this->expectException(InboundrException::class);
        $this->expectExceptionMessage('GET /domains failed: connection refused');
        $client->domains->list();
    }

    public function testTheDefaultClientIsGuzzleWithATimeoutAndNoRedirects(): void
    {
        $client = new Inboundr('inb_x', timeout: 12.5);
        $transport = (new \ReflectionProperty($client->emails, 'transport'))->getValue($client->emails);
        $http = (new \ReflectionProperty($transport, 'http'))->getValue($transport);
        self::assertInstanceOf(\GuzzleHttp\Client::class, $http);
        $config = (new \ReflectionProperty($http, 'config'))->getValue($http);
        self::assertIsArray($config);
        self::assertSame(12.5, $config['timeout']);
        self::assertFalse($config['allow_redirects']);
    }
}
