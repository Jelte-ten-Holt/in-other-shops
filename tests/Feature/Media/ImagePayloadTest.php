<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Jobs\GenerateImageVariants;
use InOtherShops\Media\Models\Media;
use InOtherShops\Media\Support\ImagePayload;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * D8: the one image payload. Package data only — no `sizes`, no attribute
 * string, no HTML. `srcset` is null until rungs exist, and the original is
 * always the widest candidate once they do.
 */
final class ImagePayloadTest extends TestCase
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
    public function without_variants_the_payload_carries_dimensions_and_a_null_srcset(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');
        $media->update(['alt' => 'A lake', 'description' => 'Evening light']);

        $payload = ImagePayload::for($media->fresh());

        $this->assertSame(['url', 'alt', 'description', 'width', 'height', 'srcset'], array_keys($payload));
        $this->assertSame(Storage::disk('public')->url('media/land.jpg'), $payload['url']);
        $this->assertSame('A lake', $payload['alt']);
        $this->assertSame('Evening light', $payload['description']);
        $this->assertSame(1200, $payload['width']);
        $this->assertSame(800, $payload['height']);
        $this->assertNull($payload['srcset']);
    }

    #[Test]
    public function with_variants_the_srcset_lists_each_rung_and_the_original_as_the_widest(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');
        (new GenerateImageVariants($media->id, 'public', 'media/land.jpg'))->handle();

        $payload = ImagePayload::for($media->fresh());
        $disk = Storage::disk('public');

        $this->assertSame([
            ['url' => $disk->url('media/land-w400.webp'), 'width' => 400],
            ['url' => $disk->url('media/land-w800.webp'), 'width' => 800],
            ['url' => $disk->url('media/land.jpg'), 'width' => 1200],
        ], $payload['srcset']);
        $this->assertSame($disk->url('media/land.jpg'), $payload['url'], '<img src> stays the original (D9)');
    }

    #[Test]
    public function a_recorded_skip_reads_as_no_srcset(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');
        $media->forceFill(['variants' => []])->saveQuietly();

        $this->assertNull(ImagePayload::for($media->fresh())['srcset']);
    }

    #[Test]
    public function an_external_row_presents_its_url_with_nothing_else_to_say(): void
    {
        $media = Media::factory()->external()->create();

        $payload = ImagePayload::for($media->fresh());

        $this->assertSame($media->url, $payload['url']);
        $this->assertNull($payload['width']);
        $this->assertNull($payload['srcset']);
    }

    private function upload(string $fixture, string $path): Media
    {
        Storage::disk('public')->put($path, (string) file_get_contents(__DIR__.'/../../Fixtures/media/'.$fixture));

        return Media::create([
            'type' => MediaType::Upload,
            'disk' => 'public',
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => 'image/jpeg',
            'size' => Storage::disk('public')->size($path),
        ])->fresh();
    }
}
