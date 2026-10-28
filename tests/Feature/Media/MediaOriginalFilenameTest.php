<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\Filament\StubEditableResource\Pages\EditStubEditable;
use InOtherShops\Tests\Stubs\TestEditable;
use InOtherShops\Tests\Stubs\TestMediable;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * `media.filename` is the uploader's name for the file, not the generated one
 * it is stored under. A consumer offering the file as a download saves it
 * under this name, so a reader's copy is "The Parasite.pdf" rather than a ULID.
 */
final class MediaOriginalFilenameTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    private const string PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public']);
        Storage::fake('public');
    }

    /**
     * Through the page, because the name only exists while Filament stores the
     * upload: the schema-level tests below hand `original_filename` in, and
     * would pass with the key misnamed.
     */
    #[Test]
    public function an_upload_through_the_form_records_the_uploaders_filename(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        $record = TestEditable::create(['slug' => 'pictured']);
        $record->setTranslation('name', 'en', 'Pictured');
        $record->save();

        Livewire::test(EditStubEditable::class, ['record' => $record->getKey()])
            ->fillForm(['_media.images' => ['row' => ['type' => MediaType::Upload->value, 'path' => []]]])
            ->set('data._media.images.row.path', UploadedFile::fake()->createWithContent('The Parasite.pdf', self::PDF))
            ->call('save')
            ->assertHasNoFormErrors();

        $media = Media::sole();

        $this->assertSame('The Parasite.pdf', $media->filename);
        $this->assertNotSame('The Parasite.pdf', basename($media->path), 'the file was stored under the client name');
    }

    #[Test]
    public function creating_a_row_records_the_uploaders_filename(): void
    {
        $record = TestMediable::factory()->create();
        Storage::disk('public')->put('media/01KQ-generated.pdf', self::PDF);

        $this->save($record, [$this->item('media/01KQ-generated.pdf', originalFilename: 'The Parasite.pdf')]);

        $this->assertSame('The Parasite.pdf', Media::sole()->filename);
    }

    #[Test]
    public function a_row_without_the_uploaders_filename_takes_the_stored_basename(): void
    {
        $record = TestMediable::factory()->create();
        Storage::disk('public')->put('media/01KQ-generated.pdf', self::PDF);

        $this->save($record, [$this->item('media/01KQ-generated.pdf')]);

        $this->assertSame('01KQ-generated.pdf', Media::sole()->filename);
    }

    /**
     * A corrected file is usually uploaded under the name it already has. Set
     * alongside `path`, that name would not be dirty, and the model's refresh
     * would replace it with the stored basename.
     */
    #[Test]
    public function a_replacement_under_the_same_name_keeps_it(): void
    {
        $record = TestMediable::factory()->create();
        Storage::disk('public')->put('media/01KQ-first.pdf', self::PDF);
        Storage::disk('public')->put('media/01KR-second.pdf', self::PDF.'v2');

        $this->save($record, [$this->item('media/01KQ-first.pdf', originalFilename: 'The Parasite.pdf')]);
        $media = Media::sole();

        $this->save($record, [$this->item(
            ['livewire-id' => 'media/01KR-second.pdf'],
            originalFilename: 'The Parasite.pdf',
            mediaId: $media->getKey(),
        )]);

        $media->refresh();

        $this->assertSame('media/01KR-second.pdf', $media->path);
        $this->assertSame('The Parasite.pdf', $media->filename);
    }

    /**
     * @param  string|array<string, string>  $path
     * @return array<string, mixed>
     */
    private function item(string|array $path, ?string $originalFilename = null, ?int $mediaId = null): array
    {
        return [
            'media_id' => $mediaId,
            'type' => MediaType::Upload->value,
            'path' => $path,
            'original_filename' => $originalFilename,
            'is_cover' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function save(TestMediable $record, array $items): void
    {
        MediaSchema::saveFormData($record, ['_media' => ['images' => $items]]);
        $record->unsetRelation('media');
    }
}
