<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\DTOs;

/**
 * The result of reconciling each purchase-order line's `quantity_received`
 * aggregate against its movement ledger (G11). `quantity_received` is a
 * delta-maintained counter bumped by {@see \InOtherShops\Purchasing\Actions\ReceiveItems};
 * the authoritative record is the set of `Received` stock movements referencing
 * the line. Any divergence means the counter drifted from what was physically
 * received.
 */
final readonly class PurchaseReceiptReconciliationReport
{
    /**
     * @param  list<array{line_id: int, purchase_order_id: int, recorded: int, ledger: int}>  $mismatches
     */
    public function __construct(
        public array $mismatches,
    ) {}

    public function isClean(): bool
    {
        return $this->mismatches === [];
    }

    public function issueCount(): int
    {
        return count($this->mismatches);
    }
}
