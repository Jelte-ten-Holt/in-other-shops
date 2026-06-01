<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CreatePrice;
use InOtherShops\Pricing\Actions\UpdatePrice;
use InOtherShops\Pricing\DTOs\PriceData;
use InOtherShops\Pricing\Exceptions\InvalidCompareAtPriceException;
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

    #[Test]
    public function a_strikethrough_amount_without_an_end_date_is_allowed(): void
    {
        // A permanent strikethrough (no auto-expiry) is valid — only the
        // inverse (end date without amount) is the orphan we reject.
        $priceable = TestPriceable::factory()->create();

        $price = (new CreatePrice)($priceable, new PriceData(
            amount: 4000,
            currency: Currency::EUR,
            compareAtAmount: 5000,
        ));

        $this->assertSame(5000, $price->fresh()->compare_at_amount);
        $this->assertNull($price->fresh()->compare_at_until);
    }

    #[Test]
    public function price_data_rejects_an_end_date_without_a_strikethrough_amount(): void
    {
        // A-3: an end date with no strikethrough amount is an orphan the
        // hourly sweep (which requires both columns) never cleans.
        $this->expectException(InvalidCompareAtPriceException::class);

        new PriceData(
            amount: 4000,
            currency: Currency::EUR,
            compareAtUntil: now()->addWeek(),
        );
    }

    #[Test]
    public function price_data_rejects_a_past_end_date(): void
    {
        // A-5: a past end date with a valid strikethrough amount shows a
        // strikethrough until the next hourly sweep — false advertising.
        $this->expectException(InvalidCompareAtPriceException::class);

        new PriceData(
            amount: 4000,
            currency: Currency::EUR,
            compareAtAmount: 5000,
            compareAtUntil: now()->subMinute(),
        );
    }

    #[Test]
    public function create_price_rejects_a_past_end_date_before_writing(): void
    {
        // The guard is at construction, so the action never gets the chance to
        // persist the row — nothing is written.
        $priceable = TestPriceable::factory()->create();

        try {
            (new CreatePrice)($priceable, new PriceData(
                amount: 4000,
                currency: Currency::EUR,
                compareAtAmount: 5000,
                compareAtUntil: now()->subDay(),
            ));
            $this->fail('Expected InvalidCompareAtPriceException.');
        } catch (InvalidCompareAtPriceException) {
            // expected
        }

        $this->assertSame(0, $priceable->prices()->count());
    }
}
