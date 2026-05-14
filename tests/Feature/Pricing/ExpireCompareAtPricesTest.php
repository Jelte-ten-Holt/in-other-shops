<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\ExpireCompareAtPrices;
use InOtherShops\Pricing\Events\CompareAtPriceExpired;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The scheduled cutover. When `compare_at_until` passes, the strikethrough
 * value becomes the actual price and the strikethrough is cleared — so an
 * early-bird window can be configured during business hours and end at its
 * exact time without anyone flipping prices by hand.
 */
final class ExpireCompareAtPricesTest extends TestCase
{
    use RefreshDatabase;

    private ExpireCompareAtPrices $expire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expire = new ExpireCompareAtPrices;
    }

    #[Test]
    public function it_promotes_a_price_whose_strikethrough_window_has_closed(): void
    {
        $price = $this->priceWith([
            'amount' => 4000,
            'compare_at_amount' => 5000,
            'compare_at_until' => now()->subMinute(),
        ]);

        $expired = ($this->expire)();

        $this->assertCount(1, $expired);

        $fresh = $price->fresh();
        $this->assertSame(5000, $fresh->amount, 'The strikethrough value becomes the actual price.');
        $this->assertNull($fresh->compare_at_amount);
        $this->assertNull($fresh->compare_at_until);
    }

    #[Test]
    public function it_leaves_a_strikethrough_whose_window_is_still_open(): void
    {
        $price = $this->priceWith([
            'amount' => 4000,
            'compare_at_amount' => 5000,
            'compare_at_until' => now()->addWeek(),
        ]);

        $this->assertCount(0, ($this->expire)());
        $this->assertSame(4000, $price->fresh()->amount);
        $this->assertSame(5000, $price->fresh()->compare_at_amount);
    }

    #[Test]
    public function it_leaves_a_strikethrough_with_no_end_date_alone(): void
    {
        $price = $this->priceWith([
            'amount' => 4000,
            'compare_at_amount' => 5000,
            'compare_at_until' => null,
        ]);

        $this->assertCount(0, ($this->expire)());
        $this->assertSame(4000, $price->fresh()->amount);
    }

    #[Test]
    public function it_skips_a_row_with_an_end_date_but_no_compare_at_amount(): void
    {
        // Defensive: promoting this row would write a null `amount`. The
        // Filament layer prevents the state, but the command must not trust it.
        $price = $this->priceWith([
            'amount' => 4000,
            'compare_at_amount' => null,
            'compare_at_until' => now()->subMinute(),
        ]);

        $this->assertCount(0, ($this->expire)());
        $this->assertSame(4000, $price->fresh()->amount);
    }

    #[Test]
    public function it_dispatches_compare_at_price_expired_with_the_previous_amount(): void
    {
        Event::fake([CompareAtPriceExpired::class]);

        $price = $this->priceWith([
            'amount' => 4000,
            'compare_at_amount' => 5000,
            'compare_at_until' => now()->subMinute(),
        ]);

        ($this->expire)();

        Event::assertDispatched(
            CompareAtPriceExpired::class,
            fn (CompareAtPriceExpired $event): bool => $event->price->id === $price->id
                && $event->previousAmount === 4000
                && $event->price->amount === 5000,
        );
    }

    #[Test]
    public function running_twice_in_succession_is_idempotent(): void
    {
        $this->priceWith([
            'amount' => 4000,
            'compare_at_amount' => 5000,
            'compare_at_until' => now()->subMinute(),
        ]);

        $first = ($this->expire)();
        $second = ($this->expire)();

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'A promoted price no longer has an end date — nothing to re-promote.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function priceWith(array $attributes)
    {
        $priceable = TestPriceable::factory()->create();

        return $priceable->prices()->create(array_merge([
            'currency' => Currency::EUR->value,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ], $attributes));
    }
}
