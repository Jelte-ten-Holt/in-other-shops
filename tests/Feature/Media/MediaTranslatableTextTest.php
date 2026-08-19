<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class MediaTranslatableTextTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function alt_and_description_are_not_columns(): void
    {
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('media', 'alt'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('media', 'description'));
    }

    #[Test]
    public function assigning_on_create_lands_in_the_default_locale(): void
    {
        config(['translation.default' => 'en']);

        $media = Media::factory()->create(['alt' => 'A hero image', 'description' => 'Ink and wash.']);

        // The write path has to survive the id not existing yet: a translation
        // row cannot be written until the insert completes.
        $this->assertSame('A hero image', $media->fresh()->alt);
        $this->assertSame('Ink and wash.', $media->fresh()->description);

        $this->assertDatabaseHas('translations', [
            'translatable_type' => 'media',
            'translatable_id' => $media->id,
            'locale' => 'en',
            'field' => 'alt',
            'value' => 'A hero image',
        ]);
    }

    #[Test]
    public function an_assigned_value_reads_back_before_the_row_is_saved(): void
    {
        $media = new Media;
        $media->alt = 'Not yet persisted';

        $this->assertSame('Not yet persisted', $media->alt);
    }

    #[Test]
    public function each_locale_resolves_its_own_value(): void
    {
        config(['translation.locales' => ['es', 'en'], 'translation.default' => 'es', 'translation.fallback' => 'es']);

        $media = Media::factory()->create(['alt' => null]);
        $media->setTranslation('description', 'es', 'Plata de ley.');
        $media->setTranslation('description', 'en', 'Sterling silver.');

        app()->setLocale('es');
        $this->assertSame('Plata de ley.', $media->fresh()->description);

        app()->setLocale('en');
        $this->assertSame('Sterling silver.', $media->fresh()->description);
    }

    #[Test]
    public function a_missing_locale_falls_back_rather_than_rendering_blank(): void
    {
        config(['translation.locales' => ['es', 'en'], 'translation.default' => 'es', 'translation.fallback' => 'es']);

        $media = Media::factory()->create(['alt' => null]);
        $media->setTranslation('description', 'es', 'Plata de ley.');

        // An untranslated caption shows the shop's own language, not nothing —
        // a blank space under a photo reads as a layout bug.
        app()->setLocale('en');
        $this->assertSame('Plata de ley.', $media->fresh()->description);
    }

    #[Test]
    public function both_fields_survive_serialization(): void
    {
        config(['translation.default' => 'en']);

        $media = Media::factory()->create(['alt' => 'Alt text', 'description' => 'A caption.']);

        // Consumers hand Media models straight to Inertia. If these dropped out
        // of toArray() every gallery would silently lose its alt attribute.
        $array = $media->fresh()->toArray();

        $this->assertArrayHasKey('alt', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertSame('Alt text', $array['alt']);
        $this->assertSame('A caption.', $array['description']);
    }

    #[Test]
    public function a_null_assignment_clears_the_stored_value(): void
    {
        config(['translation.default' => 'en']);

        $media = Media::factory()->create(['alt' => 'Alt text']);

        $media->alt = null;
        $media->save();

        $this->assertNull($media->fresh()->alt);
        $this->assertDatabaseMissing('translations', [
            'translatable_type' => 'media',
            'translatable_id' => $media->id,
            'field' => 'alt',
        ]);
    }

    #[Test]
    public function deleting_media_takes_its_translations_with_it(): void
    {
        $media = Media::factory()->create(['alt' => 'Alt text', 'description' => 'A caption.']);
        $id = $media->id;

        $media->delete();

        // Orphaned translation rows would be re-adopted by whatever media row
        // next takes this id.
        $this->assertDatabaseMissing('translations', [
            'translatable_type' => 'media',
            'translatable_id' => $id,
        ]);
    }

    #[Test]
    public function reading_a_collection_does_not_query_per_row(): void
    {
        Media::factory()->count(5)->create();

        DB::enableQueryLog();
        $all = Media::all();
        foreach ($all as $media) {
            $media->alt;
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // One for the media, one for every translation in the batch. A lazy
        // load per row would put six here, on every gallery and card.
        $this->assertCount(2, $queries);
    }
}
