<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Enums;

use InOtherShops\Support\HasLabel;

/**
 * Who initiated a refund. Recorded explicitly so a refund's actor is never a
 * silent null — a refund made at the gateway by someone other than this app
 * (the Stripe dashboard, another API client) has no operator, but that absence
 * is itself a recorded fact. A dispute is not a refund and records none.
 */
enum RefundActorSource: string
{
    use HasLabel;

    case Admin = 'admin';
    case Gateway = 'gateway';
}
