<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Database\Factories\Concerns;

use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Location\Location;
use Illuminate\Database\Eloquent\Model;

/**
 * Attaches a shipping + billing address pair to an addressable after creation.
 *
 * Shared by {@see \InOtherShops\Commerce\Database\Factories\OrderFactory} and
 * {@see \InOtherShops\Commerce\Database\Factories\CustomerFactory}, which build
 * the identical pair. First factory-concern trait in the package; the
 * convention is `{Domain}/Database/Factories/Concerns/`.
 */
trait CreatesAddressPair
{
    public function withAddresses(): static
    {
        return $this->afterCreating(function (Model $addressable): void {
            Location::address()::factory()
                ->for($addressable, 'addressable')
                ->state(['type' => AddressType::Shipping])
                ->create();

            Location::address()::factory()
                ->for($addressable, 'addressable')
                ->state(['type' => AddressType::Billing])
                ->create();
        });
    }
}
