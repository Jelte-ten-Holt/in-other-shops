<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Models\Media;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `media:prune` deletes files, so every invariant it promises gets a test
 * that fails when the invariant is removed — the tests ARE the contract:
 *
 * 1. never a referenced original, and never a referenced rung;
 * 2. never anything outside the swept directory, and never `livewire-tmp`;
 * 3. never a file younger than `--min-age`;
 * 4. idempotent — a second forced run finds nothing;
 * 5. dry run by default.
 *
 * Plus the blocked policy: an undeletable file is recorded and the run
 * carries on with the rest.
 *
 * Rows are seeded without model events — the boot hooks would otherwise read
 * headers and queue variant jobs for fixtures that are deliberately not real
 * images here.
 */
final class PruneMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media.disk' => 'public', 'media.directory' => 'media']);
        Storage::fake('public');
    }

    #[Test]
    public function a_referenced_original_is_never_deleted(): void
    {
        $this->file('media/keep.jpg', old: true);
        $this->row(['path' => 'media/keep.jpg']);

        $output = $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('media/keep.jpg'));
        $this->assertStringContainsString('referenced', $output);
        $this->assertDisposition($output, 'referenced', 1);
        $this->assertDisposition($output, 'deleted', 0);
    }

    #[Test]
    public function a_referenced_variant_is_never_deleted(): void
    {
        $this->file('media/pic.jpg', old: true);
        $this->file('media/pic-w400.webp', old: true);
        $this->file('media/pic-w800.webp', old: true);

        $this->row([
            'path' => 'media/pic.jpg',
            'variants' => [
                '400' => ['path' => 'media/pic-w400.webp', 'width' => 400, 'height' => 300],
                '800' => ['path' => 'media/pic-w800.webp', 'width' => 800, 'height' => 600],
            ],
        ]);

        $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('media/pic-w400.webp'));
        $this->assertTrue(Storage::disk('public')->exists('media/pic-w800.webp'));
    }

    /**
     * The bianka case, and the reason the reference set is not scoped to the
     * swept directory: its rows all point at `products/…` while the sweep
     * runs over `media/`. A directory-scoped reference set would treat every
     * one of its rung files under `media/` as unreferenced.
     */
    #[Test]
    public function a_row_pointing_outside_the_swept_directory_still_protects_its_rungs(): void
    {
        $this->file('products/shirt.jpg', old: true);
        $this->file('media/shirt-w400.webp', old: true);

        $this->row([
            'path' => 'products/shirt.jpg',
            'variants' => ['400' => ['path' => 'media/shirt-w400.webp', 'width' => 400, 'height' => 300]],
        ]);

        $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('media/shirt-w400.webp'));
        $this->assertTrue(Storage::disk('public')->exists('products/shirt.jpg'));
    }

    #[Test]
    public function a_young_orphan_is_listed_and_kept(): void
    {
        $this->file('media/fresh.jpg', old: false);

        $output = $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('media/fresh.jpg'));
        $this->assertDisposition($output, 'young', 1);
        $this->assertDisposition($output, 'deleted', 0);
    }

    #[Test]
    public function a_dry_run_lists_an_old_orphan_without_deleting_it(): void
    {
        $this->file('media/orphan.jpg', old: true);

        $output = $this->prune();

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertTrue(Storage::disk('public')->exists('media/orphan.jpg'));
        $this->assertDisposition($output, 'orphan', 1);
        $this->assertDisposition($output, 'deleted', 0);
    }

    #[Test]
    public function force_deletes_an_old_orphan_and_a_second_run_finds_nothing(): void
    {
        $this->file('media/orphan.jpg', old: true);

        $first = $this->prune('--force');

        $this->assertStringContainsString('FORCED RUN', $first);
        $this->assertFalse(Storage::disk('public')->exists('media/orphan.jpg'));
        $this->assertDisposition($first, 'deleted', 1);

        $second = $this->prune('--force');

        $this->assertDisposition($second, 'deleted', 0);
        $this->assertDisposition($second, 'orphan', 0);
    }

    #[Test]
    public function a_file_outside_the_swept_directory_is_never_a_candidate(): void
    {
        $this->file('products/loose.jpg', old: true);
        $this->file('loose-at-root.jpg', old: true);

        $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('products/loose.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('loose-at-root.jpg'));
    }

    /**
     * Non-recursion is an invariant, not an implementation detail: it is what
     * keeps a nested directory — and, structurally, Livewire's sibling
     * `livewire-tmp/` — outside the sweep. Swapping `files()` for
     * `allFiles()` must break something, so it breaks this.
     */
    #[Test]
    public function the_sweep_is_non_recursive(): void
    {
        $this->file('media/nested/deep-orphan.jpg', old: true);
        $this->file('media/flat-orphan.jpg', old: true);

        $output = $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('media/nested/deep-orphan.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('media/flat-orphan.jpg'), 'the flat sweep itself still works');
        $this->assertDisposition($output, 'deleted', 1);
    }

    /**
     * Because the sweep is non-recursive, the only way it could ever reach a
     * pending Livewire upload is by being AIMED at `livewire-tmp` — a bad
     * MEDIA_DIRECTORY, or a slip of `--directory`. That is refused outright,
     * which is a guard that can actually fire; a per-file name check on a
     * non-recursive listing never could.
     */
    #[Test]
    public function it_refuses_to_sweep_livewire_tmp(): void
    {
        $this->file('livewire-tmp/pending.jpg', old: true);

        $this->artisan('media:prune --force --directory=livewire-tmp')
            ->expectsOutputToContain('Refusing to sweep livewire-tmp')
            ->assertFailed();

        $this->assertTrue(Storage::disk('public')->exists('livewire-tmp/pending.jpg'));
    }

    #[Test]
    public function a_sweep_of_the_disk_root_never_reaches_livewire_tmp(): void
    {
        config(['media.directory' => '']);
        $this->file('livewire-tmp/pending.jpg', old: true);
        $this->file('root-orphan.jpg', old: true);

        $this->prune('--force');

        $this->assertTrue(Storage::disk('public')->exists('livewire-tmp/pending.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('root-orphan.jpg'), 'the root sweep itself still works');
    }

    #[Test]
    public function a_row_on_another_disk_with_the_same_path_is_not_a_reference(): void
    {
        Storage::fake('other');
        $this->file('media/shared.jpg', old: true);
        $this->row(['path' => 'media/shared.jpg', 'disk' => 'other']);

        $this->prune('--force');

        $this->assertFalse(Storage::disk('public')->exists('media/shared.jpg'));
    }

    #[Test]
    public function an_undeletable_file_is_recorded_as_blocked_and_the_run_continues(): void
    {
        $this->file('media/locked/stuck.jpg', old: true);
        $this->file('media/deletable.jpg', old: true);

        // The sweep is non-recursive, so the locked file has to sit in the
        // swept directory itself. Making its PARENT read-only is what makes
        // unlink() fail — file permissions alone do not.
        $directory = Storage::disk('public')->path('media');
        Storage::disk('public')->move('media/locked/stuck.jpg', 'media/stuck.jpg');

        chmod($directory, 0555);

        try {
            $output = $this->pruneExpecting(1, '--force');
        } finally {
            chmod($directory, 0755);
        }

        $this->assertDisposition($output, 'blocked', 2, 'both orphans are blocked by the read-only directory');
        $this->assertTrue(Storage::disk('public')->exists('media/stuck.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('media/deletable.jpg'));
        $this->assertMatchesRegularExpression(
            '/delete returned false|cannot delete/',
            $output,
            'a blocked row records why, whichever shape the failure took',
        );
    }

    #[Test]
    public function the_manifest_carries_the_ulid_upload_time_and_the_nearest_row(): void
    {
        // A ULID minted at a known instant, and a row created 30 s later —
        // the shape of a replacement that moved the file but never wrote it.
        $uploadedAt = Carbon::parse('2026-05-06 11:00:00');
        $ulid = (string) \Symfony\Component\Uid\Ulid::generate($uploadedAt->toDateTimeImmutable());

        $this->file("media/{$ulid}.jpg", old: true);
        $this->file("media/{$ulid}-w400.webp", old: true);

        $neighbour = $this->row([
            'path' => 'products/live.jpg',
            'created_at' => $uploadedAt->copy()->addSeconds(30),
        ]);

        $output = $this->prune();

        $this->assertStringContainsString('2026-05-06 11:00:00', $output);
        $this->assertStringContainsString('#'.$neighbour->getKey(), $output);
        $this->assertDisposition($output, 'orphan', 2, 'the rung orphan is listed too');
    }

    #[Test]
    public function a_file_whose_name_is_not_a_ulid_reports_no_upload_time(): void
    {
        $this->file('media/hand-placed.jpg', old: true);
        $this->row(['path' => 'products/live.jpg', 'created_at' => Carbon::now()->subDay()]);

        $output = $this->prune();

        $this->assertMatchesRegularExpression('/hand-placed\.jpg.*—.*—/', $output);
    }

    #[Test]
    public function min_age_is_configurable_and_rejected_when_it_does_not_parse(): void
    {
        $this->file('media/an-hour-old.jpg', ageSeconds: 3600);

        // Default 6h keeps it; 30m is past it.
        $this->assertDisposition($this->prune('--force'), 'young', 1);
        $this->assertTrue(Storage::disk('public')->exists('media/an-hour-old.jpg'));

        $this->assertDisposition($this->prune('--force', '--min-age=30m'), 'deleted', 1);
        $this->assertFalse(Storage::disk('public')->exists('media/an-hour-old.jpg'));
    }

    #[Test]
    public function an_unparseable_min_age_deletes_nothing(): void
    {
        $this->file('media/orphan.jpg', old: true);

        $this->artisan('media:prune --force --min-age=soon')
            ->expectsOutputToContain('--min-age must look like')
            ->assertFailed();

        $this->assertTrue(Storage::disk('public')->exists('media/orphan.jpg'));
    }

    #[Test]
    public function the_summary_reports_counts_and_bytes_per_disposition_only(): void
    {
        $this->file('media/orphan.jpg', old: true, contents: str_repeat('x', 2048));
        $this->file('media/keep.jpg', old: true);
        $this->row(['path' => 'media/keep.jpg']);

        $output = $this->prune();

        $this->assertMatchesRegularExpression('/orphan\s+1\s+2\.0 KB/', $output);
        $this->assertDisposition($output, 'referenced', 1);

        foreach (['all clean', 'nothing to do', 'done', 'success'] as $verdict) {
            $this->assertStringNotContainsStringIgnoringCase($verdict, $output, 'the summary never grades itself');
        }
    }

    /**
     * Every timestamp in the manifest is rendered in the app's timezone.
     * `Carbon::createFromTimestamp()` builds in UTC while `Carbon::now()` —
     * and so the cutoff printed in the header — builds in the app's zone, so
     * without an explicit conversion the manifest's own columns disagree by
     * the offset. Both consumers run UTC today, which is exactly why this
     * needs a test rather than an observation.
     */
    #[Test]
    public function the_manifest_renders_timestamps_in_the_app_timezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $mtime = Carbon::parse('2026-05-06 09:00:00', 'UTC');
            Storage::disk('public')->put('media/orphan.jpg', 'x');
            touch(Storage::disk('public')->path('media/orphan.jpg'), $mtime->getTimestamp());
            clearstatcache();

            $output = $this->prune();

            // 09:00 UTC is 11:00 in Berlin (CEST). The UTC rendering would
            // print 09:00 next to a cutoff two hours ahead of it.
            $this->assertStringContainsString('2026-05-06 11:00:00', $output);
            $this->assertStringNotContainsString('2026-05-06 09:00:00', $output);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    private function prune(string ...$options): string
    {
        return $this->pruneExpecting(0, ...$options);
    }

    /** A run that must exit non-zero — the blocked-file case. */
    private function pruneExpecting(int $exit, string ...$options): string
    {
        $flags = [];

        foreach ($options as $option) {
            [$name, $value] = array_pad(explode('=', $option, 2), 2, true);
            $flags[$name] = $value;
        }

        $this->assertSame($exit, Artisan::call('media:prune', $flags));

        return Artisan::output();
    }

    private function assertDisposition(string $output, string $disposition, int $count, string $message = ''): void
    {
        $this->assertMatchesRegularExpression("/^{$disposition}\\s+{$count}\\s/m", $output, $message);
    }

    private function file(string $path, bool $old = false, ?int $ageSeconds = null, string $contents = 'x'): void
    {
        Storage::disk('public')->put($path, $contents);

        $age = $ageSeconds ?? ($old ? 86400 : 60);
        touch(Storage::disk('public')->path($path), time() - $age);
        clearstatcache();
    }

    /** @param array<string, mixed> $attributes */
    private function row(array $attributes): Media
    {
        return Media::withoutEvents(fn (): Media => Media::factory()->create($attributes + [
            'disk' => 'public',
            'type' => MediaType::Upload->value,
        ]));
    }
}
