<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Events;

use InOtherShops\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class PurchaseOrderPlaced
{
    use Dispatchable;

    public function __construct(
        public PurchaseOrder $order,
    ) {}
}
