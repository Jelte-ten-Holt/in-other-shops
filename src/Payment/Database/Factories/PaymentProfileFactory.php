<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Database\Factories;

use InOtherShops\Payment\Models\PaymentProfile;
use InOtherShops\Payment\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentProfile>
 */
final class PaymentProfileFactory extends Factory
{
    public function modelName(): string
    {
        return Payment::paymentProfile();
    }

    public function definition(): array
    {
        return [
            'profileable_type' => 'customer',
            'profileable_id' => 1,
            'gateway' => 'stripe',
            'gateway_customer_id' => 'cus_'.fake()->unique()->regexify('[a-zA-Z0-9]{14}'),
            'gateway_data' => null,
        ];
    }
}
