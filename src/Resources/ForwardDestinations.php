<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListForwardDestinationsResponse from Types
 * @phpstan-import-type CreateForwardDestinationRequest from Types
 * @phpstan-import-type ForwardDestinationRequest from Types
 */
final class ForwardDestinations
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return ListForwardDestinationsResponse
     */
    public function list(): array
    {
        /** @var ListForwardDestinationsResponse */
        return $this->transport->request('GET', '/forward-destinations');
    }

    /**
     * Ask an address to confirm it will accept forwarded mail.
     *
     * @param CreateForwardDestinationRequest $params
     * @return ForwardDestinationRequest
     */
    public function create(array $params): array
    {
        /** @var ForwardDestinationRequest */
        return $this->transport->request('POST', '/forward-destinations', body: $params);
    }

    public function delete(string $address): void
    {
        $this->transport->request('DELETE', '/forward-destinations/' . Transport::segment($address));
    }
}
