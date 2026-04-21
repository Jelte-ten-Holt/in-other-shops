<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Customer\Models\CustomerGroup;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Location\Location;

/**
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::customer();
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
        ];
    }

    public function forGroup(?CustomerGroup $group = null): static
    {
        return $this->state(fn (): array => [
            'customer_group_id' => $group?->id,
        ]);
    }

    public function withAddresses(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            Location::address()::factory()
                ->for($customer, 'addressable')
                ->state(['type' => AddressType::Shipping])
                ->create();

            Location::address()::factory()
                ->for($customer, 'addressable')
                ->state(['type' => AddressType::Billing])
                ->create();
        });
    }
}
