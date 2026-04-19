<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\Contracts;

interface HasOrders
{
    /**
     * Snapshot catalog data for order line creation.
     *
     * Quantity and line_total are checkout-time concerns and should
     * be supplied by the action that creates the order line.
     *
     * @param  string  $currencyCode  ISO 4217 currency code (e.g. 'EUR', 'USD')
     * @return array{description: string, sku: string|null, currency: string, unit_price: int, is_pre_order?: bool}
     */
    public function toOrderLineData(string $currencyCode): array;

    /**
     * @return array<string> ISO 4217 currency codes available for this orderable.
     */
    public function availableCurrencies(): array;
}
