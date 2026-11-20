<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListDomainsResponse from Types
 * @phpstan-import-type CreateDomainRequest from Types
 * @phpstan-import-type DomainWithDns from Types
 * @phpstan-import-type UpdateDomainRequest from Types
 * @phpstan-import-type UpdateDomainResponse from Types
 */
final class Domains
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return ListDomainsResponse
     */
    public function list(): array
    {
        /** @var ListDomainsResponse */
        return $this->transport->request('GET', '/domains');
    }

    /**
     * Register a domain. Publish the returned `dnsRecords`, then poll `get`.
     *
     * @param CreateDomainRequest $params
     * @return DomainWithDns
     */
    public function create(array $params): array
    {
        /** @var DomainWithDns */
        return $this->transport->request('POST', '/domains', body: $params);
    }

    /**
     * Fetch a domain and re-check its verification.
     *
     * @return DomainWithDns
     */
    public function get(string $id): array
    {
        /** @var DomainWithDns */
        return $this->transport->request('GET', '/domains/' . Transport::segment($id));
    }

    /**
     * Set the catch-all endpoints. An empty list turns the catch-all off.
     *
     * @param UpdateDomainRequest $params
     * @return UpdateDomainResponse
     */
    public function update(string $id, array $params): array
    {
        /** @var UpdateDomainResponse */
        return $this->transport->request('PATCH', '/domains/' . Transport::segment($id), body: $params);
    }

    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/domains/' . Transport::segment($id));
    }
}
