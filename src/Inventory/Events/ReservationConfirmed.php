<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Events;

use InOtherShops\Inventory\Models\StockReservation;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ReservationConfirmed
{
    use Dispatchable;

    public function __construct(
        public StockReservation $reservation,
    ) {}
}
