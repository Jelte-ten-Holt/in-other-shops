<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Contracts;

use InOtherShops\Payment\DTOs\GatewayRefund;
use InOtherShops\Payment\Models\Payment;

/**
 * Optional interface for gateways that can list the refunds made against a
 * payment, whoever made them. Separate from `PaymentGateway` for the same
 * reason as {@see ManagesCustomers}: not every gateway can, and a refund
 * webhook from one that cannot still moves the payment total.
 */
interface ListsRefunds
{
    /**
     * The gateway's own refunds for this payment that returned money or are
     * returning it, oldest first.
     *
     * @return list<GatewayRefund>
     */
    public function listRefunds(Payment $payment): array;
}
