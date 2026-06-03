<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\DTOs;

final readonly class PriceBreakdownLine
{
    public function __construct(
        public string $description,
        public int $unitPrice,
        public int $quantity,
        public int $lineTotal,
        // The line's own VAT rate (basis points). Lines carry their rate so the
        // breakdown can group by bracket and a later per-line refund can reverse
        // the right tax; the tax amount itself lives at the bracket level.
        public int $taxRateBps = 0,
    ) {}
}
