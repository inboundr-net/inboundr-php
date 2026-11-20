<?php

declare(strict_types=1);

namespace Inboundr\Resources;

use Inboundr\Generated\Types;
use Inboundr\Internal\Transport;

/**
 * @phpstan-import-type GetThreadResponse from Types
 */
final class Threads
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Every message in a thread, oldest first.
     *
     * @return GetThreadResponse
     */
    public function get(string $id): array
    {
        /** @var GetThreadResponse */
        return $this->transport->request('GET', '/threads/' . Transport::segment($id));
    }
}
