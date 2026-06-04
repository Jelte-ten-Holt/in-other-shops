<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\DTOs;

use InOtherShops\Commerce\Order\Enums\RefundActorSource;

/**
 * Who issued a refund. Passed explicitly into the refund flow rather than read
 * from auth/LogContext (which the package can't reach into and which isn't
 * populated) — the consumer maps its authenticated user to an `admin` actor at
 * the callsite, and gateway-initiated refunds use the `gateway` sentinel so the
 * "no operator" case is recorded, not a null hole.
 */
final readonly class RefundActor
{
    public function __construct(
        public RefundActorSource $source,
        public ?string $id = null,
        public ?string $label = null,
    ) {}

    public static function admin(string $id, ?string $label = null): self
    {
        return new self(RefundActorSource::Admin, $id, $label);
    }

    public static function gateway(?string $label = null): self
    {
        return new self(RefundActorSource::Gateway, null, $label);
    }
}
