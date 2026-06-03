<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Events;

use InOtherShops\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ItemsReceived
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $received  purchase order line id => quantity received in this receipt
     */
    public function __construct(
        public PurchaseOrder $order,
        public array $received,
    ) {}
}
