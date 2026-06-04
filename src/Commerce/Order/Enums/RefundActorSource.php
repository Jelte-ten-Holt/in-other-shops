<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Enums;

/**
 * Who initiated a refund. Recorded explicitly so a refund's actor is never a
 * silent null — a gateway-initiated refund (Stripe dashboard, dispute auto-
 * refund, webhook) has no operator, but that absence is itself a recorded fact.
 */
enum RefundActorSource: string
{
    case Admin = 'admin';
    case Gateway = 'gateway';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Gateway => 'Gateway',
        };
    }
}
