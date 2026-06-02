<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Actions;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Variants\Contracts\HasVariants;
use InOtherShops\Variants\Models\Variant;
use Illuminate\Database\Eloquent\Model;

/**
 * Migrates a flat owner onto variants non-destructively: creates a single
 * default (option-less) variant carrying the owner's current price (via the
 * price template) and stock, so adding the first variant never loses state.
 *
 * No-op when the owner already has variants — returns null.
 */
final class CreateDefaultVariant
{
    public function __construct(
        private readonly CreateVariant $createVariant,
        private readonly AdjustStock $adjustStock,
    ) {}

    public function __invoke(Model&HasVariants $owner): ?Variant
    {
        if ($owner->hasVariants()) {
            return null;
        }

        $variant = ($this->createVariant)($owner);

        $this->carryOwnerStock($owner, $variant);

        return $variant;
    }

    private function carryOwnerStock(Model $owner, Variant $variant): void
    {
        if (! $owner instanceof HasStock) {
            return;
        }

        $level = $owner->stockLevel();

        if ($level <= 0) {
            return;
        }

        ($this->adjustStock)(
            $variant,
            $level,
            StockMovementReason::Adjusted,
            'Carried from flat owner when its first variant was created',
        );
    }
}
