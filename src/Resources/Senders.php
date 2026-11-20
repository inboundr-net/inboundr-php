<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type ListSendersResponse from Types
 */
final class Senders
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Senders connected in the console.
     *
     * @return ListSendersResponse
     */
    public function list(): array
    {
        /** @var ListSendersResponse */
        return $this->transport->request('GET', '/senders');
    }
}
