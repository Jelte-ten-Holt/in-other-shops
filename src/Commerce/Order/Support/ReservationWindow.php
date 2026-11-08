<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Support;

use InOtherShops\Commerce\Exceptions\ReservationTtlTooShortException;

/**
 * The one ordering the stock and the order timers must keep (X6b, decided
 * 2026-09-07): a reservation outlives the window in which its order can still
 * be paid. `commerce:expire-orders` is then the single actor that ends a
 * Pending order, and `inventory:release-expired` never returns stock under a
 * live gateway intent. Commerce reads Inventory's config here — a config-key
 * coupling inside the shop core.
 *
 * A null TTL means reservations never lapse on their own, which keeps the
 * ordering trivially.
 */
final class ReservationWindow
{
    public static function assertOutlivesAbandonWindow(): void
    {
        $ttl = config('inventory.reservation_ttl');

        if ($ttl === null) {
            return;
        }

        $abandon = (int) config('commerce.order.abandon_after_minutes', 60);

        if ((int) $ttl <= $abandon) {
            throw ReservationTtlTooShortException::for((int) $ttl, $abandon);
        }
    }
}
