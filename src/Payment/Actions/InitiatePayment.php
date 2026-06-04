<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Actions;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Contracts\HasPaymentProfiles;
use InOtherShops\Payment\Contracts\HasPayments;
use InOtherShops\Payment\Contracts\ManagesCustomers;
use InOtherShops\Payment\Contracts\PaymentGateway;
use InOtherShops\Payment\DTOs\InitiatePaymentResult;
use InOtherShops\Payment\DTOs\PaymentCustomerData;
use InOtherShops\Payment\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Model;

/**
 * One-shot payment initiation: persist a Pending payment, resolve the gateway
 * customer (if a profileable is supplied), and open the gateway session — all in
 * one call. Suitable for callers that are NOT inside a DB transaction. Checkout
 * deliberately does NOT use this: it persists the payment inside its transaction
 * via {@see CreatePendingPayment} and opens the session after commit via
 * {@see OpenPaymentSession} (persist-then-pay, F1). This action composes those
 * two so single-shot callers keep one entry point and unchanged behaviour.
 */
final class InitiatePayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly CreatePendingPayment $createPendingPayment,
        private readonly OpenPaymentSession $openPaymentSession,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        string $gatewayName,
        Model&HasPayments $payable,
        int $amount,
        Currency $currency,
        string $returnUrl,
        string $cancelUrl,
        array $metadata = [],
        (Model&HasPaymentProfiles)|null $profileable = null,
        ?PaymentCustomerData $customerData = null,
    ): InitiatePaymentResult {
        $gateway = $this->gateways->gateway($gatewayName);

        $payment = ($this->createPendingPayment)($payable, $gatewayName, $amount, $currency, $metadata);

        $gatewayCustomerId = $this->resolveGatewayCustomerId($gateway, $profileable, $customerData);

        return ($this->openPaymentSession)($payment, $returnUrl, $cancelUrl, $gatewayCustomerId);
    }

    private function resolveGatewayCustomerId(PaymentGateway $gateway, (Model&HasPaymentProfiles)|null $profileable, ?PaymentCustomerData $customerData): ?string
    {
        if ($profileable === null) {
            return null;
        }

        $profile = $profileable->paymentProfileFor($gateway->identifier());

        if ($profile !== null) {
            return $profile->gateway_customer_id;
        }

        if ($customerData === null || ! $gateway instanceof ManagesCustomers) {
            return null;
        }

        $gatewayCustomerId = $gateway->createCustomer($customerData);

        $profileable->paymentProfiles()->create([
            'gateway' => $gateway->identifier(),
            'gateway_customer_id' => $gatewayCustomerId,
        ]);

        return $gatewayCustomerId;
    }
}
