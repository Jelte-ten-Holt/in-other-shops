<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Events;

use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Order $order,
        public OrderStatus $from,
        public OrderStatus $to,
    ) {}
}
