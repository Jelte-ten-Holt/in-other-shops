<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;

final class RetrievePaymentSession
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function __invoke(Payment $payment): PaymentSession
    {
        return $this->gateways->gateway($payment->gateway)->retrieveSession($payment);
    }
}
