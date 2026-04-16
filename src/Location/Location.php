<?php

declare(strict_types=1);

namespace InOtherShops\Location;

use InOtherShops\Location\Models\Address;

final class Location
{
    /** @return class-string<Address> */
    public static function address(): string
    {
        return config('location.models.address', Address::class);
    }
}
