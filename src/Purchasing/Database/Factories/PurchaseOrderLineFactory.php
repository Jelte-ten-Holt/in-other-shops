<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Database\Factories;

use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Purchasing\Models\PurchaseOrderLine;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PurchaseOrderLine>
 */
final class PurchaseOrderLineFactory extends Factory
{
    public function modelName(): string
    {
        return Purchasing::purchaseOrderLine();
    }

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 10);
        $unitCost = fake()->numberBetween(100, 5000);

        return [
            'purchase_order_id' => Purchasing::purchaseOrder()::factory(),
            'description' => fake()->words(fake()->numberBetween(2, 4), true),
            'sku' => strtoupper(fake()->bothify('??-####')),
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'input_vat' => null,
            'tax_category' => null,
            'line_cost' => $unitCost * $quantity,
        ];
    }

    /**
     * @param  Model&HasStock  $purchasable
     */
    public function forPurchasable(Model $purchasable): static
    {
        return $this->state(fn (): array => [
            'purchasable_type' => $purchasable->getMorphClass(),
            'purchasable_id' => $purchasable->getKey(),
        ]);
    }

    public function received(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity_received' => $quantity]);
    }
}
