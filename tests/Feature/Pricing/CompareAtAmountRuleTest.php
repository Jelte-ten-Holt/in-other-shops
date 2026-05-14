<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use InOtherShops\Pricing\Filament\PricingSchema;
use InOtherShops\Pricing\Models\Price;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guard B — the Omnibus speed-bump, exposed as a standalone closure so it is
 * testable without rendering Filament. A strikethrough may only reference a
 * price the item was actually sold at: blocked outright on create (no price
 * history), and on edit it may not exceed the price already on record.
 *
 * Field state arrives in major units (the form shows euros/pounds); the rule
 * normalises to cents before comparing against the stored `amount`.
 */
final class CompareAtAmountRuleTest extends TestCase
{
    #[Test]
    public function it_blocks_any_strikethrough_on_create(): void
    {
        $this->assertNotNull(
            $this->failure(record: null, value: '20.00'),
            'A brand-new price has no prior price the item was sold at.',
        );
    }

    #[Test]
    public function it_allows_an_empty_strikethrough_on_create(): void
    {
        $this->assertNull($this->failure(record: null, value: null));
        $this->assertNull($this->failure(record: null, value: ''));
    }

    #[Test]
    public function it_rejects_a_strikethrough_above_the_recorded_price_on_edit(): void
    {
        $price = new Price(['amount' => 4000]);

        $this->assertNotNull(
            $this->failure(record: $price, value: '50.00'),
            '5000 cents is above the 4000-cent price on record.',
        );
    }

    #[Test]
    public function it_allows_a_strikethrough_at_or_below_the_recorded_price_on_edit(): void
    {
        $price = new Price(['amount' => 5000]);

        // The legitimate flow: was sold at €50, now dropping the price and
        // showing €50 struck through.
        $this->assertNull($this->failure(record: $price, value: '50.00'));
        $this->assertNull($this->failure(record: $price, value: '40.00'));
    }

    /**
     * Runs the rule and returns the failure message, or null if it passed.
     */
    private function failure(?Price $record, mixed $value): ?string
    {
        $message = null;

        $rule = PricingSchema::compareAtAmountRule($record);
        $rule('compare_at_amount', $value, function (string $failure) use (&$message): void {
            $message = $failure;
        });

        return $message;
    }
}
