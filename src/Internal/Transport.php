<?php

declare(strict_types=1);

namespace Inboundr\Internal;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Inboundr\Exception\ApiException;
use Inboundr\Exception\InboundrException;
use Inboundr\Exception\RateLimitException;
use Inboundr\Inboundr;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** @internal Builds requests, sends them and turns responses into data or exceptions. */
final class Transport
{
    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;
    private readonly string $url;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
        float $timeout,
    ) {
        $this->url = rtrim($baseUrl, '/') . '/api/v1';
        $this->http = $httpClient ?? self::defaultClient($timeout);
        try {
            $this->requests = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
            $this->streams = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $e) {
            throw new InboundrException(
                'No PSR-17 HTTP factories found. Install guzzlehttp/guzzle (or nyholm/psr7), or pass requestFactory and streamFactory.',
                0,
                $e,
            );
        }
    }

    /**
     * PSR-18 has no timeout setting and Guzzle's default is none at all, so
     * when Guzzle is installed and no client was passed, build one with a
     * timeout. Redirects are off: the API never redirects, so one means a
     * wrong base URL, and following it would hide that.
     */
    private static function defaultClient(float $timeout): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $timeout,
                'connect_timeout' => min($timeout, 10.0),
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        }
        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException $e) {
            throw new InboundrException(
                'No PSR-18 HTTP client found. Install guzzlehttp/guzzle, or pass httpClient.',
                0,
                $e,
            );
        }
    }

    /** Percent-encodes one path segment. Ids are safe; email addresses are not. */
    public static function segment(string|int $value): string
    {
        return rawurlencode((string) $value);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<mixed>|null $body
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, bool $raw = false): mixed
    {
        $query = array_filter($query, static fn ($v) => $v !== null);
        $uri = $this->url . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $request = $this->requests->createRequest($method, $uri)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'inboundr-php/' . Inboundr::VERSION);
        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($json));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new InboundrException("$method $path failed: " . $e->getMessage(), 0, $e);
        }

        return $this->handle($method, $path, $response, $raw);
    }

    private function handle(string $method, string $path, ResponseInterface $response, bool $raw): mixed
    {
        $status = $response->getStatusCode();
        $text = (string) $response->getBody();
        $requestId = $response->getHeaderLine('x-request-id') ?: null;

        if ($status >= 200 && $status < 300) {
            if ($raw) {
                return $text;
            }
            if ($status === 204 || $text === '') {
                return null;
            }
            try {
                return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Every API response is JSON, so this is something in between
                // (a proxy, a captive portal, the wrong base URL) answering.
                $type = $response->getHeaderLine('content-type') ?: 'no content-type';
                throw new InboundrException(sprintf(
                    'Expected JSON from %s %s, got %d %s: %s. Check the base URL and any proxy in between.',
                    $method,
                    $path,
                    $status,
                    $type,
                    var_export(substr($text, 0, 200), true),
                ));
            }
        }

        if ($status >= 300 && $status < 400) {
            $location = $response->getHeaderLine('location') ?: '(no location)';
            throw new ApiException(
                "Got a $status redirect to $location. The SDK doesn't follow redirects; set the base URL to the final address, e.g. https://inboundr.net.",
                $status,
                $text,
                $requestId,
            );
        }

        try {
            $body = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = $text;
        }
        $message = self::message($status, $body);

        if ($status === 429) {
            $header = $response->getHeaderLine('retry-after');
            $retry = is_numeric($header) && (float) $header >= 0 ? (float) $header : null;
            throw new RateLimitException($message, $body, $requestId, $retry);
        }
        throw new ApiException($message, $status, $body, $requestId);
    }

    private static function message(int $status, mixed $body): string
    {
        if (is_array($body) && array_key_exists('error', $body)) {
            return is_string($body['error'])
                ? $body['error']
                : "$status " . json_encode($body['error'], JSON_UNESCAPED_SLASHES);
        }
        if (is_string($body) && trim($body) !== '') {
            return "$status $body";
        }
        return "Request failed with status $status";
    }

    /**
     * Every item of a keyset-paginated listing, fetched a page at a time as
     * the caller iterates. Stops at the first page with no cursor.
     *
     * @param array<string, mixed> $query
     * @return \Generator<int, mixed, mixed, void>
     */
    public function paginate(string $path, array $query): \Generator
    {
        while (true) {
            $page = $this->request('GET', $path, $query);
            if (!is_array($page) || !isset($page['data']) || !is_array($page['data'])) {
                throw new InboundrException("GET $path returned something other than a page.");
            }
            // Not `yield from`: that reuses each page's keys (0, 1, …), so
            // iterator_to_array() would overwrite earlier pages.
            foreach ($page['data'] as $item) {
                yield $item;
            }
            $cursor = $page['nextCursor'] ?? null;
            if (!is_string($cursor) || $cursor === '') {
                return;
            }
            $query['cursor'] = $cursor;
        }
    }
}
