<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Exceptions;

/**
 * A purchase order's supplier invoice or its payment would be stored in a shape
 * the books cannot use: half an invoice, a total its per-rate summary does not
 * add up to, or a payment without its amount.
 */
final class SupplierInvoiceException extends PurchasingException
{
    public static function incomplete(): self
    {
        return new self('A supplier invoice needs its number, date, gross total and per-rate tax summary together.');
    }

    public static function totalDisagrees(int $total, int $summed): self
    {
        return new self("The supplier invoice total ({$total}) is not the sum of its per-rate net and tax ({$summed}).");
    }

    public static function negativeAmount(): self
    {
        return new self('A supplier invoice tax summary holds a negative rate, net or tax amount.');
    }

    public static function paymentIncomplete(): self
    {
        return new self('A supplier payment needs both its date and a positive amount.');
    }
}
