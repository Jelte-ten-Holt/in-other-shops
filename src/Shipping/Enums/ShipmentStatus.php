<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Enums;

use InOtherShops\Support\HasLabel;
use InOtherShops\Support\StateTransitions;
use InOtherShops\Support\Transitionable;

enum ShipmentStatus: string implements Transitionable
{
    use HasLabel;
    use StateTransitions;

    case Pending = 'pending';
    case Ready = 'ready';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case ReturnedToSender = 'returned_to_sender';
    case Lost = 'lost';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Ready => 'info',
            self::InTransit => 'primary',
            self::Delivered => 'success',
            self::ReturnedToSender => 'warning',
            self::Lost => 'danger',
        };
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Ready, self::Lost],
            self::Ready => [self::InTransit, self::Lost],
            self::InTransit => [self::Delivered, self::ReturnedToSender, self::Lost],
            self::ReturnedToSender => [self::Pending, self::Lost],
            self::Delivered, self::Lost => [],
        };
    }
}
