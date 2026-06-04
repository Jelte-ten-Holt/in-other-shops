<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Payment\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Persist a Pending payment row for a payable — pure DB, no gateway network
 * call, so it is safe to run inside the caller's transaction (the persist half
 * of persist-then-pay, F1). The gateway session is opened separately, after
 * commit, by {@see OpenPaymentSession}.
 *
 * Resolving the gateway here only validates the name and canonicalises the
 * stored `gateway` identifier; it makes no network call.
 */
final class CreatePendingPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        Model&HasPayments $payable,
        string $gatewayName,
        int $amount,
        Currency $currency,
        array $metadata = [],
    ): Payment {
        $gateway = $this->gateways->gateway($gatewayName);

        return $payable->payments()->create([
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
            'gateway' => $gateway->identifier(),
            'gateway_data' => $metadata ?: null,
        ]);
    }
}
