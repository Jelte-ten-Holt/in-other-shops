<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Database\Factories;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
final class PurchaseOrderFactory extends Factory
{
    public function modelName(): string
    {
        return Purchasing::purchaseOrder();
    }

    public function definition(): array
    {
        return [
            'reference' => 'PO-'.strtoupper(fake()->unique()->bothify('????####')),
            'supplier_id' => Purchasing::supplier()::factory(),
            'status' => PurchaseOrderStatus::Draft,
            'currency' => Currency::EUR,
            'ordered_at' => null,
            'expected_delivery_at' => null,
            'shipping_cost' => 0,
            'customs_cost' => 0,
            'subtotal' => 0,
            'total' => 0,
            'notes' => null,
        ];
    }

    public function status(PurchaseOrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function forSupplier(?Supplier $supplier = null): static
    {
        return $this->state(fn (): array => [
            'supplier_id' => $supplier?->getKey() ?? Purchasing::supplier()::factory(),
        ]);
    }

    public function withLines(int $count = 3): static
    {
        return $this->afterCreating(function (PurchaseOrder $order) use ($count): void {
            $subtotal = 0;

            for ($i = 0; $i < $count; $i++) {
                $unitCost = fake()->numberBetween(100, 5000);
                $quantity = fake()->numberBetween(1, 10);
                $lineCost = $unitCost * $quantity;
                $subtotal += $lineCost;

                $order->lines()->create([
                    'description' => fake()->words(fake()->numberBetween(2, 4), true),
                    'sku' => strtoupper(fake()->bothify('??-####')),
                    'quantity_ordered' => $quantity,
                    'quantity_received' => 0,
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                ]);
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal + $order->shipping_cost + $order->customs_cost,
            ]);
        });
    }
}
