<?php

declare(strict_types=1);

namespace InOtherShops\Shipping\Actions;

use InOtherShops\Shipping\DTOs\ShippingMethod;
use InOtherShops\Shipping\DTOs\ShippingZone;
use InOtherShops\Shipping\ShippingConfig;

final class ListAvailableShippingMethods
{
    /**
     * @return array<int, ShippingMethod>
     */
    public function __invoke(?ShippingZone $zone = null): array
    {
        $methods = array_filter(
            ShippingConfig::methods(),
            fn (ShippingMethod $m): bool => $m->isActive,
        );

        if ($zone !== null) {
            $methods = array_filter(
                $methods,
                fn (ShippingMethod $m): bool => $m->isAvailableInZone($zone->identifier),
            );
        }

        $methods = array_values($methods);

        usort(
            $methods,
            fn (ShippingMethod $a, ShippingMethod $b): int => $a->sortOrder <=> $b->sortOrder
                ?: strcmp($a->identifier, $b->identifier),
        );

        return $methods;
    }
}
