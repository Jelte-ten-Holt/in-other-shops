<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * D3 + F9: `width`/`height` are read from the file header inside the request,
 * whenever `path` is dirty on an upload row, EXIF-orientation-corrected. A
 * phone photo stores landscape pixels plus a rotation flag; the browser shows
 * it upright, so the row must say what the browser shows or the D4 slider
 * reserves the wrong space.
 */
final class MediaDimensionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public']);
        Storage::fake('public');
        Queue::fake();
    }

    #[Test]
    public function dimensions_are_read_when_an_upload_is_created(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg', 'image/jpeg');

        $this->assertSame(1200, $media->width);
        $this->assertSame(800, $media->height);
    }

    #[Test]
    public function an_exif_orientation_6_photo_stores_portrait_dimensions(): void
    {
        // Stored pixels are 1200×800; the flag says "rotate 90°", so it displays 800×1200.
        $media = $this->upload('portrait-exif6.jpg', 'media/phone.jpg', 'image/jpeg');

        $this->assertSame(800, $media->width);
        $this->assertSame(1200, $media->height);
    }

    #[Test]
    public function documents_and_external_rows_stay_null(): void
    {
        Storage::disk('public')->put('media/manual.pdf', '%PDF-1.4 not really');

        $pdf = Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => 'media/manual.pdf',
            'filename' => 'manual.pdf',
            'mime_type' => 'application/pdf',
            'size' => 19,
        ]);
        $external = Media::factory()->external()->create();

        $this->assertNull($pdf->fresh()->width);
        $this->assertNull($pdf->fresh()->height);
        $this->assertNull($external->fresh()->width);
        $this->assertNull($external->fresh()->height);
    }

    #[Test]
    public function a_missing_file_leaves_dimensions_null_rather_than_failing_the_save(): void
    {
        $media = Media::factory()->create(['path' => 'media/never-uploaded.jpg']);

        $this->assertNull($media->fresh()->width);
    }

    #[Test]
    public function dimensions_refresh_and_variants_reset_when_the_path_changes(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/first.jpg', 'image/jpeg');
        $media->forceFill(['variants' => ['400' => ['path' => 'media/first-w400.webp', 'width' => 400, 'height' => 267]]])->saveQuietly();
        $this->putFixture('portrait-exif6.jpg', 'media/second.jpg');

        $media->update(['path' => 'media/second.jpg']);
        $media->refresh();

        $this->assertSame(800, $media->width);
        $this->assertSame(1200, $media->height);
        $this->assertNull($media->variants, 'the old ladder described the old file');
    }

    private function upload(string $fixture, string $path, string $mime): Media
    {
        $this->putFixture($fixture, $path);

        return Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => $mime,
            'size' => Storage::disk('public')->size($path),
        ])->fresh();
    }

    private function putFixture(string $fixture, string $path): void
    {
        Storage::disk('public')->put($path, (string) file_get_contents(__DIR__.'/../../Fixtures/media/'.$fixture));
    }
}
