<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Events;

use InOtherShops\Inventory\Contracts\HasStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class StockReservationFailed
{
    use Dispatchable;

    public function __construct(
        /** @var Model&HasStock */
        public Model $stockable,
        public int $requestedQuantity,
        public int $availableQuantity,
    ) {}
}
