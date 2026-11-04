<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Actions;

use Illuminate\Support\Facades\DB;
use InOtherShops\Commerce\Exceptions\StockHoldChangedException;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Support\OrderStockDemand;
use InOtherShops\Inventory\Actions\ConfirmReservation;
use InOtherShops\Inventory\Actions\ReserveFromHold;
use InOtherShops\Inventory\Actions\ReserveStock;
use InOtherShops\Inventory\Models\StockHold;
use InOtherShops\Inventory\Support\StockLocks;

/**
 * An admin order's stock step (stock-holds brief §4.3): takes what its lines
 * need from main stock, or from a hold first and main stock for the rest (D5).
 * "Don't take stock" is simply not calling this. The order page runs it in
 * `afterCreate()`, inside the page's transaction, after the lines are saved.
 *
 * Its reservations never expire (a bank transfer can take days); Mark paid
 * confirms them through ConfirmOrder, and *Update status → Confirmed* through
 * the status listener. An order created Confirmed has them confirmed here, in
 * the same transaction; one created Cancelled takes nothing (§8 Q2).
 *
 * Main-stock reservations use `rejectOversell: ! allowsBackorder()`, as
 * checkout does: a backorderable product is never refused. A shortfall the hold
 * leaves is taken from main stock; if main stock cannot cover it either,
 * InsufficientStockException rolls the whole order back.
 *
 * With `$heldAsShown` — what the page showed the hold holding, by stock group
 * ({@see StockHold::heldByGroup()}) — a hold that changed in between so that
 * the split comes out different refuses the save (StockHoldChangedException)
 * before main stock is touched.
 *
 * Lock order (§4.2): the hold row, then every stock item the lines reach,
 * sorted, then reservation rows, then inserts.
 */
final class ReserveOrderStock
{
    private const string SOURCE = 'dashboard';

    public function __construct(
        private readonly ReserveStock $reserveStock,
        private readonly ReserveFromHold $reserveFromHold,
        private readonly ConfirmReservation $confirmReservation,
    ) {}

    /**
     * @param  array<string, int>|null  $heldAsShown
     */
    public function __invoke(Order $order, ?StockHold $hold = null, ?array $heldAsShown = null): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            return;
        }

        $entries = OrderStockDemand::forOrder($order);

        if ($entries === []) {
            return;
        }

        DB::transaction(function () use ($order, $hold, $heldAsShown, $entries): void {
            if ($hold !== null) {
                $hold = StockLocks::lockHold($hold);
            }

            StockLocks::lock(array_column($entries, 'stockable'));

            $shortfalls = $hold === null
                ? array_column($entries, 'quantity')
                : $this->takeFromHold($order, $hold, $entries, $heldAsShown);

            foreach ($entries as $index => $entry) {
                if ($shortfalls[$index] > 0) {
                    ($this->reserveStock)(
                        stockable: $entry['stockable'],
                        quantity: $shortfalls[$index],
                        description: "Admin order {$order->order_number}",
                        reference: $order,
                        source: self::SOURCE,
                        rejectOversell: ! $entry['stockable']->allowsBackorder(),
                        expires: false,
                    );
                }
            }

            if ($order->status === OrderStatus::Confirmed) {
                ($this->confirmReservation)($order);
            }
        });
    }

    /**
     * @param  list<array{stockable: \Illuminate\Database\Eloquent\Model&\InOtherShops\Inventory\Contracts\HasStock, quantity: int, line: int, group: string}>  $entries
     * @param  array<string, int>|null  $heldAsShown
     * @return list<int>  each entry's shortfall, for main stock
     */
    private function takeFromHold(Order $order, StockHold $hold, array $entries, ?array $heldAsShown): array
    {
        $shortfalls = [];
        $moved = [];

        foreach ($entries as $entry) {
            $move = ($this->reserveFromHold)(
                hold: $hold,
                stockable: $entry['stockable'],
                quantity: $entry['quantity'],
                reference: $order,
                description: "Moved from hold {$hold->name} to order {$order->order_number}",
            );

            $shortfalls[] = $move->shortfall;

            if ($move->fromHold > 0) {
                $moved[$entry['group']] = ($moved[$entry['group']] ?? 0) + $move->fromHold;
            }
        }

        ksort($moved);

        if ($heldAsShown !== null && $moved !== OrderStockDemand::fromHoldByGroup(OrderStockDemand::split($entries, $heldAsShown))) {
            throw StockHoldChangedException::for($hold);
        }

        return $shortfalls;
    }
}
