<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Enums;

enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case ReturnedToSender = 'returned_to_sender';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Ready => 'Ready',
            self::InTransit => 'In transit',
            self::Delivered => 'Delivered',
            self::ReturnedToSender => 'Returned to sender',
            self::Lost => 'Lost',
        };
    }

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

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
