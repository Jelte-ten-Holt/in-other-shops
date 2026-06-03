<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\DTOs;

final readonly class TaxSnapshot
{
    public function __construct(
        // Headline order-level rate. Null for a mixed-rate order — the
        // authoritative per-rate detail lives in the order's tax_summary.
        public ?int $rateBps,
        public string $countryCode,
    ) {}
}
