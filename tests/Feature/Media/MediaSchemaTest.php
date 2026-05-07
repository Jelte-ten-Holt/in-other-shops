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

    #[Test]
    public function it_persists_is_cover_when_form_data_marks_a_row(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('a.jpeg', 100, 'image/jpeg')->storeAs('media', 'a.jpeg', 'public');
        UploadedFile::fake()->create('b.jpeg', 100, 'image/jpeg')->storeAs('media', 'b.jpeg', 'public');

        $data = [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/a.jpeg',
                        'alt' => null,
                        'is_cover' => false,
                    ],
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/b.jpeg',
                        'alt' => null,
                        'is_cover' => true,
                    ],
                ],
            ],
        ];

        MediaSchema::saveFormData($record, $data);

        $cover = $record->coverImage();
        $this->assertNotNull($cover);
        $this->assertSame('media/b.jpeg', $cover->path);
    }

    #[Test]
    public function it_keeps_only_one_is_cover_when_form_data_marks_multiple_rows(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('a.jpeg', 100, 'image/jpeg')->storeAs('media', 'a.jpeg', 'public');
        UploadedFile::fake()->create('b.jpeg', 100, 'image/jpeg')->storeAs('media', 'b.jpeg', 'public');
        UploadedFile::fake()->create('c.pdf', 100, 'application/pdf')->storeAs('media', 'c.pdf', 'public');

        $data = [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/a.jpeg',
                        'alt' => null,
                        'is_cover' => true,
                    ],
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/b.jpeg',
                        'alt' => null,
                        'is_cover' => true,
                    ],
                ],
                'documents' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/c.pdf',
                        'alt' => null,
                        'is_cover' => true,
                    ],
                ],
            ],
        ];

        MediaSchema::saveFormData($record, $data);

        $covers = $record->media()->wherePivot('is_cover', true)->get();
        $this->assertCount(1, $covers);
        $this->assertSame('media/a.jpeg', $covers->first()->path);
    }

    #[Test]
    public function it_clears_a_previous_cover_when_form_data_unmarks_the_row(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('a.jpeg', 100, 'image/jpeg')->storeAs('media', 'a.jpeg', 'public');

        // First save: row is cover.
        MediaSchema::saveFormData($record, [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/a.jpeg',
                        'alt' => null,
                        'is_cover' => true,
                    ],
                ],
            ],
        ]);

        $persisted = $record->media()->wherePivot('collection', 'images')->first();
        $this->assertTrue((bool) $persisted->pivot->is_cover);

        // Second save: same row, is_cover toggled off.
        MediaSchema::saveFormData($record, [
            '_media' => [
                'images' => [
                    [
                        'media_id' => $persisted->id,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/a.jpeg',
                        'alt' => null,
                        'is_cover' => false,
                    ],
                ],
            ],
        ]);

        $reloaded = $record->media()->wherePivot('collection', 'images')->first();
        $this->assertFalse((bool) $reloaded->pivot->is_cover);
    }

    #[Test]
    public function it_includes_is_cover_when_filling_form_data(): void
    {
        $record = TestMediable::factory()->create();

        UploadedFile::fake()->create('a.jpeg', 100, 'image/jpeg')->storeAs('media', 'a.jpeg', 'public');

        MediaSchema::saveFormData($record, [
            '_media' => [
                'images' => [
                    [
                        'media_id' => null,
                        'type' => MediaType::Upload->value,
                        'path' => 'media/a.jpeg',
                        'alt' => null,
                        'is_cover' => true,
                    ],
                ],
            ],
        ]);

        $filled = MediaSchema::fillFormData($record, []);

        $this->assertSame('media/a.jpeg', $filled['_media']['images'][0]['path']);
        $this->assertTrue($filled['_media']['images'][0]['is_cover']);
    }
}
