<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListAddressesResponse from Types
 * @phpstan-import-type CreateAddressRequest from Types
 * @phpstan-import-type CreatedAddress from Types
 * @phpstan-import-type Address from Types
 * @phpstan-import-type UpdateAddressRequest from Types
 */
final class Addresses
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return ListAddressesResponse
     */
    public function list(): array
    {
        /** @var ListAddressesResponse */
        return $this->transport->request('GET', '/addresses');
    }

    /**
     * With no `endpointIds` the address is store-only.
     *
     * @param CreateAddressRequest $params
     * @return CreatedAddress
     */
    public function create(array $params): array
    {
        /** @var CreatedAddress */
        return $this->transport->request('POST', '/addresses', body: $params);
    }

    /**
     * @return Address
     */
    public function get(string $id): array
    {
        /** @var Address */
        return $this->transport->request('GET', '/addresses/' . Transport::segment($id));
    }

    /**
     * `endpointIds` replaces the whole set; an empty list makes the address store-only.
     *
     * @param UpdateAddressRequest $params
     * @return Address
     */
    public function update(string $id, array $params): array
    {
        /** @var Address */
        return $this->transport->request('PATCH', '/addresses/' . Transport::segment($id), body: $params);
    }

    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/addresses/' . Transport::segment($id));
    }
}
