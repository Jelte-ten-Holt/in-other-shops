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
use InOtherShops\Payment\DTOs\PaymentSession;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Model;

final class InitiatePayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @param  Model&HasPayments  $payable
     * @param  (Model&HasPaymentProfiles)|null  $profileable
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        Model $payable,
        int $amount,
        Currency $currency,
        string $returnUrl,
        string $cancelUrl,
        array $metadata = [],
        ?Model $profileable = null,
        ?PaymentCustomerData $customerData = null,
    ): InitiatePaymentResult {
        $payment = $this->createPaymentRecord($payable, $amount, $currency, $metadata);

        $gatewayCustomerId = $this->resolveGatewayCustomerId($profileable, $customerData);

        $session = $this->createGatewaySession($payment, $returnUrl, $cancelUrl, $gatewayCustomerId);

        $this->updateWithGatewayReference($payment, $session);

        return new InitiatePaymentResult(
            payment: $payment,
            redirectUrl: $session->redirectUrl,
        );
    }

    /**
     * @param  (Model&HasPaymentProfiles)|null  $profileable
     */
    private function resolveGatewayCustomerId(?Model $profileable, ?PaymentCustomerData $customerData): ?string
    {
        if ($profileable === null || ! $profileable instanceof HasPaymentProfiles) {
            return null;
        }

        $profile = $profileable->paymentProfileFor($this->gateway->identifier());

        if ($profile !== null) {
            return $profile->gateway_customer_id;
        }

        if ($customerData === null || ! $this->gateway instanceof ManagesCustomers) {
            return null;
        }

        $gatewayCustomerId = $this->gateway->createCustomer($customerData);

        $profileable->paymentProfiles()->create([
            'gateway' => $this->gateway->identifier(),
            'gateway_customer_id' => $gatewayCustomerId,
        ]);

        return $gatewayCustomerId;
    }

    /**
     * @param  Model&HasPayments  $payable
     * @param  array<string, mixed>  $metadata
     */
    private function createPaymentRecord(Model $payable, int $amount, Currency $currency, array $metadata): Payment
    {
        return $payable->payments()->create([
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
            'gateway' => $this->gateway->identifier(),
            'gateway_data' => $metadata ?: null,
        ]);
    }

    private function createGatewaySession(Payment $payment, string $returnUrl, string $cancelUrl, ?string $gatewayCustomerId): PaymentSession
    {
        return $this->gateway->createSession($payment, $returnUrl, $cancelUrl, $gatewayCustomerId);
    }

    private function updateWithGatewayReference(Payment $payment, PaymentSession $session): void
    {
        $payment->update([
            'gateway_reference' => $session->gatewayReference,
            'gateway_data' => $session->gatewayData,
        ]);
    }
}
