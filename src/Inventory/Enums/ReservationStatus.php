<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Released => 'Released',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
