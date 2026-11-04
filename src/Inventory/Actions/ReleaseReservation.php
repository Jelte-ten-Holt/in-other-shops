<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Inventory\Contracts\HasStock;
use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
use InOtherShops\Inventory\Support\StockLocks;
use Illuminate\Support\Facades\DB;

/**
 * Release a single reservation — appends a `+X Released` ledger movement
 * and transitions the reservation Pending|Confirmed → Released. The
 * compensating `+X Released` entry returns the reserved stock to available
 * regardless of which state the reservation was in (Confirmed reservations
 * also held stock; confirming only moved the lifecycle marker, not the
 * ledger).
 *
 * Returns null when the reservation is already Released (or no longer
 * exists). The locked select + status guard makes concurrent calls
 * idempotent — safe to call from multiple paths (admin cancellation,
 * payment-fail listener, status-change listener).
 *
 * `$onlyIfExpired` tightens the guard for the expiry-sweep caller: it releases
 * a reservation only if, under the lock, it is *still* Pending and *still* past
 * its TTL. The default (false) keeps the permissive Pending|Confirmed behaviour
 * the manual/payment-fail/status-change paths rely on. The expiry cron snapshots
 * candidate ids without a lock, so a payment confirmation can flip a row
 * Pending→Confirmed in the gap; without the tighter re-check the cron would
 * release stock for an order that was just paid (oversell after payment).
 *
 * An optional `$description` overrides the release movement's ledger text —
 * pass the *reason* for releasing (e.g. "Payment failed for order …",
 * "Refund restock") so the audit trail records why the stock came back, not
 * the original reservation text. Defaults to the reservation's own description.
 *
 * An optional `$reference` overrides the release movement's reference. The
 * order-deleted hook needs it: the order row is gone by then, so
 * `$reservation->reference` resolves to null, but the in-memory order still
 * knows its morph class and key.
 *
 * Lock order (stock-holds brief §4.2): the stock items of the reservation's
 * group first, sorted ({@see StockLocks}), then the reservation row. The
 * stock item is known before the row is locked — `stock_item_id` never
 * changes — so every releaser stacks the same way as every reserver, and the
 * optimizer's choice of index for the row lock stops mattering.
 */
final class ReleaseReservation
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    public function __invoke(
        StockReservation $reservation,
        ?string $description = null,
        bool $onlyIfExpired = false,
        ?Model $reference = null,
    ): ?StockReservation {
        $stockable = $this->stockableOf($reservation);

        $released = DB::transaction(function () use ($reservation, $stockable, $description, $onlyIfExpired, $reference): ?StockReservation {
            if ($stockable !== null) {
                StockLocks::lock([$stockable]);
            }

            return $this->release($reservation->getKey(), $description, $onlyIfExpired, $reference);
        });

        if ($released !== null) {
            ReservationReleased::dispatch($released);
            StockReleased::dispatch($released, $released->releaseMovement);
        }

        return $released;
    }

    /**
     * Read without a lock: which stockable a reservation holds never changes.
     */
    private function stockableOf(StockReservation $reservation): (Model&HasStock)|null
    {
        $stockable = Inventory::stockItem()::query()->find($reservation->stock_item_id)?->stockable;

        return $stockable instanceof HasStock ? $stockable : null;
    }

    private function release(int $reservationId, ?string $description, bool $onlyIfExpired, ?Model $reference): ?StockReservation
    {
        $model = Inventory::stockReservation();

        $query = $model::query()
            ->where('id', $reservationId)
            ->with('stockItem.stockable')
            ->lockForUpdate();

        if ($onlyIfExpired) {
            // Expiry path: re-validate under the lock that the row is still a
            // Pending reservation past its TTL — never release a Confirmed
            // (paid) one, even if it looked expired when the sweep snapshotted
            // its id.
            $query->where('status', ReservationStatus::Pending)
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '<=', now());
        } else {
            $query->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed]);
        }

        /** @var StockReservation|null $reservation */
        $reservation = $query->first();

        if ($reservation === null) {
            return null;
        }

        $stockable = $reservation->stockItem->stockable;

        $releaseMovement = ($this->adjustStock)(
            stockable: $stockable,
            quantity: $reservation->quantity,
            reason: StockMovementReason::Released,
            description: $description ?? $reservation->description,
            reference: $reference ?? $reservation->reference,
            source: $reservation->source,
        );

        $reservation->update([
            'status' => ReservationStatus::Released,
            'release_movement_id' => $releaseMovement->id,
            'resolved_at' => now(),
        ]);

        return $reservation;
    }
}
