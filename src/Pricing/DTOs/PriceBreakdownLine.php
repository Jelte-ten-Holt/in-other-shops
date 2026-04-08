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
    ) {}
}
