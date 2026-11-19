<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Filament\Schemas\Components\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\TestMediable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `mediables.is_listed`: whether a row appears in its host's own list of the
 * collection, or only where the host's text places it. Only a collection
 * configured with `listed_toggle => true` offers the switch.
 */
final class MediaSchemaListedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public']);
        config(['media.collections.documents' => ['label' => 'Documents', 'cover' => false, 'listed_toggle' => true]]);
        Storage::fake('public');
    }

    #[Test]
    public function a_row_attached_without_the_flag_is_listed(): void
    {
        // The column default is what keeps every row that existed before the
        // migration showing where it showed.
        $record = TestMediable::factory()->create();
        $media = Media::factory()->create();

        $record->media()->attach($media->id, ['collection' => 'images', 'position' => 0]);

        $this->assertTrue($record->media()->first()->pivot->is_listed);
    }

    #[Test]
    public function collection_has_listed_toggle_is_off_unless_the_config_says_true(): void
    {
        config(['media.collections.embed' => ['label' => 'Embed', 'listed_toggle' => 'yes']]);

        $this->assertTrue(MediaSchema::collectionHasListedToggle('documents'));
        $this->assertFalse(MediaSchema::collectionHasListedToggle('images'));
        $this->assertFalse(MediaSchema::collectionHasListedToggle('embed'));
    }

    #[Test]
    public function the_repeater_offers_the_listed_toggle_only_where_the_collection_opts_in(): void
    {
        $this->assertContains('is_listed', $this->fieldNames('documents'));
        $this->assertNotContains('is_listed', $this->fieldNames('images'));
    }

    #[Test]
    public function it_saves_a_row_unlisted_when_its_collection_offers_the_toggle(): void
    {
        $record = TestMediable::factory()->create();

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [
            $this->upload('off.pdf', ['is_listed' => false]),
            $this->upload('on.pdf', ['is_listed' => true]),
            $this->upload('unset.pdf'),
        ]]]);

        $this->assertSame(
            ['media/off.pdf' => false, 'media/on.pdf' => true, 'media/unset.pdf' => true],
            $this->listedByPath($record),
        );
    }

    #[Test]
    public function it_keeps_a_row_listed_when_its_collection_offers_no_toggle(): void
    {
        $record = TestMediable::factory()->create();

        MediaSchema::saveFormData($record, ['_media' => ['images' => [
            $this->upload('a.jpeg', ['is_listed' => false]),
        ]]]);

        $this->assertSame(['media/a.jpeg' => true], $this->listedByPath($record));
    }

    #[Test]
    public function toggling_an_existing_row_updates_its_pivot(): void
    {
        $record = TestMediable::factory()->create();

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [$this->upload('a.pdf')]]]);
        $mediaId = $record->media()->first()->id;

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [
            ['media_id' => $mediaId, 'type' => MediaType::Upload->value, 'path' => 'media/a.pdf', 'is_listed' => false],
        ]]]);
        $this->assertSame(['media/a.pdf' => false], $this->listedByPath($record));

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [
            ['media_id' => $mediaId, 'type' => MediaType::Upload->value, 'path' => 'media/a.pdf', 'is_listed' => true],
        ]]]);
        $this->assertSame(['media/a.pdf' => true], $this->listedByPath($record));
    }

    #[Test]
    public function changing_a_rows_type_keeps_it_unlisted(): void
    {
        // A type change deletes the row and creates a new one in its place;
        // the new row must carry the flag the form holds, not the default.
        $record = TestMediable::factory()->create();

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [$this->upload('a.pdf', ['is_listed' => false])]]]);
        $mediaId = $record->media()->first()->id;

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [
            ['media_id' => $mediaId, 'type' => MediaType::External->value, 'url' => 'https://example.test/a.pdf', 'is_listed' => false],
        ]]]);

        $row = $record->media()->first();
        $this->assertNotSame($mediaId, $row->id);
        $this->assertFalse($row->pivot->is_listed);
    }

    #[Test]
    public function it_includes_is_listed_when_filling_form_data(): void
    {
        $record = TestMediable::factory()->create();

        MediaSchema::saveFormData($record, ['_media' => ['documents' => [
            $this->upload('off.pdf', ['is_listed' => false]),
            $this->upload('on.pdf'),
        ]]]);

        $filled = MediaSchema::fillFormData($record, []);

        $this->assertSame([false, true], array_column($filled['_media']['documents'], 'is_listed'));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function upload(string $name, array $extra = []): array
    {
        $mime = str_ends_with($name, '.pdf') ? 'application/pdf' : 'image/jpeg';
        UploadedFile::fake()->create($name, 100, $mime)->storeAs('media', $name, 'public');

        return ['media_id' => null, 'type' => MediaType::Upload->value, 'path' => "media/{$name}", ...$extra];
    }

    /** @return array<string, bool> */
    private function listedByPath(TestMediable $record): array
    {
        return $record->media()->get()
            ->mapWithKeys(fn (Media $media): array => [$media->path ?? $media->getAttribute('url') => $media->pivot->is_listed])
            ->all();
    }

    /** @return list<string|null> */
    private function fieldNames(string $collection): array
    {
        return array_map(
            fn (Component $component): ?string => method_exists($component, 'getName') ? $component->getName() : null,
            MediaSchema::mediaRepeater($collection)->getDefaultChildComponents(),
        );
    }
}
