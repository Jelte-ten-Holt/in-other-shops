<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Filament\Forms\Components\TextInput;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Regression cover for H6 (audit 2026-05-09) and the v0.60 recalc fix: the
 * admin form's Order.total is computed via the PriceBreakdown identity
 * `subtotal − discount + shipping_cost` (inclusive mode: tax already lives
 * INSIDE subtotal) and must stay read-only.
 *
 * The arithmetic is pinned directly on computeTotal() — the suite has no
 * Livewire panel harness, so the end-to-end form save is the consumers'
 * admin suites' to cover. The pre-v0.60 formula (`subtotal + tax − discount`)
 * double-counted VAT and dropped shipping, and the shipping input was bound
 * to a phantom `_shipping_cost` attribute that matched no column, so the
 * shipping term was always 0.00.
 */
final class OrderResourceTotalFieldTest extends TestCase
{
    #[Test]
    public function the_total_follows_the_breakdown_identity_not_the_vat_double_count(): void
    {
        // Discounted + shipped: 50.00 − 10.00 + 5.95. The old formula with
        // tax 7.98 would have said 47.98.
        $this->assertSame('45.95', OrderResource::computeTotal(50.00, 10.00, 5.95));
    }

    #[Test]
    public function tax_never_enters_the_total(): void
    {
        // Inclusive mode: VAT is contained in the subtotal. There is no tax
        // parameter to pass — the signature itself pins the fix.
        $this->assertSame('50.00', OrderResource::computeTotal(50.00, 0.0, 0.0));
        $this->assertSame('44.05', OrderResource::computeTotal(50.00, 5.95, 0.0));
        $this->assertSame('55.95', OrderResource::computeTotal(50.00, 0.0, 5.95));
    }

    #[Test]
    public function total_field_is_disabled(): void
    {
        $field = $this->field('total');

        $this->assertNotNull($field, 'OrderResource form must include a `total` TextInput.');
        $this->assertTrue(
            $field->isDisabled(),
            'OrderResource `total` field must be disabled — see H6 / docs/launch-blockers.md.'
        );
    }

    #[Test]
    public function total_field_is_still_dehydrated(): void
    {
        // disabled() alone strips the field from dehydrated state by default.
        // We need ->dehydrated() so the recalculated total still saves.
        $field = $this->field('total');

        $this->assertNotNull($field);
        $this->assertTrue(
            $field->isDehydrated(),
            'Disabled `total` must still be dehydrated so the recalculated value persists.'
        );
    }

    #[Test]
    public function the_shipping_input_is_bound_to_the_real_column_and_recalculates(): void
    {
        $this->assertNull(
            $this->field('_shipping_cost'),
            'The phantom `_shipping_cost` binding is gone — it matched no column, so the shipping term was always 0.',
        );

        $field = $this->field('shipping_cost');

        $this->assertNotNull($field, 'The shipping input must bind to the `shipping_cost` column.');
        $this->assertTrue(
            $field->isLive(),
            '`shipping_cost` must be live so editing it recalculates the total.',
        );
    }

    private function field(string $name): ?TextInput
    {
        // orderDetailFields() is protected — reflection is the cheapest way
        // to inspect the field config without booting a Livewire test stack.
        $method = new ReflectionMethod(OrderResource::class, 'orderDetailFields');
        $method->setAccessible(true);

        foreach ($method->invoke(null) as $component) {
            if ($component instanceof TextInput && $component->getName() === $name) {
                return $component;
            }
        }

        return null;
    }
}
