<?php

declare(strict_types=1);

namespace InOtherShops\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InOtherShops\Logging\Concerns\RunsAsSystemActor;
use InOtherShops\Media\Media as MediaRegistry;
use InOtherShops\Media\Models\Media;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Deletes files in `media.directory` that no `media` row points at (W4).
 *
 * Orphans exist because three things left files behind: the replace no-op
 * fixed in v0.68.0 (every replacement ever made), `DeleteVariant` detaching
 * without deleting, and a Filament save that throws after the upload has
 * already been moved out of `livewire-tmp` (bianka's panel runs
 * `->databaseTransactions()`, so a rollback leaves a moved file with no row).
 * Nothing has ever swept them up; on staging more than half the bytes under
 * IOW's `media/` were orphaned.
 *
 * This command deletes files, so it runs under the job contract and its
 * invariants are the code, not a comment:
 *
 * 1. Never deletes a file any `media.path` on this disk points at, or any
 *    variant path — the reference set spans EVERY row on the disk regardless
 *    of directory, because bianka's seeded rows live under `products/` while
 *    the sweep is scoped to `media/`. A reference set narrowed to the swept
 *    directory would have deleted every one of them.
 * 2. Never touches anything outside `media.directory`: the candidate list is
 *    one non-recursive `files()` call, so a nested directory is not swept
 *    either. That non-recursion is also what keeps Livewire's pending
 *    uploads safe — `livewire-tmp/` is a sibling directory, never a file in
 *    the swept one — so the only way the sweep could reach them is if it
 *    were POINTED at them, which `handle()` refuses outright.
 * 3. Never deletes a file younger than `--min-age` (default 6h). The window
 *    between Filament moving a file out of `livewire-tmp` and writing its row
 *    is milliseconds, but a sweep landing inside it destroys a live upload,
 *    and the threshold costs nothing.
 * 4. Idempotent: it reads state and deletes; a second run finds no orphans.
 * 5. Dry run is the default. Nothing is deleted without `--force`.
 *
 * Blocked-handling: a file that cannot be stat-ed or deleted is recorded as
 * `blocked` with the exception message and the run continues. The command
 * never retries, never widens its scope, and never falls back to another
 * directory. A run with any `blocked` row exits non-zero so a scheduled
 * sweep surfaces it instead of failing quietly.
 *
 * The manifest is per file, not a "deleted N files" summary: the operator
 * decides, and cannot decide from a count. Each row carries the ULID-derived
 * upload time and the nearest `media` row created around it (Q3) — the
 * closest thing to a retroactive report of which replacements silently
 * failed before v0.68.0, for free, because the filenames are ULIDs.
 */
final class PruneMediaCommand extends Command
{
    use RunsAsSystemActor;

    /** Dispositions, in the order the summary prints them. */
    private const array DISPOSITIONS = ['referenced', 'young', 'orphan', 'deleted', 'blocked'];

    /** How far either side of a file's ULID timestamp to look for its row. */
    private const int NEAREST_ROW_WINDOW_SECONDS = 300;

    protected $signature = 'media:prune
        {--force : Delete the orphans. Without this the run is a dry run and touches nothing}
        {--min-age=6h : Leave files modified more recently than this alone (e.g. 30m, 6h, 2d)}
        {--disk= : Sweep this disk instead of media.disk}
        {--directory= : Sweep this directory instead of media.directory}';

    protected $description = 'List (and with --force, delete) files in the media directory that no media row references';

    public function handle(): int
    {
        $this->beginSystemAuditActor();

        $disk = (string) ($this->option('disk') ?? config('media.disk', 'public'));
        $directory = trim((string) ($this->option('directory') ?? config('media.directory', 'media')), '/');

        // The one way a non-recursive sweep of `media.directory` could reach
        // Livewire's pending uploads is by being aimed at them — a bad
        // MEDIA_DIRECTORY, or a slip of `--directory`. Those files belong to
        // requests in flight and Livewire prunes them itself.
        if ($directory === 'livewire-tmp' || str_starts_with($directory, 'livewire-tmp/')) {
            $this->error('Refusing to sweep livewire-tmp: those are uploads in flight, and Livewire prunes them itself.');

            return self::FAILURE;
        }

        $seconds = $this->minAgeSeconds();

        if ($seconds === null) {
            $this->error('--min-age must look like 45s, 30m, 6h or 2d.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subSeconds($seconds);
        $force = (bool) $this->option('force');

        // Every row on this disk, whatever directory it points at.
        $rows = MediaRegistry::media()::query()
            ->where('disk', $disk)
            ->whereNotNull('path')
            ->orderBy('id')
            ->get();

        $referenced = $this->referenceSet($rows);
        $storage = Storage::disk($disk);

        $this->line(sprintf(
            '%s · disk %s · directory %s/ · min-age %s (files modified after %s are left alone) · %d row(s) referencing %d path(s)',
            $force ? 'FORCED RUN — orphans are deleted' : 'DRY RUN — nothing is deleted',
            $disk,
            $directory,
            (string) $this->option('min-age'),
            $cutoff->toDateTimeString(),
            $rows->count(),
            count($referenced),
        ));

        $manifest = [];
        $counts = array_fill_keys(self::DISPOSITIONS, 0);
        $bytes = array_fill_keys(self::DISPOSITIONS, 0);

        foreach ($storage->files($directory) as $path) {
            $row = $this->inspect($storage, $path, $referenced, $cutoff, $force, $rows);

            $manifest[] = $row;
            $counts[$row['disposition']]++;
            $bytes[$row['disposition']] += $row['bytes'];
        }

        $this->manifest($manifest);
        $this->summary($counts, $bytes);
        $this->record($force, $counts, $bytes);

        return $counts['blocked'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every path this disk's rows point at: the original AND its rung files.
     *
     * `variantFilePaths()` is the package's existing answer to "which files
     * belong to this row" — the union of what the `variants` map records and
     * what the CURRENT ladder would name. Reusing it keeps prune and the
     * delete/replace paths on one definition: a rung the ladder would write
     * is protected even if the map has not been written back yet (the job is
     * queued, so there is always a window where it has not).
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $rows
     * @return array<string, true>
     */
    private function referenceSet($rows): array
    {
        $referenced = [];

        foreach ($rows as $media) {
            $referenced[(string) $media->path] = true;

            foreach ($media->variantFilePaths() as $variantPath) {
                $referenced[$variantPath] = true;
            }
        }

        return $referenced;
    }

    /**
     * One manifest row. Order matters: referenced beats age, age beats
     * deletion, and a stat failure is recorded rather than assumed away.
     *
     * @param  array<string, true>  $referenced
     * @param  \Illuminate\Support\Collection<int, Media>  $rows
     * @return array{path: string, bytes: int, size: string, modified: string, uploaded_at: string, nearest_row: string, disposition: string, note: string}
     */
    private function inspect(Filesystem $storage, string $path, array $referenced, Carbon $cutoff, bool $force, $rows): array
    {
        $uploadedAt = $this->uploadedAt($path);

        $row = [
            'path' => $path,
            'bytes' => 0,
            'size' => '—',
            'modified' => '—',
            'uploaded_at' => $uploadedAt?->toDateTimeString() ?? '—',
            'nearest_row' => $this->nearestRow($rows, $uploadedAt),
            'disposition' => 'blocked',
            'note' => '',
        ];

        try {
            $row['bytes'] = (int) $storage->size($path);
            $row['size'] = $this->humanBytes($row['bytes']);
            // Explicitly into the app timezone. `createFromTimestamp()` builds
            // in UTC while `now()` (and so the cutoff line in the header)
            // builds in the app's zone, and this manifest is the audit record
            // of a destructive run that a human reads column by column — two
            // of its timestamps silently hours apart is not something to
            // leave to whether `app.timezone` happens to be UTC. Comparison
            // is unaffected either way; Carbon compares instants.
            $modified = Carbon::createFromTimestamp((int) $storage->lastModified($path))->setTimezone(date_default_timezone_get());
            $row['modified'] = $modified->toDateTimeString();
        } catch (Throwable $e) {
            // Blocked-handling: recorded, not repaired, not assumed young or
            // old. A file we cannot stat is a file we must not delete.
            $row['bytes'] = 0;
            $row['note'] = 'cannot stat: '.$e->getMessage();

            return $row;
        }

        if (isset($referenced[$path])) {
            $row['disposition'] = 'referenced';

            return $row;
        }

        if ($modified->greaterThan($cutoff)) {
            $row['disposition'] = 'young';
            $row['note'] = 'newer than the min-age cutoff';

            return $row;
        }

        if (! $force) {
            $row['disposition'] = 'orphan';

            return $row;
        }

        try {
            // FilesystemAdapter::delete() swallows UnableToDeleteFile and
            // returns false unless the disk is configured to throw, so both
            // shapes of failure have to be handled to keep the promise that
            // a `deleted` row means the file is gone.
            if ($storage->delete($path) === false) {
                $row['note'] = 'delete returned false';

                return $row;
            }
        } catch (Throwable $e) {
            $row['note'] = 'cannot delete: '.$e->getMessage();

            return $row;
        }

        $row['disposition'] = 'deleted';

        return $row;
    }

    /**
     * When the file was uploaded, read out of its own name: `StoreMedia`
     * names files with a ULID, and a ULID's first 48 bits are its
     * millisecond timestamp. A rung file (`{ulid}-w400.webp`) carries its
     * original's ULID, so the suffix is stripped before parsing — a rung
     * orphan should date to the upload that produced it.
     *
     * `—` for anything else (a legacy or hand-placed filename), which is
     * itself worth seeing in the manifest.
     */
    private function uploadedAt(string $path): ?Carbon
    {
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $stem = (string) preg_replace('/-w\d+$/', '', $stem);

        if (! Str::isUlid($stem)) {
            return null;
        }

        try {
            return Carbon::instance(Ulid::fromString($stem)->getDateTime())->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Q3 hint: a row created around the same time as this file was
     * uploaded is very likely the row the file was meant for — the other
     * half of a replacement that silently failed before v0.68.0. A hint, not
     * a finding; it never affects a disposition.
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $rows
     */
    private function nearestRow($rows, ?Carbon $uploadedAt): string
    {
        if ($uploadedAt === null) {
            return '—';
        }

        $nearest = null;
        $nearestDistance = null;

        foreach ($rows as $media) {
            if ($media->created_at === null) {
                continue;
            }

            $distance = abs($media->created_at->diffInSeconds($uploadedAt));

            if ($distance > self::NEAREST_ROW_WINDOW_SECONDS) {
                continue;
            }

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $media;
                $nearestDistance = $distance;
            }
        }

        return $nearest === null
            ? '—'
            : sprintf('#%s %s', $nearest->getKey(), basename((string) $nearest->path));
    }

    /** @param list<array<string, mixed>> $manifest */
    private function manifest(array $manifest): void
    {
        if ($manifest === []) {
            $this->line('No files in the directory.');

            return;
        }

        $this->table(
            ['File', 'Size', 'Modified', 'Uploaded (ULID)', 'Nearest row', 'Disposition', 'Note'],
            array_map(fn (array $row): array => [
                $row['path'],
                $row['size'],
                $row['modified'],
                $row['uploaded_at'],
                $row['nearest_row'],
                $row['disposition'],
                $row['note'],
            ], $manifest),
        );
    }

    /**
     * Counts and bytes per disposition, and nothing else. No "done", no
     * "clean", no "N files reclaimed, all good" — the supervisor reads the
     * manifest and decides; a summary that grades itself is the thing the
     * job contract exists to prevent.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $bytes
     */
    private function summary(array $counts, array $bytes): void
    {
        foreach (self::DISPOSITIONS as $disposition) {
            $this->line(sprintf('%-11s %4d  %s', $disposition, $counts[$disposition], $this->humanBytes($bytes[$disposition])));
        }
    }

    /**
     * A scheduled sweep's manifest goes to stdout, which on a `schedule:work`
     * container is nobody. So a forced run that actually changed something
     * leaves one line in the log as well — at `warning`, the level both
     * consumers actually run, which is the same lesson the variant job's
     * skip line just cost us.
     *
     * Only when a forced run deleted or blocked something: a dry run changes
     * nothing, and once the backlog is swept the nightly run is silent, so a
     * line appearing at all is the signal.
     *
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $bytes
     */
    private function record(bool $force, array $counts, array $bytes): void
    {
        if (! $force || ($counts['deleted'] === 0 && $counts['blocked'] === 0)) {
            return;
        }

        Log::warning('media:prune deleted orphaned files', [
            'deleted' => $counts['deleted'],
            'deleted_bytes' => $bytes['deleted'],
            'blocked' => $counts['blocked'],
            'referenced' => $counts['referenced'],
            'young' => $counts['young'],
        ]);
    }

    /** Seconds in `--min-age`, or null when it does not parse. */
    private function minAgeSeconds(): ?int
    {
        $value = trim((string) $this->option('min-age'));

        if (preg_match('/^(\d+)\s*([smhd])?$/i', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * match (strtolower($matches[2] ?? 's')) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            default => 1,
        };
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
