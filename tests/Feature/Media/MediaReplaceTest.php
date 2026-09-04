<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Filament\MediaSchema;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\Stubs\TestMediable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Swapping the file on an existing media row.
 *
 * Until v0.68.0 this was a silent no-op on the repeater surface:
 * `updateExistingMedia` refreshed the url, the translations and the pivot, but
 * never read `$item['path']`. `media_id` survives in a Hidden field, so the row
 * kept pointing at the replaced file — the new upload was orphaned on disk and
 * the site went on serving the old image with no error anywhere. On the
 * relation-manager surface the path did land, but `filename`/`mime_type`/`size`
 * went on describing the old file and the old file stayed on disk.
 *
 * The invariant now lives on the model, so both surfaces — and any future one —
 * inherit it.
 */
final class MediaReplaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public']);
        Storage::fake('public');
    }

    #[Test]
    public function replacing_the_file_on_a_repeater_row_repoints_the_row(): void
    {
        $record = TestMediable::factory()->create();
        $this->putFile('media/old.jpeg', 1200, 'image/jpeg');
        $this->putFile('media/new.png', 3400, 'image/png');

        $this->save($record, [$this->item(path: 'media/old.jpeg', alt: 'Hero')]);

        $media = Media::sole();

        $this->save($record, [$this->item(
            path: ['livewire-id' => 'media/new.png'],
            alt: 'Hero',
            mediaId: $media->getKey(),
        )]);

        $media->refresh();

        $this->assertSame(1, Media::count());
        $this->assertSame('media/new.png', $media->path);
        $this->assertSame('new.png', $media->filename);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(3400, $media->size);
        $this->assertTrue(Storage::disk('public')->exists('media/new.png'));
        $this->assertFalse(
            Storage::disk('public')->exists('media/old.jpeg'),
            'the replaced file was left behind on disk',
        );
    }

    #[Test]
    public function replacing_a_file_another_row_still_points_at_keeps_it(): void
    {
        $record = TestMediable::factory()->create();
        $this->putFile('media/shared.jpeg', 1200, 'image/jpeg');
        $this->putFile('media/new.jpeg', 900, 'image/jpeg');

        $sharing = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/shared.jpeg',
            'filename' => 'shared.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 1,
        ]);

        $this->save($record, [$this->item(path: 'media/shared.jpeg')]);
        $media = Media::whereKeyNot($sharing->getKey())->sole();

        $this->save($record, [$this->item(path: 'media/new.jpeg', mediaId: $media->getKey())]);

        $this->assertSame('media/new.jpeg', $media->refresh()->path);
        $this->assertTrue(
            Storage::disk('public')->exists('media/shared.jpeg'),
            'a file another row still references was deleted',
        );
    }

    #[Test]
    public function the_invariant_holds_on_a_bare_model_update(): void
    {
        $this->putFile('media/old.jpeg', 1200, 'image/jpeg');
        $this->putFile('media/new.png', 3400, 'image/png');

        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/old.jpeg',
            'filename' => 'old.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 1,
        ]);

        $media->update(['path' => 'media/new.png']);

        $this->assertSame('new.png', $media->filename);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(3400, $media->size);
        $this->assertFalse(Storage::disk('public')->exists('media/old.jpeg'));
    }

    /**
     * `StoreMedia` records the *client's* original filename, which the stored
     * path's generated basename would destroy. The metadata refresh is an
     * update-only rule for that reason.
     */
    #[Test]
    public function creating_a_row_leaves_the_supplied_metadata_alone(): void
    {
        $this->putFile('media/01KQ-generated.jpeg', 1200, 'image/jpeg');

        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/01KQ-generated.jpeg',
            'filename' => 'Holiday Photo.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 12,
        ]);

        $this->assertSame('Holiday Photo.jpeg', $media->refresh()->filename);
        $this->assertSame(12, $media->size);
    }

    /**
     * bianka's panel runs `->databaseTransactions()`, so a save that throws
     * after the row is written rolls the row back. Deleting the old file before
     * the commit would leave the restored row pointing at nothing.
     */
    #[Test]
    public function a_rolled_back_replacement_keeps_the_old_file(): void
    {
        $this->putFile('media/old.jpeg', 1200, 'image/jpeg');
        $this->putFile('media/new.jpeg', 900, 'image/jpeg');

        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/old.jpeg',
            'filename' => 'old.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 1,
        ]);

        try {
            DB::transaction(function () use ($media): void {
                $media->update(['path' => 'media/new.jpeg']);

                throw new RuntimeException('save failed after the media row was written');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('media/old.jpeg', $media->fresh()->path);
        $this->assertTrue(
            Storage::disk('public')->exists('media/old.jpeg'),
            'the old file was deleted before the transaction committed',
        );
    }

    #[Test]
    public function a_metadata_only_save_leaves_the_file_alone(): void
    {
        $this->putFile('media/kept.jpeg', 1200, 'image/jpeg');

        $media = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/kept.jpeg',
            'filename' => 'kept.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 1,
        ]);

        $media->update(['alt' => 'A caption']);

        $this->assertTrue(Storage::disk('public')->exists('media/kept.jpeg'));
        $this->assertSame(1, $media->refresh()->size);
    }

    #[Test]
    public function switching_an_upload_row_to_external_replaces_the_row(): void
    {
        $record = TestMediable::factory()->create();
        $this->putFile('media/old.jpeg', 1200, 'image/jpeg');

        $this->save($record, [$this->item(path: 'media/old.jpeg', alt: 'Hero')]);
        $original = Media::sole();

        $this->save($record, [[
            'media_id' => $original->getKey(),
            'type' => MediaType::External->value,
            'url' => 'https://cdn.example.test/hero.jpg',
            'alt' => ['en' => 'Hero'],
            'is_cover' => false,
        ]]);

        $this->assertNull(Media::find($original->getKey()), 'the upload row survived the type switch');
        $this->assertFalse(
            Storage::disk('public')->exists('media/old.jpeg'),
            'the replaced upload left its file behind',
        );

        $replacement = Media::sole();
        $this->assertSame(MediaType::External, $replacement->type);
        $this->assertSame('https://cdn.example.test/hero.jpg', $replacement->getAttribute('url'));

        $attached = $record->media()->wherePivot('collection', 'images')->get();
        $this->assertCount(1, $attached);
        $this->assertSame($replacement->getKey(), $attached->first()->getKey());
        $this->assertSame(0, $attached->first()->pivot->position);
    }

    #[Test]
    public function switching_an_external_row_to_upload_replaces_the_row(): void
    {
        $record = TestMediable::factory()->create();
        $this->putFile('media/new.jpeg', 20, 'image/jpeg');

        $this->save($record, [[
            'media_id' => null,
            'type' => MediaType::External->value,
            'url' => 'https://cdn.example.test/hero.jpg',
            'is_cover' => false,
        ]]);
        $original = Media::sole();

        $this->save($record, [$this->item(path: 'media/new.jpeg', mediaId: $original->getKey())]);

        $this->assertNull(Media::find($original->getKey()));

        $replacement = Media::sole();
        $this->assertSame(MediaType::Upload, $replacement->type);
        $this->assertSame('media/new.jpeg', $replacement->path);
        $this->assertTrue(Storage::disk('public')->exists('media/new.jpeg'));
    }

    /**
     * A type switch keeps the row's place in the collection — the editor moved
     * one slot from an upload to an embed, not to the end of the list.
     */
    #[Test]
    public function a_type_switch_keeps_the_rows_position_and_cover_flag(): void
    {
        $record = TestMediable::factory()->create();
        $this->putFile('media/a.jpeg', 800, 'image/jpeg');
        $this->putFile('media/b.jpeg', 800, 'image/jpeg');

        $this->save($record, [
            $this->item(path: 'media/a.jpeg'),
            $this->item(path: 'media/b.jpeg'),
        ]);

        $second = $record->media()->wherePivot('position', 1)->sole();

        $this->save($record, [
            $this->item(path: 'media/a.jpeg', mediaId: $record->media()->wherePivot('position', 0)->sole()->getKey()),
            [
                'media_id' => $second->getKey(),
                'type' => MediaType::External->value,
                'url' => 'https://cdn.example.test/second.jpg',
                'is_cover' => true,
            ],
        ]);

        $replacement = $record->media()->wherePivot('position', 1)->sole();

        $this->assertSame(MediaType::External, $replacement->type);
        $this->assertTrue((bool) $replacement->pivot->is_cover);
        $this->assertSame(2, $record->media()->count());
    }

    /**
     * Real bytes, not `UploadedFile::fake()->create()` — that writes an *empty*
     * file and only reports a size, so every `size` assertion against the disk
     * would be 0 === 0 and could not tell a refresh from a no-op.
     */
    private function putFile(string $path, int $bytes, string $mime): void
    {
        $magic = match ($mime) {
            'image/png' => "\x89PNG\r\n\x1a\n",
            default => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00",
        };

        Storage::disk('public')->put($path, $magic.str_repeat("\0", max(0, $bytes - strlen($magic))));
    }

    /**
     * @param  string|array<string, string>  $path
     * @return array<string, mixed>
     */
    private function item(string|array $path, ?string $alt = null, ?int $mediaId = null): array
    {
        return [
            'media_id' => $mediaId,
            'type' => MediaType::Upload->value,
            'path' => $path,
            'alt' => $alt === null ? [] : ['en' => $alt],
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
