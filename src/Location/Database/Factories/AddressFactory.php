<?php

declare(strict_types=1);

namespace InOtherShops\Location\Database\Factories;

use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Location\Location;
use InOtherShops\Location\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
final class AddressFactory extends Factory
{
    public function modelName(): string
    {
        return Location::address();
    }

    public function definition(): array
    {
        return [
            'type' => AddressType::Shipping,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'line_1' => fake()->streetAddress(),
            'line_2' => null,
            'city' => fake()->city(),
            'state' => null,
            'postal_code' => fake()->postcode(),
            'country_code' => 'NL',
            'phone' => null,
        ];
    }

    public function billing(): self
    {
        return $this->state(fn () => ['type' => AddressType::Billing]);
    }
}
