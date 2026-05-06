<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

use InOtherShops\Commerce\Order\Enums\OrderStatus;

final class InvalidOrderStatusTransitionException extends CommerceException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Cannot transition order from {$from->value} to {$to->value}.");
    }
}
