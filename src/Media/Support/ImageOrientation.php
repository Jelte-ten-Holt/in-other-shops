<?php

declare(strict_types=1);

namespace InOtherShops\Media\Support;

use GdImage;

/**
 * EXIF orientation, read once at ingest and applied in two places (F9): to
 * the stored `width`/`height` (the pair is transposed for 5–8, since
 * `getimagesize()` reports the pixels as stored) and to the decoded pixels
 * before a variant is scaled (GD does not honour EXIF; browsers do).
 *
 * Only JPEG and TIFF carry EXIF. Everything else, and every environment
 * without the `exif` extension, reads as 1 (upright).
 */
final class ImageOrientation
{
    public const int UPRIGHT = 1;

    public static function read(string $file, ?string $mime): int
    {
        if (! in_array($mime, ['image/jpeg', 'image/tiff'], true) || ! function_exists('exif_read_data')) {
            return self::UPRIGHT;
        }

        $data = @exif_read_data($file);
        $orientation = is_array($data) ? ($data['Orientation'] ?? null) : null;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : self::UPRIGHT;
    }

    /** Orientations 5–8 rotate by 90°, so width and height swap. */
    public static function transposes(int $orientation): bool
    {
        return $orientation >= 5;
    }

    /**
     * Returns the upright image. The input handle is consumed (destroyed)
     * whenever a new one is allocated, so callers keep only the return value.
     */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => self::flip($image, IMG_FLIP_HORIZONTAL),
            3 => self::rotate($image, 180),
            4 => self::flip($image, IMG_FLIP_VERTICAL),
            5 => self::flip(self::rotate($image, 270), IMG_FLIP_VERTICAL),
            6 => self::rotate($image, 270),
            7 => self::flip(self::rotate($image, 90), IMG_FLIP_VERTICAL),
            8 => self::rotate($image, 90),
            default => $image,
        };
    }

    private static function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /** Counter-clockwise degrees, GD's convention. */
    private static function rotate(GdImage $image, int $degrees): GdImage
    {
        $transparent = (int) imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees, $transparent);
        imagedestroy($image);

        if ($rotated === false) {
            throw new \RuntimeException("imagerotate({$degrees}) failed.");
        }

        return $rotated;
    }
}
