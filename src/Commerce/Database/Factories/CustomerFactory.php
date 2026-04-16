<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
