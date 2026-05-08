<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use InOtherShops\Shipping\Actions\CalculateShippingCost;
use InOtherShops\Shipping\Exceptions\MethodNotAvailableInZoneException;
use InOtherShops\Shipping\ShippingConfig;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CalculateShippingCostTest extends TestCase
{
    #[Test]
    public function it_returns_the_zone_rate_for_a_method(): void
    {
        $this->configureShipping(threshold: null);

        $cost = (new CalculateShippingCost)(
            ShippingConfig::method('standard'),
            ShippingConfig::zone('de'),
        );

        $this->assertSame(595, $cost);
    }

    #[Test]
    public function it_throws_when_method_has_no_rate_for_zone(): void
    {
        config()->set('shipping.zones', [
            'de' => ['currency' => 'EUR', 'countries' => ['DE']],
            'eu' => ['currency' => 'EUR', 'countries' => ['NL']],
        ]);
        config()->set('shipping.methods', [
            'express' => ['rates' => ['de' => 999]], // only DE
        ]);

        $this->expectException(MethodNotAvailableInZoneException::class);

        (new CalculateShippingCost)(
            ShippingConfig::method('express'),
            ShippingConfig::zone('eu'),
        );
    }

    #[Test]
    public function it_returns_zero_when_subtotal_meets_free_shipping_threshold(): void
    {
        $this->configureShipping(threshold: 5000);

        $cost = (new CalculateShippingCost)(
            ShippingConfig::method('standard'),
            ShippingConfig::zone('de'),
            subtotalCents: 5000,
        );

        $this->assertSame(0, $cost);
    }

    #[Test]
    public function it_returns_zero_when_subtotal_above_threshold(): void
    {
        $this->configureShipping(threshold: 5000);

        $cost = (new CalculateShippingCost)(
            ShippingConfig::method('standard'),
            ShippingConfig::zone('de'),
            subtotalCents: 9999,
        );

        $this->assertSame(0, $cost);
    }

    #[Test]
    public function it_charges_full_rate_when_subtotal_below_threshold(): void
    {
        $this->configureShipping(threshold: 5000);

        $cost = (new CalculateShippingCost)(
            ShippingConfig::method('standard'),
            ShippingConfig::zone('de'),
            subtotalCents: 4999,
        );

        $this->assertSame(595, $cost);
    }

    #[Test]
    public function it_ignores_threshold_when_subtotal_not_provided(): void
    {
        $this->configureShipping(threshold: 1);

        $cost = (new CalculateShippingCost)(
            ShippingConfig::method('standard'),
            ShippingConfig::zone('de'),
        );

        $this->assertSame(595, $cost);
    }

    private function configureShipping(?int $threshold): void
    {
        config()->set('shipping.zones', [
            'de' => [
                'currency' => 'EUR',
                'countries' => ['DE'],
                'free_shipping_threshold' => $threshold,
            ],
        ]);
        config()->set('shipping.methods', [
            'standard' => ['rates' => ['de' => 595]],
        ]);
    }
}
