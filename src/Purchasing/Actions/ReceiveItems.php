<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Actions;

use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Events\ItemsReceived;
use InOtherShops\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use InOtherShops\Purchasing\Exceptions\ReceiveExceedsOutstandingException;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Purchasing\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Receive quantities against an ordered purchase order. For each line linked to
 * a stockable, drops a StockMovement (reason: Received) referencing the line —
 * so the ledger is the receipt history and stock goes up — and bumps the cached
 * `quantity_received`. Recomputes the order status (PartiallyReceived / Received).
 *
 * All quantities are validated before any stock moves, so a single over-receive
 * rejects the whole receipt rather than leaving a partial adjustment.
 */
final class ReceiveItems
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    /**
     * @param  array<int, int>  $quantities  purchase order line id => quantity received now
     */
    public function __invoke(PurchaseOrder $order, array $quantities, ?string $source = 'dashboard'): PurchaseOrder
    {
        if (! $order->status->isReceivable()) {
            throw InvalidPurchaseOrderTransitionException::between($order->status, PurchaseOrderStatus::PartiallyReceived);
        }

        $order = DB::transaction(function () use ($order, $quantities, $source): PurchaseOrder {
            /** @var list<array{0: PurchaseOrderLine, 1: int}> $receipts */
            $receipts = [];

            foreach ($quantities as $lineId => $quantity) {
                $quantity = (int) $quantity;

                if ($quantity <= 0) {
                    continue;
                }

                /** @var PurchaseOrderLine $line */
                $line = $order->lines()->whereKey((int) $lineId)->firstOrFail();

                $outstanding = $line->outstandingQuantity();
                if ($quantity > $outstanding) {
                    throw ReceiveExceedsOutstandingException::forLine((int) $lineId, $quantity, $outstanding);
                }

                $receipts[] = [$line, $quantity];
            }

            foreach ($receipts as [$line, $quantity]) {
                $this->applyReceipt($order, $line, $quantity, $source);
            }

            return $this->applyStatus($order);
        });

        ItemsReceived::dispatch($order, $quantities);

        return $order;
    }

    private function applyReceipt(PurchaseOrder $order, PurchaseOrderLine $line, int $quantity, ?string $source): void
    {
        $purchasable = $line->purchasable;

        if ($purchasable instanceof Model && $purchasable instanceof HasStock) {
            ($this->adjustStock)(
                $purchasable,
                $quantity,
                StockMovementReason::Received,
                description: "Received against {$order->reference}",
                reference: $line,
                source: $source,
            );
        }

        $line->quantity_received += $quantity;
        $line->save();
    }

    private function applyStatus(PurchaseOrder $order): PurchaseOrder
    {
        $lines = $order->lines()->get();

        $allReceived = $lines->isNotEmpty()
            && $lines->every(fn (PurchaseOrderLine $line): bool => $line->isFullyReceived());
        $anyReceived = $lines->contains(fn (PurchaseOrderLine $line): bool => $line->quantity_received > 0);

        $target = match (true) {
            $allReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => $order->status,
        };

        if ($target !== $order->status) {
            $order->update(['status' => $target]);
        }

        return $order;
    }
}
