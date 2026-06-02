<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Variants\Actions\GenerateVariants;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Tests\Stubs\TestVariantable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class GenerateVariantsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_the_cartesian_product_of_the_selected_values(): void
    {
        $owner = TestVariantable::factory()->create();
        [$metal, $size] = $this->twoOptions();
        $metalValues = OptionValue::factory()->for($metal)->count(2)->create();
        $sizeValues = OptionValue::factory()->for($size)->count(3)->create();

        $created = app(GenerateVariants::class)($owner, [
            $metal->id => $metalValues->modelKeys(),
            $size->id => $sizeValues->modelKeys(),
        ]);

        $this->assertCount(6, $created);
        $this->assertSame(6, $owner->variants()->count());
        // Each variant carries one metal + one size value.
        $created->each(fn ($variant) => $this->assertCount(2, $variant->optionValues));
    }

    #[Test]
    public function it_declares_the_axes_on_the_owner(): void
    {
        $owner = TestVariantable::factory()->create();
        [$metal, $size] = $this->twoOptions();

        app(GenerateVariants::class)($owner, [
            $metal->id => [OptionValue::factory()->for($metal)->create()->id],
            $size->id => [OptionValue::factory()->for($size)->create()->id],
        ]);

        $this->assertEqualsCanonicalizing(
            [$metal->id, $size->id],
            $owner->options()->pluck('options.id')->all(),
        );
    }

    #[Test]
    public function it_skips_combinations_that_already_exist_on_re_run(): void
    {
        $owner = TestVariantable::factory()->create();
        $metal = Option::factory()->create();
        $silver = OptionValue::factory()->for($metal)->create();
        $gold = OptionValue::factory()->for($metal)->create();

        app(GenerateVariants::class)($owner, [$metal->id => [$silver->id]]);
        $second = app(GenerateVariants::class)($owner, [$metal->id => [$silver->id, $gold->id]]);

        // Only the gold combination is new the second time.
        $this->assertCount(1, $second);
        $this->assertSame(2, $owner->variants()->count());
    }

    #[Test]
    public function it_copies_the_price_template_to_each_generated_variant(): void
    {
        $owner = TestVariantable::factory()->create();
        $owner->prices()->create(['amount' => 1500, 'currency' => 'EUR', 'minimum_quantity' => 1]);
        $metal = Option::factory()->create();
        $values = OptionValue::factory()->for($metal)->count(2)->create();

        $created = app(GenerateVariants::class)($owner, [$metal->id => $values->modelKeys()]);

        $created->each(fn ($variant) => $this->assertSame(1500, $variant->priceFor(Currency::EUR)?->amount));
    }

    /** @return array{0: Option, 1: Option} */
    private function twoOptions(): array
    {
        return [Option::factory()->create(), Option::factory()->create()];
    }
}
