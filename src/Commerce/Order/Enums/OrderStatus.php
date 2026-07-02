<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Enums;

use InOtherShops\Support\HasLabel;
use InOtherShops\Support\StateTransitions;
use InOtherShops\Support\Transitionable;

enum OrderStatus: string implements Transitionable
{
    use HasLabel;
    use StateTransitions;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Confirmed => 'info',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        };
    }
}
