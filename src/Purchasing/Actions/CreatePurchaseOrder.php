<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Contracts\HasPurchases;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\PurchaseOrderCreated;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\Supplier;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a draft purchase order with its lines, in one transaction. `subtotal`
 * is the sum of (net) line costs; `total` adds shipping + customs (net landed
 * cost). Stock is untouched — a draft commits nothing until {@see ReceiveItems}.
 */
final class CreatePurchaseOrder
{
    /**
     * Each line: `quantity_ordered` and `unit_cost` (net cents) are required.
     * Pass `purchasable` (a HasPurchases model) to link the line and snapshot
     * its description/sku; or pass `description`/`sku` explicitly. `input_vat`
     * (reclaimable, cents) and `tax_category` are optional.
     *
     * @param  list<array{purchasable?: Model|null, description?: string, sku?: string|null, quantity_ordered: int, unit_cost: int, input_vat?: int|null, tax_category?: string|null}>  $lines
     */
    public function __invoke(
        Supplier $supplier,
        array $lines,
        ?Currency $currency = null,
        ?\DateTimeInterface $expectedDeliveryAt = null,
        int $shippingCost = 0,
        int $customsCost = 0,
        ?string $notes = null,
        ?string $reference = null,
    ): PurchaseOrder {
        $currency ??= $supplier->default_currency;

        $order = DB::transaction(function () use ($supplier, $lines, $currency, $expectedDeliveryAt, $shippingCost, $customsCost, $notes, $reference): PurchaseOrder {
            /** @var PurchaseOrder $order */
            $order = Purchasing::purchaseOrder()::query()->create([
                'reference' => $reference ?? $this->generateReference(),
                'supplier_id' => $supplier->getKey(),
                'status' => PurchaseOrderStatus::Draft,
                'currency' => $currency,
                'expected_delivery_at' => $expectedDeliveryAt,
                'shipping_cost' => $shippingCost,
                'customs_cost' => $customsCost,
                'subtotal' => 0,
                'total' => 0,
                'notes' => $notes,
            ]);

            $subtotal = 0;
            foreach ($lines as $line) {
                $subtotal += $this->persistLine($order, $line);
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal + $shippingCost + $customsCost,
            ]);

            return $order;
        });

        PurchaseOrderCreated::dispatch($order);

        return $order;
    }

    /**
     * @param  array{purchasable?: Model|null, description?: string, sku?: string|null, quantity_ordered: int, unit_cost: int, input_vat?: int|null, tax_category?: string|null}  $line
     * @return int  the line cost added to the order subtotal
     */
    private function persistLine(PurchaseOrder $order, array $line): int
    {
        $purchasable = $line['purchasable'] ?? null;

        $snapshot = $purchasable instanceof HasPurchases
            ? $purchasable->toPurchaseLineData()
            : ['description' => '', 'sku' => null];

        $description = $line['description'] ?? $snapshot['description'];
        $sku = $line['sku'] ?? $snapshot['sku'];

        if ($description === '' || $description === null) {
            throw new InvalidArgumentException('Purchase order line requires a description or a purchasable.');
        }

        $quantity = (int) $line['quantity_ordered'];
        $unitCost = (int) $line['unit_cost'];

        if ($quantity < 1) {
            throw new InvalidArgumentException('quantity_ordered must be at least 1.');
        }

        if ($unitCost < 0) {
            throw new InvalidArgumentException('unit_cost cannot be negative.');
        }

        $lineCost = $unitCost * $quantity;

        $model = new (Purchasing::purchaseOrderLine())([
            'description' => $description,
            'sku' => $sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'input_vat' => $line['input_vat'] ?? null,
            'tax_category' => $line['tax_category'] ?? null,
            'line_cost' => $lineCost,
        ]);
        $model->purchaseOrder()->associate($order);

        if ($purchasable instanceof Model) {
            $model->purchasable()->associate($purchasable);
        }

        $model->save();

        return $lineCost;
    }

    private function generateReference(): string
    {
        $prefix = (string) config('purchasing.reference_prefix', 'PO');

        return $prefix.'-'.Str::upper(Str::random(8));
    }
}
