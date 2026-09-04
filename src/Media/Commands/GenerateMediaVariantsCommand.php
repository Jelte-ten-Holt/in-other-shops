<?php

declare(strict_types=1);

namespace InOtherShops\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InOtherShops\Logging\Concerns\RunsAsSystemActor;
use InOtherShops\Media\Enums\MediaType;
use InOtherShops\Media\Jobs\GenerateImageVariants;
use InOtherShops\Media\Media as MediaRegistry;
use InOtherShops\Media\Models\Media;

/**
 * Backfill and detection surface for the variant ladder. Registered, never
 * auto-scheduled (package convention): the consumer runs it once per
 * environment after the v0.69.0 bump, and again whenever it wants to know
 * what is still missing — "dispatched 0" is the done signal.
 *
 * Default (`--missing`) targets image rows never attempted (`variants IS
 * NULL`); a recorded skip (`{}`) is a decision, not a gap, and is left alone.
 * `--all` resets every image row and re-dispatches — after changing the
 * ladder, or to regenerate after a bad batch. `--sync` runs the job inline
 * for a one-shot CLI backfill when the queue is busy.
 *
 * Also fills `width`/`height` on rows that predate the columns: a header
 * read, synchronous, no queue.
 *
 * `--skipped` is the other detection half (v0.70.0): it lists the rows a
 * previous run RECORDED as skipped (`variants = {}` — attempted, nothing
 * produced) and exits without dispatching anything. The default listing
 * cannot show these, by design — `{}` is a decision, not a gap — so before
 * this option a skip was visible only in the log, and both consumers run
 * `LOG_LEVEL=warning`, where the job's line did not land until v0.70.0
 * raised it. An oversize hero served as a 1.7 MB original is exactly the
 * thing that hides here.
 */
final class GenerateMediaVariantsCommand extends Command
{
    use RunsAsSystemActor;

    protected $signature = 'media:variants
        {--missing : Only image rows never attempted (variants IS NULL) — the default}
        {--all : Reset every image row and regenerate its ladder}
        {--skipped : List image rows a previous run recorded as skipped (variants = {}) and exit}
        {--sync : Run the job inline instead of queueing it}
        {--limit= : Stop after this many rows}';

    protected $description = 'Generate (or backfill) the WebP variant ladder for uploaded images';

    public function handle(): int
    {
        $this->beginSystemAuditActor();

        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $model = MediaRegistry::media();

        $uploads = fn (): Builder => $model::query()
            ->where('type', MediaType::Upload->value)
            ->whereNotNull('disk')
            ->whereNotNull('path');

        if ($this->option('skipped')) {
            return $this->listSkipped($uploads());
        }

        $filled = $this->fillMissingDimensions($uploads());

        $query = $uploads()->where('mime_type', 'like', 'image/%')->orderBy('id');

        if (! $this->option('all')) {
            $query->whereNull('variants');
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $dispatched = 0;

        /** @var Media $media */
        foreach ($query->get() as $media) {
            if ($this->option('all')) {
                $media->variants = null;
                $media->saveQuietly();
            }

            $job = new GenerateImageVariants((int) $media->getKey(), (string) $media->disk, (string) $media->path);

            // `--sync` calls the handler directly rather than `dispatchSync()`:
            // that still routes through the queue manager (on the `sync`
            // connection), which is exactly what a busy or faked queue is
            // in the way of. The job has no dependencies to resolve.
            $this->option('sync') ? $job->handle() : GenerateImageVariants::dispatch($job->mediaId, $job->disk, $job->path);
            $dispatched++;
        }

        $skipped = $uploads()->where('mime_type', 'not like', 'image/%')->count();

        $this->info(sprintf(
            '%s %d, skipped %d (non-image)%s.',
            $this->option('sync') ? 'Generated' : 'Dispatched',
            $dispatched,
            $skipped,
            $filled > 0 ? ", filled dimensions on {$filled}" : '',
        ));

        return self::SUCCESS;
    }

    /**
     * The recorded-skip listing: rows whose `variants` is an EMPTY map.
     *
     * The emptiness test is done in PHP, not in SQL, and that is deliberate.
     * The obvious `JSON_LENGTH(variants) = 0` is correct on MySQL, where it
     * counts an object's keys — but Laravel's `whereJsonLength()` compiles to
     * SQLite's `json_array_length()`, which returns **0 for any JSON value
     * that is not an array**. A successful row (`{"400": {…}}`) is an object,
     * so on SQLite that predicate lists every row that WORKED — precisely
     * inverted, and silent. Production is MySQL and the suite runs both, so a
     * driver-dependent listing would have been right where it is never read
     * and wrong where it is tested. The row count here is an operator-scale
     * listing (tens of rows), so a cursor costs nothing.
     *
     * Dimensions are shown because they are usually the reason — an oversize
     * source is refused by the megapixel cap or the memory estimate, and a
     * source narrower than the smallest rung has nothing to produce. Rows
     * whose dimensions never got read print `—`, which is itself a signal.
     */
    private function listSkipped(Builder $uploads): int
    {
        $rows = [];

        /** @var Media $media */
        foreach ($uploads->where('mime_type', 'like', 'image/%')->whereNotNull('variants')->orderBy('id')->cursor() as $media) {
            if ($media->variants !== []) {
                continue;
            }

            $rows[] = [
                $media->getKey(),
                $media->disk,
                $media->path,
                $media->width !== null && $media->height !== null ? "{$media->width}×{$media->height}" : '—',
            ];
        }

        $this->line(sprintf('%d image row(s) recorded as skipped (variants = {}).', count($rows)));

        if ($rows !== []) {
            $this->table(['ID', 'Disk', 'Path', 'Dimensions'], $rows);
        }

        return self::SUCCESS;
    }

    private function fillMissingDimensions(Builder $uploads): int
    {
        $filled = 0;

        /** @var Media $media */
        foreach ($uploads->where('mime_type', 'like', 'image/%')->whereNull('width')->cursor() as $media) {
            $media->readDimensions();

            if ($media->isDirty(['width', 'height'])) {
                $media->saveQuietly();
                $filled++;
            }
        }

        return $filled;
    }
}
