<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Commands;

use InOtherShops\Commerce\Order\Actions\ExpireAbandonedOrders;
use InOtherShops\Logging\Concerns\RunsAsSystemActor;
use Illuminate\Console\Command;

/**
 * Cancel Pending orders that were never paid within their hold window (F14) —
 * releasing their reservations and cancelling the gateway intent so a late
 * payment can't land on them. Registered but NOT auto-scheduled: it cancels
 * orders and calls the payment gateway, so the consumer opts in explicitly by
 * scheduling it (e.g. every few minutes) in its own console kernel.
 */
final class ExpireAbandonedOrdersCommand extends Command
{
    use RunsAsSystemActor;

    protected $signature = 'commerce:expire-orders {--minutes= : Override the hold window (defaults to commerce.order.abandon_after_minutes)}';

    protected $description = 'Cancel unpaid Pending orders past their hold window, releasing stock and cancelling the gateway intent';

    public function handle(ExpireAbandonedOrders $expire): int
    {
        $this->beginSystemAuditActor();

        $minutesOption = $this->option('minutes');
        $minutes = $minutesOption === null ? null : (int) $minutesOption;

        $cancelled = $expire($minutes);

        $this->info("Expired {$cancelled} abandoned order(s).");

        return self::SUCCESS;
    }
}
