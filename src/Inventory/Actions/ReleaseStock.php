<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

final class ReleaseStock
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    /**
     * @param  Model&HasStock  $stockable
     */
    public function __invoke(
        Model $stockable,
        int $quantity,
        ?string $description = null,
        ?Model $reference = null,
        ?string $source = null,
    ): StockMovement {
        return ($this->adjustStock)(
            stockable: $stockable,
            quantity: abs($quantity),
            reason: StockMovementReason::Released,
            description: $description,
            reference: $reference,
            source: $source,
        );
    }
}
