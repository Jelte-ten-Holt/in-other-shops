<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Exceptions;

use DomainException;

class CommerceException extends DomainException
{
    public static function subtotalMismatch(int $linesTotal, int $subtotal): self
    {
        return new self(
            "Order line totals ({$linesTotal}) do not reconcile with the priced subtotal ({$subtotal}). "
            .'A per-line stored price diverged from the breakdown — e.g. a quantity-tier price resolved in '
            .'the breakdown but not in the stored line. Refusing to persist an order whose total ≠ sum(lines).'
        );
    }
}
