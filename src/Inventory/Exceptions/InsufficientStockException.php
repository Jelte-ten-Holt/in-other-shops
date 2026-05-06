<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Exceptions;

use InOtherShops\Inventory\Contracts\HasStock;
use Illuminate\Database\Eloquent\Model;

final class InsufficientStockException extends InventoryException
{
    /**
     * @param  Model&HasStock  $stockable
     */
    public static function forReservation(Model $stockable, int $requested, int $available): self
    {
        return new self(
            "Cannot reserve {$requested} units of {$stockable->getMorphClass()}#{$stockable->getKey()}: only {$available} available.",
        );
    }

    public static function forCart(string $label, int $available): self
    {
        if ($available <= 0) {
            return new self("{$label} is out of stock.");
        }

        return new self("{$label}: only {$available} in stock.");
    }
}
