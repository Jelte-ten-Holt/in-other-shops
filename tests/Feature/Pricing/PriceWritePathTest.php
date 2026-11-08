<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Events\PriceCreated;
use InOtherShops\Pricing\Events\PriceDeleted;
use InOtherShops\Pricing\Events\PriceUpdated;
use InOtherShops\Pricing\Exceptions\InvalidCompareAtPriceException;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * X1 (complexity cleanup release 2, PR 5): a price is written through the
 * model. Its hooks dispatch the audit events and hold the strikethrough
 * guards, so the admin repeater, agent tools and anything else that writes a
 * price directly are covered — the old CreatePrice/UpdatePrice actions were
 * skipped by the repeater, which left no audit row. Replaces
 * CreateUpdatePriceTest; the not-above-price rule is in
 * CompareAtPriceInvariantTest.
 */
final class PriceWritePathTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_updating_and_deleting_a_price_each_dispatch_their_event(): void
    {
        Event::fake([PriceCreated::class, PriceUpdated::class, PriceDeleted::class]);
        $priceable = TestPriceable::factory()->create();

        $price = $priceable->prices()->create(['currency' => Currency::EUR, 'amount' => 4000, 'minimum_quantity' => 1]);
        $price->update(['amount' => 4500]);
        $price->delete();

        Event::assertDispatchedTimes(PriceCreated::class, 1);
        Event::assertDispatched(PriceCreated::class, fn (PriceCreated $event): bool => $event->price->is($price));
        Event::assertDispatched(PriceUpdated::class, fn (PriceUpdated $event): bool => $event->price->is($price) && $event->fromExpiry === false);
        Event::assertDispatched(PriceDeleted::class, fn (PriceDeleted $event): bool => $event->priceId === $price->id
            && $event->priceableType === $priceable->getMorphClass()
            && $event->priceableId === $priceable->id);
    }

    /** An admin saving the form without touching the price leaves no audit row. */
    #[Test]
    public function an_unchanged_save_dispatches_nothing(): void
    {
        $price = $this->price(['amount' => 4000]);
        Event::fake([PriceUpdated::class]);

        $price->fill(['amount' => 4000])->save();

        Event::assertNotDispatched(PriceUpdated::class);
    }

    /**
     * Filament drops the hidden end-date field when the strikethrough is
     * cleared, so the old date stays on the row. That has to save, not 500,
     * and must not leave an orphan date the sweep never cleans.
     */
    #[Test]
    public function clearing_a_strikethrough_clears_its_end_date_even_an_elapsed_one(): void
    {
        $price = $this->price(['amount' => 4000, 'compare_at_amount' => 5000]);
        Price::query()->whereKey($price->id)->update(['compare_at_until' => now()->subHour()]);
        $price->refresh();

        $price->update(['compare_at_amount' => null]);

        $this->assertNull($price->fresh()->compare_at_until);
        $this->assertNull($price->fresh()->compare_at_amount);
    }

    #[Test]
    public function an_end_date_without_a_strikethrough_is_dropped_on_create(): void
    {
        $price = $this->price(['amount' => 4000, 'compare_at_amount' => null, 'compare_at_until' => now()->addWeek()]);

        $this->assertNull($price->fresh()->compare_at_until);
    }

    #[Test]
    public function a_strikethrough_without_an_end_date_is_allowed(): void
    {
        $price = $this->price(['amount' => 4000, 'compare_at_amount' => 5000, 'compare_at_until' => null]);

        $this->assertSame(5000, $price->fresh()->compare_at_amount);
        $this->assertNull($price->fresh()->compare_at_until);
    }

    #[Test]
    public function setting_an_end_date_in_the_past_is_refused_and_writes_nothing(): void
    {
        $priceable = TestPriceable::factory()->create();

        try {
            $priceable->prices()->create([
                'currency' => Currency::EUR,
                'amount' => 4000,
                'compare_at_amount' => 5000,
                'compare_at_until' => now()->subMinute(),
                'minimum_quantity' => 1,
            ]);
            $this->fail('A past strikethrough end date was accepted.');
        } catch (InvalidCompareAtPriceException) {
        }

        $this->assertSame(0, Price::query()->count());
    }

    /**
     * The sweep runs hourly, so a strikethrough can sit past its end date for
     * a while. Saving the row for something else in that hour must work.
     */
    #[Test]
    public function an_untouched_elapsed_end_date_does_not_block_an_unrelated_change(): void
    {
        $price = $this->price(['amount' => 4000, 'compare_at_amount' => 5000]);
        Price::query()->whereKey($price->id)->update(['compare_at_until' => now()->subMinute()]);
        $price->refresh();

        $price->update(['minimum_quantity' => 2]);

        $this->assertSame(2, $price->fresh()->minimum_quantity);
        $this->assertNotNull($price->fresh()->compare_at_until);
    }

    /** The admin's picker drops seconds, so an untouched elapsed date comes back rewritten. */
    #[Test]
    public function an_elapsed_end_date_rewritten_without_its_seconds_still_saves(): void
    {
        $price = $this->price(['amount' => 4000, 'compare_at_amount' => 5000]);
        Price::query()->whereKey($price->id)->update(['compare_at_until' => now()->subMinutes(10)->setSecond(27)]);
        $price->refresh();

        $price->update(['compare_at_until' => $price->compare_at_until->copy()->setSecond(0), 'minimum_quantity' => 2]);

        $this->assertSame(2, $price->fresh()->minimum_quantity);
    }

    #[Test]
    public function moving_an_end_date_into_the_past_is_refused(): void
    {
        $price = $this->price(['amount' => 4000, 'compare_at_amount' => 5000, 'compare_at_until' => now()->addWeek()]);

        $this->expectException(InvalidCompareAtPriceException::class);

        $price->update(['compare_at_until' => now()->subDay()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function price(array $attributes): Price
    {
        return TestPriceable::factory()->create()->prices()->create(array_merge([
            'currency' => Currency::EUR,
            'minimum_quantity' => 1,
        ], $attributes));
    }
}
