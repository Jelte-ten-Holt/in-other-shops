<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Jobs\GenerateImageVariants;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `media:variants` — the backfill and the detection surface. Rows are seeded
 * without model events so the command's own dispatches are the only ones on
 * the fake queue (a create-time dispatch would otherwise hold the unique
 * lock and mask what the command did).
 */
final class GenerateMediaVariantsCommandTest extends TestCase
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
    public function by_default_it_dispatches_only_image_rows_never_attempted(): void
    {
        $never = $this->row(['variants' => null]);
        $this->row(['variants' => []]);
        $this->row(['variants' => ['400' => ['path' => 'x', 'width' => 400, 'height' => 300]]]);
        $this->row(['variants' => null, 'mime_type' => 'application/pdf', 'path' => 'media/doc.pdf']);
        $this->row(['variants' => null, 'type' => MediaType::External->value, 'disk' => null, 'path' => null]);

        $this->artisan('media:variants')
            ->expectsOutputToContain('Dispatched 1, skipped 1 (non-image)')
            ->assertSuccessful();

        Queue::assertPushed(GenerateImageVariants::class, 1);
        Queue::assertPushed(GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->mediaId === $never->id);
    }

    #[Test]
    public function all_resets_every_image_row_and_dispatches_for_each(): void
    {
        $done = $this->row(['variants' => ['400' => ['path' => 'x', 'width' => 400, 'height' => 300]]]);
        $skipped = $this->row(['variants' => []]);
        $this->row(['variants' => null]);

        $this->artisan('media:variants --all')->expectsOutputToContain('Dispatched 3')->assertSuccessful();

        Queue::assertPushed(GenerateImageVariants::class, 3);
        $this->assertNull($done->fresh()->variants);
        $this->assertNull($skipped->fresh()->variants);
    }

    #[Test]
    public function limit_caps_the_batch(): void
    {
        $this->row(['variants' => null]);
        $this->row(['variants' => null]);
        $this->row(['variants' => null]);

        $this->artisan('media:variants --limit=2')->expectsOutputToContain('Dispatched 2')->assertSuccessful();

        Queue::assertPushed(GenerateImageVariants::class, 2);
    }

    #[Test]
    public function sync_runs_the_job_inline_and_produces_the_files(): void
    {
        $media = $this->rowWithFile('landscape-1200.jpg', 'media/land.jpg');

        $this->artisan('media:variants --sync')->expectsOutputToContain('Generated 1')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertTrue(Storage::disk('public')->exists('media/land-w400.webp'));
        $this->assertSame([400, 800], array_keys($media->fresh()->variants));
    }

    #[Test]
    public function it_fills_dimensions_on_rows_that_predate_the_columns(): void
    {
        $media = $this->rowWithFile('portrait-exif6.jpg', 'media/phone.jpg');
        $this->assertNull($media->width, 'seeded without events, so nothing read the header');

        $this->artisan('media:variants')->expectsOutputToContain('filled dimensions on 1')->assertSuccessful();

        $this->assertSame([800, 1200], [$media->fresh()->width, $media->fresh()->height]);
    }

    #[Test]
    public function running_it_again_after_a_backfill_dispatches_nothing(): void
    {
        $this->rowWithFile('landscape-1200.jpg', 'media/land.jpg');
        $this->artisan('media:variants --sync')->assertSuccessful();

        $this->artisan('media:variants')->expectsOutputToContain('Dispatched 0')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $attributes */
    private function row(array $attributes): Media
    {
        return Media::withoutEvents(fn (): Media => Media::factory()->create($attributes + ['disk' => 'public']));
    }

    private function rowWithFile(string $fixture, string $path): Media
    {
        Storage::disk('public')->put($path, (string) file_get_contents(__DIR__.'/../../Fixtures/media/'.$fixture));

        return $this->row(['path' => $path, 'variants' => null, 'width' => null, 'height' => null]);
    }
}
