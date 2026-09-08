<?php

declare(strict_types=1);

namespace InOtherShops\Inventory\Events;

use InOtherShops\Inventory\DTOs\StockReconciliationReport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reconciliation pass found drift. Dispatched by {@see \InOtherShops\Inventory\Actions\ReconcileStock}
 * whenever the report it returns is not clean — from the scheduled
 * `inventory:reconcile` run and from any manual invocation alike.
 *
 * This is the point of scheduling the tripwire at all: a warning line nobody
 * reads is not detection. Consumers subscribe and alert (bianka's
 * `OrderConfirmationBlocked → AlertOperatorToBlockedOrder` is the same shape).
 * The package ships no listener — what "alert" means is each shop's call.
 *
 * Read-only: the report describes drift, it does not repair it.
 */
final readonly class InventoryDriftDetected
{
    use Dispatchable;

    public function __construct(
        public StockReconciliationReport $report,
    ) {}
}
