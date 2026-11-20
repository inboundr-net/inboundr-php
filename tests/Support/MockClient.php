<?php

declare(strict_types=1);

namespace Inboundr\Tests\Support;

use Inboundr\Inboundr;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that records requests and answers from a callback. */
final class MockClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param \Closure(RequestInterface): ResponseInterface $handler */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        return ($this->handler)($request);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array{Inboundr, self} */
    public static function client(\Closure $handler, string $baseUrl = Inboundr::DEFAULT_BASE_URL): array
    {
        $mock = new self($handler);
        $factory = new Psr17Factory();
        return [new Inboundr('inb_test_2mR9Kq4nPj', $baseUrl, $mock, $factory, $factory), $mock];
    }
}
