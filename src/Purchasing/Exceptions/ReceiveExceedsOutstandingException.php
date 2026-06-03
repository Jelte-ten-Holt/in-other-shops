<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Exceptions;

final class ReceiveExceedsOutstandingException extends PurchasingException
{
    public static function forLine(int $lineId, int $requested, int $outstanding): self
    {
        return new self(
            "Cannot receive {$requested} for purchase order line [{$lineId}]: only {$outstanding} outstanding.",
        );
    }
}
