<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CreatePrice;
use InOtherShops\Pricing\Actions\UpdatePrice;
use InOtherShops\Pricing\DTOs\PriceData;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * CreatePrice / UpdatePrice take a PriceData DTO — new fields land on the DTO
 * once rather than resignaturing the actions and every callsite. These tests
 * pin that the full shape, including the strikethrough fields, round-trips.
 */
final class CreateUpdatePriceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_price_persists_the_full_data_shape(): void
    {
        $priceable = TestPriceable::factory()->create();
        $until = now()->addWeek()->startOfMinute();

        $price = (new CreatePrice)($priceable, new PriceData(
            amount: 4000,
            currency: Currency::EUR,
            compareAtAmount: 5000,
            compareAtUntil: $until,
            minimumQuantity: 1,
        ));

        $fresh = $price->fresh();
        $this->assertSame(4000, $fresh->amount);
        $this->assertSame(5000, $fresh->compare_at_amount);
        $this->assertSame(Currency::EUR, $fresh->currency);
        $this->assertTrue($until->equalTo($fresh->compare_at_until));
    }

    #[Test]
    public function update_price_persists_the_full_data_shape(): void
    {
        $priceable = TestPriceable::factory()->create();
        $price = $priceable->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 4000,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        (new UpdatePrice)($price, new PriceData(
            amount: 4500,
            currency: Currency::EUR,
            compareAtAmount: 6000,
        ));

        $fresh = $price->fresh();
        $this->assertSame(4500, $fresh->amount);
        $this->assertSame(6000, $fresh->compare_at_amount);
        $this->assertNull($fresh->compare_at_until);
    }
}
