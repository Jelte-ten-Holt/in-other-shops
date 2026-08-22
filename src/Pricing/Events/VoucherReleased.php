<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InOtherShops\Pricing\Models\Voucher;

final readonly class VoucherReleased
{
    use Dispatchable;

    public function __construct(
        public Voucher $voucher,
    ) {}
}
