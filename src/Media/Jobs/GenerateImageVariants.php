<?php

declare(strict_types=1);

namespace InOtherShops\Media\Jobs;

use GdImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InOtherShops\Media\Media as MediaRegistry;
use InOtherShops\Media\Models\Media;
use InOtherShops\Media\Support\ImageOrientation;

/**
 * Bakes the WebP ladder for one uploaded image (D2, D3, D9). Dispatched from
 * `Media::saved` when a row is created or its `path` changes — after commit,
 * because a transactional admin panel would otherwise hand the worker a row
 * that does not exist yet.
 *
 * Bounded by construction: `$timeout` sits under the workers' 60 s, the
 * megapixel cap refuses a decode that would not fit the worker's memory, and
 * every reason NOT to produce rungs is RECORDED on the row (`variants = {}`
 * plus one log line) rather than thrown — `variants IS NULL` therefore means
 * exactly "never attempted", which is what `media:variants` lists.
 *
 * Idempotent across reseeds: when every rung this ladder would write is
 * already on disk, the map is rebuilt from what is there and nothing is
 * decoded. bianka's staging reseeds every boot; without this it would
 * regenerate every seeded image's ladder on every boot.
 *
 * Unique on `disk:path`, not on the row: `MediaSchema` re-creates rows it
 * cannot match, and two rows sharing a file would otherwise queue two
 * identical jobs. Carries scalars, not the model — a row deleted between
 * dispatch and run must bail quietly, not fail deserialisation.
 *
 * The write-back is `saveQuietly()`: belt and braces on top of the `saved`
 * guard (which only re-dispatches on a `path` change), so the job can never
 * re-queue itself.
 */
final class GenerateImageVariants implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 55;

    public int $tries = 2;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $mediaId,
        public readonly string $disk,
        public readonly string $path,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->disk}:{$this->path}";
    }

    public function handle(): void
    {
        /** @var Media|null $media */
        $media = MediaRegistry::media()::query()->find($this->mediaId);

        // Gone, or re-pointed since dispatch (a fresh job is queued for the
        // new path): nothing to do, and nothing to record on a row that has
        // moved on.
        if ($media === null || $media->disk !== $this->disk || $media->path !== $this->path) {
            return;
        }

        if (! $media->isImage()) {
            $this->skip($media, 'not an image');

            return;
        }

        if (config("filesystems.disks.{$this->disk}.driver") !== 'local') {
            $this->skip($media, 'disk is not local');

            return;
        }

        $storage = Storage::disk($this->disk);

        if (! $storage->exists($this->path)) {
            $this->skip($media, 'source file missing');

            return;
        }

        $file = $storage->path($this->path);
        $info = @getimagesize($file);

        if ($info === false) {
            $this->skip($media, 'source is not a decodable image');

            return;
        }

        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? $media->mime_type);
        $decoder = self::decoderFor($mime);

        if ($decoder === null) {
            $this->skip($media, "no GD decoder for {$mime}");

            return;
        }

        $orientation = ImageOrientation::read($file, $mime);

        if (ImageOrientation::transposes($orientation)) {
            [$width, $height] = [$height, $width];
        }

        $maxPixels = (float) config('media.variants.max_megapixels', 40) * 1_000_000;

        if ($width * $height > $maxPixels) {
            $this->skip($media, sprintf('%d×%d exceeds the %s megapixel cap', $width, $height, config('media.variants.max_megapixels', 40)));

            return;
        }

        // The megapixel cap bounds time and disk; memory is bounded here, from
        // what is actually free in THIS process. A booted queue worker holds
        // well over 100 MB before the first pixel, so a 35 MP photo that
        // decodes fine in a bare `php -r` (169 MB peak) exhausts a 256M
        // worker — and an OOM is a fatal, not an exception: the worker dies,
        // the container restarts, the job burns its attempts and the row
        // stays `null` for every backfill to retry. Measured on staging
        // 2026-09-04. Recorded instead, like every other reason.
        $bytesNeeded = (int) ceil($width * $height * (float) config('media.variants.bytes_per_pixel', 5));
        $bytesFree = self::memoryHeadroom();

        if ($bytesFree !== null && $bytesNeeded > $bytesFree) {
            $this->skip($media, sprintf(
                'decoding %d×%d needs ~%d MB; %d MB free under memory_limit %s',
                $width, $height, intdiv($bytesNeeded, 1_048_576), intdiv($bytesFree, 1_048_576), (string) ini_get('memory_limit'),
            ));

            return;
        }

        $targets = array_values(array_filter(self::ladder(), fn (int $rung): bool => $rung < $width));

        if ($targets === []) {
            $this->skip($media, "{$width} px source is not wider than the smallest rung");

            return;
        }

        $existing = $this->adoptExistingRungs($media, $storage, $targets);

        if ($existing !== null) {
            $this->writeBack($media, $existing);

            return;
        }

        $image = @$decoder($file);

        if (! $image instanceof GdImage) {
            $this->skip($media, "GD could not decode {$mime}");

            return;
        }

        $image = $this->prepare($image, $mime, $orientation);
        $quality = (int) config('media.variants.quality', 80);
        $variants = [];

        try {
            foreach ($targets as $rung) {
                $scaled = imagescale($image, $rung, -1);

                if ($scaled === false) {
                    throw new \RuntimeException("imagescale to {$rung} px failed");
                }

                try {
                    imagealphablending($scaled, false);
                    imagesavealpha($scaled, true);

                    $variantPath = $media->variantPath($rung);
                    $absolute = $storage->path($variantPath);

                    if (! imagewebp($scaled, $absolute, $quality)) {
                        throw new \RuntimeException("imagewebp to {$variantPath} failed");
                    }

                    $variants[$rung] = [
                        'path' => $variantPath,
                        'width' => imagesx($scaled),
                        'height' => imagesy($scaled),
                    ];
                } finally {
                    imagedestroy($scaled);
                }
            }
        } finally {
            imagedestroy($image);
        }

        $this->writeBack($media, $variants);
    }

    /**
     * When every rung file already exists, describe what is there and skip
     * the decode. Any rung missing means regenerate the whole ladder — one
     * decode, N scales, is the cheap unit; picking single rungs is not.
     *
     * @param  list<int>  $targets
     * @return array<int, array{path: string, width: int, height: int}>|null
     */
    private function adoptExistingRungs(Media $media, \Illuminate\Contracts\Filesystem\Filesystem $storage, array $targets): ?array
    {
        $variants = [];

        foreach ($targets as $rung) {
            $variantPath = $media->variantPath($rung);

            if (! $storage->exists($variantPath)) {
                return null;
            }

            $size = @getimagesize($storage->path($variantPath));

            if ($size === false) {
                return null;
            }

            $variants[$rung] = ['path' => $variantPath, 'width' => $size[0], 'height' => $size[1]];
        }

        return $variants;
    }

    private function prepare(GdImage $image, string $mime, int $orientation): GdImage
    {
        // Palette sources (GIF, 8-bit PNG) scale badly and lose transparency;
        // promote to truecolor first. Alpha must be carried, not blended, on
        // the way through every intermediate.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return ImageOrientation::apply($image, $orientation);
    }

    /**
     * @param  array<int, array{path: string, width: int, height: int}>  $variants
     */
    private function writeBack(Media $media, array $variants): void
    {
        ksort($variants);

        // String keys on purpose: PHP would otherwise serialise a dense int-keyed
        // map as a JSON list and the widths would be lost on the way back.
        $media->variants = array_combine(array_map('strval', array_keys($variants)), array_values($variants));
        $media->saveQuietly();
    }

    private function skip(Media $media, string $reason): void
    {
        $media->variants = [];
        $media->saveQuietly();

        Log::info('media:variants skipped an image', [
            'media_id' => $media->getKey(),
            'disk' => $this->disk,
            'path' => $this->path,
            'reason' => $reason,
        ]);
    }

    /**
     * Bytes still allocatable under `memory_limit`, or null when unlimited.
     */
    public static function memoryHeadroom(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return null;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (float) $limit;
        $bytes = match ($unit) {
            'g' => $value * 1_073_741_824,
            'm' => $value * 1_048_576,
            'k' => $value * 1_024,
            default => $value,
        };

        return max(0, (int) $bytes - memory_get_usage(true));
    }

    /** @return list<int> ascending, de-duplicated, positive */
    public static function ladder(): array
    {
        $widths = array_map('intval', (array) config('media.variants.widths', [400, 800, 1600]));
        $widths = array_values(array_unique(array_filter($widths, fn (int $w): bool => $w > 0)));
        sort($widths);

        return $widths;
    }

    /** @return (callable(string): (GdImage|false))|null */
    private static function decoderFor(string $mime): ?callable
    {
        $function = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif' => 'imagecreatefromgif',
            'image/avif' => 'imagecreatefromavif',
            'image/bmp' => 'imagecreatefrombmp',
            default => null,
        };

        return $function !== null && function_exists($function) ? $function : null;
    }
}
