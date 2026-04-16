<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Database\Factories;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment as PaymentModel;
use InOtherShops\Payment\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentModel>
 */
final class PaymentFactory extends Factory
{
    public function modelName(): string
    {
        return Payment::payment();
    }

    public function definition(): array
    {
        return [
            'amount' => 1000,
            'amount_refunded' => 0,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Pending,
            'gateway' => 'stub',
            'gateway_reference' => null,
            'gateway_data' => null,
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn () => ['status' => PaymentStatus::Succeeded]);
    }

    public function failed(): self
    {
        return $this->state(fn () => ['status' => PaymentStatus::Failed]);
    }

    public function partiallyRefunded(int $refunded): self
    {
        return $this->state(fn (array $attrs) => [
            'status' => PaymentStatus::PartiallyRefunded,
            'amount_refunded' => $refunded,
        ]);
    }

    public function refunded(): self
    {
        return $this->state(fn (array $attrs) => [
            'status' => PaymentStatus::Refunded,
            'amount_refunded' => $attrs['amount'] ?? 1000,
        ]);
    }
}
