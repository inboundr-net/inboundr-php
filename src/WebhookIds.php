<?php

declare(strict_types=1);

namespace Inboundr;

final class WebhookIds
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $emailId,
    ) {
    }
}
