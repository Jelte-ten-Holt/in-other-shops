<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Exceptions\InvalidCompareAtPriceException;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guard A — the model invariant. A strikethrough (`compare_at_amount`) is only
 * a discount if it sits strictly above the actual `amount`. Enforced on the
 * model itself so every write path — actions, both Filament surfaces, the
 * expiry command — is covered by one guard rather than per-callsite checks.
 */
final class CompareAtPriceInvariantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_a_compare_at_amount_equal_to_the_price(): void
    {
        $priceable = TestPriceable::factory()->create();

        $this->expectException(InvalidCompareAtPriceException::class);

        $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => 1000,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);
    }

    #[Test]
    public function it_rejects_a_compare_at_amount_below_the_price(): void
    {
        $priceable = TestPriceable::factory()->create();

        $this->expectException(InvalidCompareAtPriceException::class);

        $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => 800,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);
    }

    #[Test]
    public function it_allows_a_compare_at_amount_above_the_price(): void
    {
        $priceable = TestPriceable::factory()->create();

        $price = $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => 1500,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        $this->assertSame(1500, $price->fresh()->compare_at_amount);
    }

    #[Test]
    public function it_allows_a_null_compare_at_amount(): void
    {
        $priceable = TestPriceable::factory()->create();

        $price = $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => null,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        $this->assertNull($price->fresh()->compare_at_amount);
    }

    #[Test]
    public function it_rejects_an_update_that_pushes_compare_at_below_the_price(): void
    {
        $priceable = TestPriceable::factory()->create();
        $price = $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'compare_at_amount' => 1500,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        $this->expectException(InvalidCompareAtPriceException::class);

        $price->update(['compare_at_amount' => 900]);
    }
}
