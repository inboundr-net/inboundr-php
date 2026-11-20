<?php

declare(strict_types=1);

namespace Inboundr;

use Inboundr\Generated\Types;

/**
 * A delivery whose body and ids were both verified. `deliveryId` is signed, so
 * it is safe to deduplicate retries on.
 *
 * @phpstan-import-type EmailReceivedEvent from Types
 */
final class WebhookDelivery
{
    /** @param EmailReceivedEvent $event */
    public function __construct(
        public readonly array $event,
        public readonly string $deliveryId,
    ) {
    }
}
