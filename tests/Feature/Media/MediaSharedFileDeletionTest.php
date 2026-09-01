<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `Media::deleting` removes an upload's file from disk — unless another row
 * still points at it. `MediaSchema` creates a replacement row before it
 * removes an orphan it could not match, so without this guard the file the
 * replacement references is gone the moment the orphan is.
 */
final class MediaSharedFileDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        UploadedFile::fake()->create('shared.jpeg', 10, 'image/jpeg')->storeAs('media', 'shared.jpeg', 'public');
    }

    #[Test]
    public function deleting_a_row_whose_file_another_row_references_keeps_the_file(): void
    {
        $orphan = $this->upload('media/shared.jpeg');
        $replacement = $this->upload('media/shared.jpeg');

        $orphan->delete();

        $this->assertTrue(Storage::disk('public')->exists('media/shared.jpeg'));
        $this->assertNull(Media::find($orphan->getKey()));
        $this->assertNotNull(Media::find($replacement->getKey()));
    }

    #[Test]
    public function deleting_the_last_row_referencing_a_file_removes_it(): void
    {
        $orphan = $this->upload('media/shared.jpeg');
        $replacement = $this->upload('media/shared.jpeg');

        $orphan->delete();
        $replacement->delete();

        $this->assertFalse(Storage::disk('public')->exists('media/shared.jpeg'));
    }

    #[Test]
    public function a_row_on_another_disk_with_the_same_path_does_not_count_as_sharing(): void
    {
        $orphan = $this->upload('media/shared.jpeg');
        $this->upload('media/shared.jpeg', disk: 'other');

        $orphan->delete();

        $this->assertFalse(Storage::disk('public')->exists('media/shared.jpeg'));
    }

    private function upload(string $path, string $disk = 'public'): Media
    {
        return Media::create([
            'type' => MediaType::Upload,
            'disk' => $disk,
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => 'image/jpeg',
            'size' => 10,
        ]);
    }
}
