<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Actions;

use InOtherShops\Inventory\Enums\ReservationStatus;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Inventory\Events\ReservationReleased;
use InOtherShops\Inventory\Events\StockReleased;
use InOtherShops\Inventory\Inventory;
use InOtherShops\Inventory\Models\StockReservation;
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
 */
final class ReleaseReservation
{
    public function __construct(
        private readonly AdjustStock $adjustStock,
    ) {}

    public function __invoke(StockReservation $reservation, ?string $description = null, bool $onlyIfExpired = false): ?StockReservation
    {
        $released = DB::transaction(
            fn (): ?StockReservation => $this->release($reservation->getKey(), $description, $onlyIfExpired),
        );

        if ($released !== null) {
            ReservationReleased::dispatch($released);
            StockReleased::dispatch($released, $released->releaseMovement);
        }

        return $released;
    }

    private function release(int $reservationId, ?string $description, bool $onlyIfExpired): ?StockReservation
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
            reference: $reservation->reference,
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
