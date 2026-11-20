<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListEndpointsResponse from Types
 * @phpstan-import-type CreateEndpointRequest from Types
 * @phpstan-import-type CreatedEndpoint from Types
 * @phpstan-import-type Endpoint from Types
 * @phpstan-import-type UpdateEndpointRequest from Types
 * @phpstan-import-type TestDeliveryResult from Types
 * @phpstan-import-type ListEndpointDeliveriesQuery from Types
 * @phpstan-import-type ListEndpointDeliveriesResponse from Types
 * @phpstan-import-type Delivery from Types
 */
final class Endpoints
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return ListEndpointsResponse
     */
    public function list(): array
    {
        /** @var ListEndpointsResponse */
        return $this->transport->request('GET', '/endpoints');
    }

    /**
     * Create an endpoint. The response is the only time `secret` is returned.
     *
     * @param CreateEndpointRequest $params
     * @return CreatedEndpoint
     */
    public function create(array $params): array
    {
        /** @var CreatedEndpoint */
        return $this->transport->request('POST', '/endpoints', body: $params);
    }

    /**
     * @return Endpoint
     */
    public function get(string $id): array
    {
        /** @var Endpoint */
        return $this->transport->request('GET', '/endpoints/' . Transport::segment($id));
    }

    /**
     * A partial update: send only the fields you are changing.
     *
     * @param UpdateEndpointRequest $params
     * @return Endpoint
     */
    public function update(string $id, array $params): array
    {
        /** @var Endpoint */
        return $this->transport->request('PATCH', '/endpoints/' . Transport::segment($id), body: $params);
    }

    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/endpoints/' . Transport::segment($id));
    }

    /**
     * Send a signed sample delivery and report the outcome.
     *
     * @return TestDeliveryResult
     */
    public function test(string $id): array
    {
        /** @var TestDeliveryResult */
        return $this->transport->request('POST', '/endpoints/' . Transport::segment($id) . '/test');
    }

    /**
     * One page of this endpoint's deliveries, newest first.
     *
     * @param ListEndpointDeliveriesQuery $query
     * @return ListEndpointDeliveriesResponse
     */
    public function deliveries(string $id, array $query = []): array
    {
        /** @var ListEndpointDeliveriesResponse */
        return $this->transport->request('GET', '/endpoints/' . Transport::segment($id) . '/deliveries', $query);
    }

    /**
     * Every matching delivery, newest first, fetching pages as you iterate.
     *
     * @param ListEndpointDeliveriesQuery $query
     * @return \Generator<int, Delivery, mixed, void>
     */
    public function listAllDeliveries(string $id, array $query = []): \Generator
    {
        /** @var \Generator<int, Delivery, mixed, void> */
        return $this->transport->paginate('/endpoints/' . Transport::segment($id) . '/deliveries', $query);
    }
}
