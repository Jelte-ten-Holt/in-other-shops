<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VariantCartableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_is_a_cartable(): void
    {
        $variant = Variant::factory()
            ->for(TestVariantable::factory()->create(), 'variantable')
            ->create();

        $this->assertInstanceOf(HasCart::class, $variant);
    }

    #[Test]
    public function it_composes_its_cart_label_from_the_owner_and_option_summary(): void
    {
        $owner = TestVariantable::factory()->create(['name' => 'Pendant']);
        $variant = Variant::factory()->for($owner, 'variantable')->create();

        $color = Option::factory()->create(['position' => 0]);
        $silver = OptionValue::factory()->for($color)->create();
        $silver->setTranslation('label', 'en', 'Silver');
        $variant->optionValues()->attach($silver->id);

        $this->assertSame('Pendant — Silver', $variant->fresh()->getCartableLabel());
    }

    #[Test]
    public function it_resolves_its_cart_unit_price_from_its_own_price(): void
    {
        $variant = Variant::factory()
            ->for(TestVariantable::factory()->create(), 'variantable')
            ->create();
        $variant->prices()->create(['amount' => 2500, 'currency' => 'EUR', 'minimum_quantity' => 1]);

        $this->assertSame(2500, $variant->getCartableUnitPrice(Currency::EUR));
        $this->assertNull($variant->getCartableUnitPrice(Currency::USD));
    }
}
