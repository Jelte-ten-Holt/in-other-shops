<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories;

use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Customer\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerGroup>
 */
final class CustomerGroupFactory extends Factory
{
    public function modelName(): string
    {
        return Commerce::customerGroup();
    }

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'code' => fake()->unique()->slug(2),
        ];
    }
}
