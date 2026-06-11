<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Enums;

use InOtherShops\Support\StateTransitions;
use InOtherShops\Support\Transitionable;

enum PurchaseOrderStatus: string implements Transitionable
{
    use StateTransitions;

    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Goods may still arrive against a purchase order in these states.
     */
    public function isReceivable(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::Received, self::Cancelled],
            self::Received => [],
            self::Cancelled => [],
        };
    }
}
