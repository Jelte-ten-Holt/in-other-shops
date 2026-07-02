<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Enums;

use InOtherShops\Support\HasLabel;

enum ReservationStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Released = 'released';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
