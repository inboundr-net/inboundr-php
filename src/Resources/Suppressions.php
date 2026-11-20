<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListSuppressionsResponse from Types
 * @phpstan-import-type CreateSuppressionRequest from Types
 * @phpstan-import-type CreateSuppressionResponse from Types
 */
final class Suppressions
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return ListSuppressionsResponse
     */
    public function list(): array
    {
        /** @var ListSuppressionsResponse */
        return $this->transport->request('GET', '/suppressions');
    }

    /**
     * @param CreateSuppressionRequest $params
     * @return CreateSuppressionResponse
     */
    public function create(array $params): array
    {
        /** @var CreateSuppressionResponse */
        return $this->transport->request('POST', '/suppressions', body: $params);
    }

    public function delete(string $address): void
    {
        $this->transport->request('DELETE', '/suppressions/' . Transport::segment($address));
    }
}
