<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Variants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Contracts\HasMedia;
use InOtherShops\Variants\Filament\Resources\OptionResource;
use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OptionValueSwatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_option_value_is_a_media_owner_with_no_swatch_by_default(): void
    {
        $value = OptionValue::factory()->create();

        $this->assertInstanceOf(HasMedia::class, $value);
        $this->assertNull($value->swatch());
    }

    #[Test]
    public function saving_a_value_with_an_uploaded_swatch_attaches_it(): void
    {
        $option = $this->fakeSwatch('media/silver.png');

        OptionResource::saveValues($option, ['_values' => [
            ['value' => 'silver', 'labels' => ['en' => 'Silver'], 'swatch' => 'media/silver.png'],
        ]]);

        $value = $option->values()->first();
        $this->assertSame('media/silver.png', $value->swatch()?->path);
        $this->assertDatabaseHas('mediables', [
            'mediable_type' => 'option_value',
            'mediable_id' => $value->id,
            'collection' => 'swatch',
        ]);
    }

    #[Test]
    public function replacing_the_swatch_removes_the_previous_media(): void
    {
        $option = $this->fakeSwatch('media/silver.png');
        Storage::disk(config('media.disk'))->put('media/gold.png', 'x');

        OptionResource::saveValues($option, ['_values' => [
            ['value' => 'metal', 'labels' => ['en' => 'Metal'], 'swatch' => 'media/silver.png'],
        ]]);
        $value = $option->values()->first();
        $oldMediaId = $value->swatch()?->id;

        OptionResource::saveValues($option, ['_values' => [
            ['id' => $value->id, 'value' => 'metal', 'labels' => ['en' => 'Metal'], 'swatch' => 'media/gold.png'],
        ]]);

        $this->assertSame('media/gold.png', $value->fresh()->swatch()?->path);
        $this->assertDatabaseMissing('media', ['id' => $oldMediaId]);
    }

    #[Test]
    public function clearing_the_swatch_removes_it(): void
    {
        $option = $this->fakeSwatch('media/silver.png');

        OptionResource::saveValues($option, ['_values' => [
            ['value' => 'silver', 'labels' => ['en' => 'Silver'], 'swatch' => 'media/silver.png'],
        ]]);
        $value = $option->values()->first();

        OptionResource::saveValues($option, ['_values' => [
            ['id' => $value->id, 'value' => 'silver', 'labels' => ['en' => 'Silver'], 'swatch' => null],
        ]]);

        $this->assertNull($value->fresh()->swatch());
    }

    private function fakeSwatch(string $path): Option
    {
        Storage::fake(config('media.disk'));
        Storage::disk(config('media.disk'))->put($path, 'fake-image-bytes');

        return Option::factory()->create();
    }
}
