<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Enums;

/**
 * The result of {@see \InOtherShops\Commerce\Order\Actions\ConfirmOrder}. The
 * caller (a payment-success handler) acts on it: only `Confirmed` should trigger
 * the buyer-facing side effects (confirmation email, cart clear); the others are
 * either harmless redelivery or a flagged exception that needs no email.
 */
enum ConfirmOrderOutcome: string
{
    /** The order transitioned Pending → Confirmed in this call. */
    case Confirmed = 'confirmed';

    /** The order was already Confirmed — a duplicate/redelivered success. No-op. */
    case AlreadyConfirmed = 'already_confirmed';

    /** Payment succeeded but the order's stock reservations were released (F14). Flagged, not confirmed. */
    case StockUnavailable = 'stock_unavailable';

    /** Payment succeeded but the order is not in a confirmable state (e.g. Cancelled). Flagged, not confirmed. */
    case NotConfirmable = 'not_confirmable';
}
