<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Events;

use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ReservationReleased
{
    use Dispatchable;

    public function __construct(
        public StockReservation $reservation,
    ) {}
}
