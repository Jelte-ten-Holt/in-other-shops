<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Contracts;

use Carbon\CarbonInterface;

/**
 * A model that may hold stock through Pending reservations with no expiry —
 * a stock hold, or an admin order waiting for a bank transfer (stock-holds
 * brief §4.5). `inventory:reconcile` reports every Pending reservation with
 * no TTL as an error unless its reference implements this and says its
 * reservations are legitimately open; the reconcile lists the open ones held
 * past `inventory.hold_warn_after_days` as warnings, never as drift (D15).
 *
 * Inventory asks the reference rather than knowing what an order is: the
 * domain stays below Commerce in the dependency graph.
 */
interface HasOpenReservations
{
    /**
     * Whether this reference's Pending, no-TTL reservations are legitimate
     * right now — an open hold, an order still waiting to be paid.
     */
    public function keepsReservationsOpen(): bool;

    /**
     * When its reservations count as held from, for the stale warning.
     */
    public function reservationsOpenSince(): CarbonInterface;
}
