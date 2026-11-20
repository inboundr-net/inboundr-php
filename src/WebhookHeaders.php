<?php

declare(strict_types=1);

namespace Inboundr;

/** The delivery headers, with the legacy `x-inbound-*` names folded in. */
final class WebhookHeaders
{
    public function __construct(
        public readonly ?string $signature,
        public readonly ?string $idSignature,
        public readonly ?string $deliveryId,
        public readonly ?string $emailId,
        public readonly ?string $event,
    ) {
    }
}
