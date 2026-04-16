<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Events;

use InOtherShops\Pricing\Models\Voucher;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class VoucherApplied
{
    use Dispatchable;

    public function __construct(
        public Voucher $voucher,
    ) {}
}
