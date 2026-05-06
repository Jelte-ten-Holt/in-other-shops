<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\TestMediable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

final class MediaSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public']);
        Storage::fake('public');
    }

    #[Test]
    public function it_creates_a_media_row_when_filament_emits_a_keyed_array_path(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('hero.jpeg', 100, 'image/jpeg')->storeAs('media', '01KQYB-real.jpeg', 'public');

        $data = [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => ['ulid-livewire-id' => 'media/01KQYB-real.jpeg'],
                        'alt' => 'A hero image',
                    ],
                ],
            ],
        ];

        MediaSchema::saveFormData($record, $data);

        $this->assertSame(1, Media::count());

        $media = Media::sole();
        $this->assertSame(MediaType::Upload, $media->type);
        $this->assertSame('public', $media->disk);
        $this->assertSame('media/01KQYB-real.jpeg', $media->path);
        $this->assertSame('01KQYB-real.jpeg', $media->filename);
        $this->assertSame('A hero image', $media->alt);

        $attached = $record->media()->wherePivot('collection', 'images')->get();
        $this->assertCount(1, $attached);
        $this->assertSame($media->id, $attached->first()->id);
    }

    #[Test]
    public function it_creates_a_media_row_when_path_is_a_bare_string(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('cover.png', 100, 'image/png')->storeAs('media', 'bare-string.png', 'public');

        $data = [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/bare-string.png',
                        'alt' => null,
                    ],
                ],
            ],
        ];

        MediaSchema::saveFormData($record, $data);

        $media = Media::sole();
        $this->assertSame('media/bare-string.png', $media->path);
        $this->assertSame('bare-string.png', $media->filename);
    }

    #[Test]
    public function it_attaches_multiple_uploads_with_correct_positions(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('one.jpeg', 100, 'image/jpeg')->storeAs('media', 'first.jpeg', 'public');
        UploadedFile::fake()->create('two.jpeg', 100, 'image/jpeg')->storeAs('media', 'second.jpeg', 'public');

        $data = [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => ['id-a' => 'media/first.jpeg'],
                        'alt' => null,
                    ],
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => ['id-b' => 'media/second.jpeg'],
                        'alt' => null,
                    ],
                ],
            ],
        ];

        MediaSchema::saveFormData($record, $data);

        $attached = $record->media()->wherePivot('collection', 'images')->orderByPivot('position')->get();
        $this->assertCount(2, $attached);
        $this->assertSame('media/first.jpeg', $attached[0]->path);
        $this->assertSame(0, (int) $attached[0]->pivot->position);
        $this->assertSame('media/second.jpeg', $attached[1]->path);
        $this->assertSame(1, (int) $attached[1]->pivot->position);
    }

    #[Test]
    public function it_silently_skips_items_with_an_empty_path(): void
    {
        $record = TestMediable::factory()->create();

        $data = [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => [],
                        'alt' => null,
                    ],
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => null,
                        'alt' => null,
                    ],
                ],
            ],
        ];

        MediaSchema::saveFormData($record, $data);

        $this->assertSame(0, Media::count());
        $this->assertSame(0, $record->media()->count());
    }
}
