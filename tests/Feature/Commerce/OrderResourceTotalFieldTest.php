<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Filament\Forms\Components\TextInput;
use InOtherShops\Commerce\Filament\Resources\OrderResource;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Regression cover for H6 (audit 2026-05-09): the Order.total field in
 * the admin form must be disabled (computed from subtotal + tax +
 * shipping_cost - discount). Editing it directly used to let an admin
 * introduce a discrepancy between total and its parts.
 */
final class OrderResourceTotalFieldTest extends TestCase
{
    #[Test]
    public function total_field_is_disabled(): void
    {
        $field = $this->totalField();

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
        $field = $this->totalField();

        $this->assertNotNull($field);
        $this->assertTrue(
            $field->isDehydrated(),
            'Disabled `total` must still be dehydrated so the recalculated value persists.'
        );
    }

    private function totalField(): ?TextInput
    {
        // orderDetailFields() is protected — reflection is the cheapest way
        // to inspect the field config without booting a Livewire test stack.
        $method = new ReflectionMethod(OrderResource::class, 'orderDetailFields');
        $method->setAccessible(true);
        $components = $method->invoke(null);

        return $this->findTotalField($components);
    }

    private function findTotalField(iterable $components): ?TextInput
    {
        foreach ($components as $component) {
            if ($component instanceof TextInput && $component->getName() === 'total') {
                return $component;
            }
        }

        return null;
    }
}
