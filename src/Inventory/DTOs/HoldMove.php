<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\DTOs;

use InOtherShops\Inventory\Models\StockReservation;

/**
 * What {@see \InOtherShops\Inventory\Actions\ReserveFromHold} moved: the
 * reservation it made for the caller's reference (null when the hold held
 * none of the product), how many units came from the hold, and the shortfall
 * the caller takes from main stock (stock-holds brief D5).
 */
final readonly class HoldMove
{
    public function __construct(
        public ?StockReservation $reservation,
        public int $fromHold,
        public int $shortfall,
    ) {}
}
