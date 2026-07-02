<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Pricing\Filament\Resources\VoucherResource;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Tax\Filament\Resources\TaxRateResource;
use InOtherShops\Taxonomy\Filament\Resources\CategoryResource;
use InOtherShops\Variants\Filament\Resources\OptionResource;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T-S2 (D3) — one navigation group per domain, no shared "Shop" catch-all.
 * Pricing/Tax/Variants (formerly under "Shop") now render under their own
 * domain group, and every package Resource reports a NavigationGroup enum case
 * rather than a bare string.
 */
final class NavigationGroupTest extends TestCase
{
    #[Test]
    public function the_formerly_shop_resources_now_sit_under_their_own_domain_group(): void
    {
        $this->assertSame(NavigationGroup::Tax, TaxRateResource::getNavigationGroup());
        $this->assertSame(NavigationGroup::Pricing, VoucherResource::getNavigationGroup());
        $this->assertSame(NavigationGroup::Variants, OptionResource::getNavigationGroup());
    }

    #[Test]
    public function existing_domain_resources_keep_their_group_as_the_enum(): void
    {
        $this->assertSame(NavigationGroup::Commerce, OrderResource::getNavigationGroup());
        $this->assertSame(NavigationGroup::Taxonomy, CategoryResource::getNavigationGroup());
    }

    #[Test]
    public function no_package_resource_uses_a_string_navigation_group(): void
    {
        foreach ([
            TaxRateResource::class,
            VoucherResource::class,
            OptionResource::class,
            OrderResource::class,
            CategoryResource::class,
        ] as $resource) {
            $this->assertInstanceOf(
                NavigationGroup::class,
                $resource::getNavigationGroup(),
                "{$resource} must use the NavigationGroup enum, not a string group.",
            );
        }
    }
}
