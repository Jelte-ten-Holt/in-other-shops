<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Variants\Filament\Resources\OptionResource;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers the OptionResource manual-sync of an option's values (fill/save).
 * Filament rendering is not booted in this package's test layer.
 */
final class OptionResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function its_values_repeater_builds_without_error(): void
    {
        $this->assertNotNull(OptionResource::valuesRepeater());
    }

    #[Test]
    public function save_values_creates_values_with_labels_and_positions(): void
    {
        $option = Option::factory()->create();

        OptionResource::saveValues($option, ['_values' => [
            ['value' => 'silver', 'labels' => ['en' => 'Silver']],
            ['value' => 'gold', 'labels' => ['en' => 'Gold']],
        ]]);

        $values = $option->values()->get();
        $this->assertSame(['silver', 'gold'], $values->pluck('value')->all());
        $this->assertSame([0, 1], $values->pluck('position')->all());
        $this->assertSame('Silver', $values->first()->translated('label', 'en'));
    }

    #[Test]
    public function save_values_updates_existing_values_and_deletes_removed_ones(): void
    {
        $option = Option::factory()->create();
        $silver = OptionValue::factory()->for($option)->create(['value' => 'silver']);
        $gold = OptionValue::factory()->for($option)->create(['value' => 'gold']);

        OptionResource::saveValues($option, ['_values' => [
            ['id' => $silver->id, 'value' => 'sterling-silver', 'labels' => ['en' => 'Sterling Silver']],
        ]]);

        $this->assertSame('sterling-silver', $silver->fresh()->value);
        $this->assertModelMissing($gold);
        $this->assertSame(1, $option->values()->count());
    }

    #[Test]
    public function fill_values_loads_values_with_labels_into_repeater_state(): void
    {
        $option = Option::factory()->create();
        $value = OptionValue::factory()->for($option)->create(['value' => 'silver', 'position' => 0]);
        $value->setTranslation('label', 'en', 'Silver');

        $data = OptionResource::fillValues($option, []);

        $this->assertSame('silver', $data['_values'][0]['value']);
        $this->assertSame('Silver', $data['_values'][0]['labels']['en']);
    }
}
