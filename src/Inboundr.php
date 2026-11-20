<?php

declare(strict_types=1);

namespace Inboundr;

use Inboundr\Exception\InboundrException;
use Inboundr\Internal\Transport;
use Inboundr\Resources\Addresses;
use Inboundr\Resources\Domains;
use Inboundr\Resources\Emails;
use Inboundr\Resources\Endpoints;
use Inboundr\Resources\ForwardDestinations;
use Inboundr\Resources\Senders;
use Inboundr\Resources\ShieldRules;
use Inboundr\Resources\Suppressions;
use Inboundr\Resources\Threads;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Client for the Inboundr API.
 *
 *     $client = new Inboundr();            // reads INBOUNDR_API_KEY
 *     $client = new Inboundr('inb_...');
 */
final class Inboundr
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://inboundr.net';
    public const API_KEY_ENV_VAR = 'INBOUNDR_API_KEY';

    public readonly Emails $emails;
    public readonly Threads $threads;
    public readonly Domains $domains;
    public readonly Addresses $addresses;
    public readonly Endpoints $endpoints;
    public readonly ShieldRules $shieldRules;
    public readonly Senders $senders;
    public readonly Suppressions $suppressions;
    public readonly ForwardDestinations $forwardDestinations;

    /**
     * @param string|null $apiKey An `inb_` key; read from INBOUNDR_API_KEY when null.
     * @param ClientInterface|null $httpClient Any PSR-18 client. By default a Guzzle
     *        client with `$timeout` when Guzzle is installed, otherwise one found by
     *        discovery (set its timeout yourself).
     * @param float $timeout Seconds; applies only to the Guzzle client built for you.
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        float $timeout = 30.0,
    ) {
        $apiKey ??= self::envKey();
        if ($apiKey === null || $apiKey === '') {
            throw new InboundrException(
                'No API key. Pass new Inboundr($apiKey) or set ' . self::API_KEY_ENV_VAR
                . '. Create a key in the console under API keys.',
            );
        }

        $transport = new Transport($apiKey, $baseUrl, $httpClient, $requestFactory, $streamFactory, $timeout);
        $this->emails = new Emails($transport);
        $this->threads = new Threads($transport);
        $this->domains = new Domains($transport);
        $this->addresses = new Addresses($transport);
        $this->endpoints = new Endpoints($transport);
        $this->shieldRules = new ShieldRules($transport);
        $this->senders = new Senders($transport);
        $this->suppressions = new Suppressions($transport);
        $this->forwardDestinations = new ForwardDestinations($transport);
    }

    private static function envKey(): ?string
    {
        foreach ([$_ENV[self::API_KEY_ENV_VAR] ?? null, $_SERVER[self::API_KEY_ENV_VAR] ?? null, getenv(self::API_KEY_ENV_VAR)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }
}
