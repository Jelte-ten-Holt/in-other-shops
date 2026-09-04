<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Jobs\GenerateImageVariants;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The WebP ladder (D2, D3, D9, F9). Dispatch is asserted through the fake
 * queue; generation is exercised by running the job by hand against real
 * fixture bytes, because "a variant exists" is not the same as "a variant
 * is upright, keeps its alpha and is the right size".
 */
final class GenerateImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public', 'media.variants.widths' => [400, 800, 1600]]);
        Storage::fake('public');
        Queue::fake();
    }

    #[Test]
    public function it_writes_only_the_rungs_narrower_than_the_source(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');

        $this->generate($media);
        $media->refresh();

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists('media/land-w400.webp'));
        $this->assertTrue($disk->exists('media/land-w800.webp'));
        $this->assertFalse($disk->exists('media/land-w1600.webp'), 'a 1200 px source must never be upscaled');

        $this->assertSame([400, 800], array_keys($media->variants));
        $this->assertSame(['path' => 'media/land-w400.webp', 'width' => 400, 'height' => 266], $media->variants[400]);
        $this->assertSame(800, $media->variants[800]['width']);
        $this->assertSame(533, $media->variants[800]['height']);

        [$w, $h, $type] = getimagesize($disk->path('media/land-w400.webp'));
        $this->assertSame([400, 266, IMAGETYPE_WEBP], [$w, $h, $type]);
    }

    #[Test]
    public function an_exif_orientation_6_source_produces_upright_variants(): void
    {
        $media = $this->upload('portrait-exif6.jpg', 'media/phone.jpg');

        $this->generate($media);
        $media->refresh();

        // Stored pixels are landscape; the rungs must be portrait, like the browser shows the original.
        $this->assertSame([400], array_keys($media->variants), 'the displayed width is 800, so only the 400 rung is narrower');
        $this->assertGreaterThan($media->variants[400]['width'], $media->variants[400]['height']);

        [$w, $h] = getimagesize(Storage::disk('public')->path('media/phone-w400.webp'));
        $this->assertSame([400, 600], [$w, $h]);

        // Orientation 6 = rotate 90° clockwise to display. The stored TOP band (red)
        // ends up on the RIGHT; a pixel near the right edge must be red, not blue.
        $variant = imagecreatefromwebp(Storage::disk('public')->path('media/phone-w400.webp'));
        $rgb = imagecolorsforindex($variant, imagecolorat($variant, 395, 300));
        $this->assertGreaterThan($rgb['blue'], $rgb['red'], 'the stored top of the frame should now be on the right');
    }

    #[Test]
    public function transparency_survives_into_the_webp_rung(): void
    {
        $media = $this->upload('alpha.png', 'media/logo.png', 'image/png');

        $this->generate($media);

        $variant = imagecreatefromwebp(Storage::disk('public')->path('media/logo-w400.webp'));
        $transparent = (imagecolorat($variant, 10, 10) >> 24) & 0x7F;
        $opaque = (imagecolorat($variant, 390, 10) >> 24) & 0x7F;

        $this->assertSame(127, $transparent, 'the transparent half must stay transparent');
        $this->assertSame(0, $opaque, 'the opaque half must stay opaque');
    }

    /**
     * A palette PNG carries transparency as an index, not a channel. Without
     * the promotion to truecolor (and alpha carried, not blended) the
     * transparent index scales into an opaque colour.
     */
    #[Test]
    public function transparency_survives_from_a_palette_png(): void
    {
        $media = $this->upload('alpha-palette.png', 'media/badge.png', 'image/png');

        $this->generate($media);

        $variant = imagecreatefromwebp(Storage::disk('public')->path('media/badge-w400.webp'));
        $this->assertSame(127, (imagecolorat($variant, 10, 10) >> 24) & 0x7F, 'the transparent index must become a transparent pixel');
        $this->assertSame(0, (imagecolorat($variant, 390, 10) >> 24) & 0x7F);
    }

    #[Test]
    public function an_oversize_source_is_recorded_as_skipped_without_being_decoded(): void
    {
        config(['media.variants.max_megapixels' => 0.5]);
        Log::spy();
        $media = $this->upload('landscape-1200.jpg', 'media/big.jpg');

        $this->generate($media);
        $media->refresh();

        $this->assertSame([], $media->variants, '{} = attempted, nothing produced');
        $this->assertFalse(Storage::disk('public')->exists('media/big-w400.webp'));
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($context['reason'] ?? '', 'megapixel'))->once();
    }

    /**
     * Staging, 2026-09-04: a 7008×5088 photo (35.6 MP, under the 40 MP cap)
     * exhausted the 256M worker three times over, restarting the container
     * each time, and its row stayed `null` for every backfill to retry.
     * Memory is bounded from what is free in this process, not from a number.
     */
    #[Test]
    public function a_source_that_would_not_fit_the_free_memory_is_recorded_as_skipped(): void
    {
        // The test runner may run unlimited; the guard only bites under a finite limit.
        $original = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
        config(['media.variants.bytes_per_pixel' => 1_000_000]);
        Log::spy();

        try {
            $media = $this->upload('landscape-1200.jpg', 'media/huge.jpg');

            $this->generate($media);
        } finally {
            ini_set('memory_limit', (string) $original);
        }

        $this->assertSame([], $media->fresh()->variants);
        $this->assertSame([], $this->rungFiles());
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c): bool => str_contains($c['reason'] ?? '', 'free under memory_limit'))->once();
    }

    #[Test]
    public function memory_headroom_is_null_when_unlimited_and_positive_otherwise(): void
    {
        $original = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '-1');
            $this->assertNull(GenerateImageVariants::memoryHeadroom());

            ini_set('memory_limit', '2G');
            $headroom = GenerateImageVariants::memoryHeadroom();
            $this->assertNotNull($headroom);
            $this->assertGreaterThan(0, $headroom);
            $this->assertLessThan(2 * 1_073_741_824, $headroom);
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    #[Test]
    public function a_source_not_wider_than_the_smallest_rung_is_recorded_as_skipped(): void
    {
        config(['media.variants.widths' => [1200, 2000]]);
        $media = $this->upload('landscape-1200.jpg', 'media/small.jpg');

        $this->generate($media);

        $this->assertSame([], $media->fresh()->variants);
        $this->assertSame([], $this->rungFiles(), 'no rung file may be written');
    }

    #[Test]
    public function a_missing_source_file_is_recorded_as_skipped(): void
    {
        $media = Media::factory()->create(['path' => 'media/gone.jpg']);

        $this->generate($media);

        $this->assertSame([], $media->fresh()->variants);
    }

    #[Test]
    public function non_image_uploads_and_external_rows_are_never_dispatched(): void
    {
        Storage::disk('public')->put('media/manual.pdf', '%PDF-1.4');
        Media::create([
            'type' => MediaType::Upload, 'disk' => 'public', 'path' => 'media/manual.pdf',
            'filename' => 'manual.pdf', 'mime_type' => 'application/pdf', 'size' => 8,
        ]);
        // External rows are created with an image mime type and no file — the
        // type check, not the mime check, is what keeps them out of the queue.
        Media::factory()->external()->create();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function creating_an_image_upload_dispatches_exactly_one_job_for_it(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');

        Queue::assertPushed(GenerateImageVariants::class, 1);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->mediaId === $media->id && $job->disk === 'public' && $job->path === 'media/land.jpg');
    }

    #[Test]
    public function a_second_dispatch_for_the_same_file_is_deduplicated(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');

        GenerateImageVariants::dispatch($media->id, 'public', 'media/land.jpg');
        GenerateImageVariants::dispatch($media->id + 1000, 'public', 'media/land.jpg');

        Queue::assertPushed(GenerateImageVariants::class, 1);
    }

    #[Test]
    public function the_write_back_does_not_dispatch_again(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');

        $this->generate($media);
        $media->fresh()->update(['size' => 1]);

        Queue::assertPushed(GenerateImageVariants::class, 1);
    }

    #[Test]
    public function replacing_the_file_removes_the_old_rungs_and_dispatches_for_the_new_path(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/first.jpg');
        $this->generate($media);
        $this->assertTrue(Storage::disk('public')->exists('media/first-w400.webp'));
        $this->putFixture('portrait-exif6.jpg', 'media/second.jpg');

        $media->fresh()->update(['path' => 'media/second.jpg']);

        $disk = Storage::disk('public');
        $this->assertFalse($disk->exists('media/first.jpg'));
        $this->assertFalse($disk->exists('media/first-w400.webp'), 'the replaced file\'s rungs must go with it');
        $this->assertFalse($disk->exists('media/first-w800.webp'));
        $this->assertNull($media->fresh()->variants);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->path === 'media/second.jpg');
    }

    #[Test]
    public function deleting_removes_the_rungs_unless_another_row_shares_the_file(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/shared.jpg');
        $this->generate($media);
        $twin = Media::factory()->create(['disk' => 'public', 'path' => 'media/shared.jpg']);

        $media->delete();

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists('media/shared.jpg'), 'still referenced by the twin');
        $this->assertTrue($disk->exists('media/shared-w400.webp'), 'the twin serves the same rungs');

        $twin->delete();

        $this->assertFalse($disk->exists('media/shared.jpg'));
        $this->assertFalse($disk->exists('media/shared-w400.webp'));
        $this->assertFalse($disk->exists('media/shared-w800.webp'));
    }

    #[Test]
    public function a_job_carrying_a_stale_path_bails_without_writing(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/first.jpg');
        $stale = new GenerateImageVariants($media->id, 'public', 'media/first.jpg');
        $this->putFixture('landscape-1200.jpg', 'media/second.jpg');
        $media->update(['path' => 'media/second.jpg']);

        $stale->handle();

        $this->assertNull($media->fresh()->variants, 'a stale job must not record anything on a row that moved on');
        $this->assertSame([], $this->rungFiles());
    }

    #[Test]
    public function a_job_for_a_deleted_row_bails_quietly(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/land.jpg');
        $job = new GenerateImageVariants($media->id, 'public', 'media/land.jpg');
        $media->delete();

        $job->handle();

        $this->assertSame([], $this->rungFiles());
    }

    /**
     * bianka's staging reseeds every boot: every seeded row is re-created and
     * re-dispatched, but its rungs are already on disk. The job must adopt
     * them, not regenerate them.
     */
    #[Test]
    public function rung_files_already_on_disk_are_adopted_without_regenerating(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/seeded.jpg');
        $this->generate($media);
        $rung = Storage::disk('public')->path('media/seeded-w400.webp');
        touch($rung, 1_000_000_000);
        clearstatcache();

        $media->forceFill(['variants' => null])->saveQuietly();
        $this->generate($media);

        clearstatcache();
        $this->assertSame(1_000_000_000, filemtime($rung), 'the rung was rewritten instead of adopted');
        $this->assertSame([400, 800], array_keys($media->fresh()->variants));
        $this->assertSame(266, $media->fresh()->variants[400]['height']);
    }

    #[Test]
    public function a_partial_ladder_on_disk_is_regenerated_in_full(): void
    {
        $media = $this->upload('landscape-1200.jpg', 'media/partial.jpg');
        $this->generate($media);
        Storage::disk('public')->delete('media/partial-w800.webp');

        $media->forceFill(['variants' => null])->saveQuietly();
        $this->generate($media);

        $this->assertTrue(Storage::disk('public')->exists('media/partial-w800.webp'));
        $this->assertSame([400, 800], array_keys($media->fresh()->variants));
    }

    #[Test]
    public function variants_can_be_switched_off_per_consumer(): void
    {
        config(['media.variants.enabled' => false]);

        $this->upload('landscape-1200.jpg', 'media/land.jpg');

        Queue::assertNothingPushed();
    }

    /** @return list<string> */
    private function rungFiles(): array
    {
        return array_values(array_filter(Storage::disk('public')->files('media'), fn (string $f): bool => str_ends_with($f, '.webp')));
    }

    private function generate(Media $media): void
    {
        (new GenerateImageVariants($media->id, (string) $media->disk, (string) $media->path))->handle();
    }

    private function upload(string $fixture, string $path, string $mime = 'image/jpeg'): Media
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
