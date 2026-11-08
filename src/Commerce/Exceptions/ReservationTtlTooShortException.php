<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

/**
 * The app is configured so that stock reservations lapse before an unpaid
 * order is abandoned (X6b). In that window the stock is back on sale while the
 * gateway intent is still live: a late payment is captured for stock that is
 * gone, the order can neither confirm nor expire, and the customer has paid
 * for nothing. Thrown at boot so the misconfiguration stops a deploy instead
 * of surfacing on the first late payment.
 */
final class ReservationTtlTooShortException extends CommerceException
{
    public static function for(int $ttlMinutes, int $abandonAfterMinutes): self
    {
        return new self(
            "inventory.reservation_ttl ({$ttlMinutes} min) must be longer than commerce.order.abandon_after_minutes "
            ."({$abandonAfterMinutes} min): a reservation has to outlive the window in which its order can still be paid. "
            .'Raise INVENTORY_RESERVATION_TTL or lower ORDER_ABANDON_AFTER_MINUTES.'
        );
    }
}
