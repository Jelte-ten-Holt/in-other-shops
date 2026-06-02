<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Variants\Actions\CreateVariant;
use InOtherShops\Variants\Events\VariantCreated;
use InOtherShops\Variants\Exceptions\InvalidVariantOptionsException;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreateVariantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_variant_and_attaches_its_values(): void
    {
        $owner = TestVariantable::factory()->create();
        $size = $this->declaredOption($owner);
        $medium = OptionValue::factory()->for($size)->create();

        $variant = app(CreateVariant::class)($owner, [$medium->id], sku: 'RING-M');

        $this->assertSame('RING-M', $variant->sku);
        $this->assertTrue($variant->variantable->is($owner));
        $this->assertEqualsCanonicalizing([$medium->id], $variant->optionValues->modelKeys());
    }

    #[Test]
    public function it_copies_the_owners_prices_as_a_template(): void
    {
        $owner = TestVariantable::factory()->create();
        $owner->prices()->create(['amount' => 4200, 'currency' => 'EUR', 'minimum_quantity' => 1]);

        $variant = app(CreateVariant::class)($owner);

        $this->assertSame(4200, $variant->priceFor(Currency::EUR)?->amount);
    }

    #[Test]
    public function it_dispatches_variant_created(): void
    {
        Event::fake([VariantCreated::class]);
        $owner = TestVariantable::factory()->create();

        $variant = app(CreateVariant::class)($owner);

        Event::assertDispatched(VariantCreated::class, fn (VariantCreated $event): bool => $event->variant->is($variant));
    }

    #[Test]
    public function it_rejects_two_values_from_the_same_option(): void
    {
        $owner = TestVariantable::factory()->create();
        $size = $this->declaredOption($owner);
        $small = OptionValue::factory()->for($size)->create();
        $medium = OptionValue::factory()->for($size)->create();

        $this->expectException(InvalidVariantOptionsException::class);

        app(CreateVariant::class)($owner, [$small->id, $medium->id]);
    }

    #[Test]
    public function it_rejects_a_value_whose_option_is_not_declared_on_the_owner(): void
    {
        $owner = TestVariantable::factory()->create();
        $undeclared = Option::factory()->create();
        $value = OptionValue::factory()->for($undeclared)->create();

        $this->expectException(InvalidVariantOptionsException::class);

        app(CreateVariant::class)($owner, [$value->id]);
    }

    private function declaredOption(TestVariantable $owner): Option
    {
        $option = Option::factory()->create();
        $owner->options()->attach($option->id, ['position' => 0]);

        return $option;
    }
}
