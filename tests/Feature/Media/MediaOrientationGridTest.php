<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Media;

use GdImage;
use InOtherShops\Media\Support\ImageOrientation;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins `ImageOrientation::apply` for all eight EXIF orientations against a
 * 3×2 grid of distinct colours, so a mapping error cannot hide behind "the
 * dimensions came out right".
 *
 * v0.69.x shipped 5 and 7 swapped: both flipped VERTICALLY after the
 * rotation, which produces the other one's image exactly — the pair
 * round-trips into each other, both keep the transposed 2×3 shape, and no
 * dimension assertion can tell them apart. Only pixel positions can.
 *
 * The expectations are derived from the EXIF spec's own table, which states
 * where the stored image's 0th row and 0th column sit in the VISUAL image:
 * 1 (top,left) · 2 (top,right) · 3 (bottom,right) · 4 (bottom,left) ·
 * 5 (left,top) · 6 (right,top) · 7 (right,bottom) · 8 (left,bottom). The
 * stored grid is always
 *
 *     A B C
 *     D E F
 *
 * and each case below is that table applied by hand — not a recording of
 * what the code currently returns.
 */
final class MediaOrientationGridTest extends TestCase
{
    /** @var array<string, array{int, int, int}> */
    private const array CELLS = [
        'A' => [255, 0, 0],
        'B' => [0, 255, 0],
        'C' => [0, 0, 255],
        'D' => [255, 255, 0],
        'E' => [255, 0, 255],
        'F' => [0, 255, 255],
    ];

    /** @return array<string, array{int, string}> */
    public static function orientations(): array
    {
        return [
            '1 — upright' => [1, 'A B C / D E F'],
            '2 — mirrored' => [2, 'C B A / F E D'],
            '3 — half turn' => [3, 'F E D / C B A'],
            '4 — mirrored, half turn' => [4, 'D E F / A B C'],
            '5 — transpose (mirrored quarter turn)' => [5, 'A D / B E / C F'],
            '6 — quarter turn clockwise' => [6, 'D A / E B / F C'],
            '7 — transverse (mirrored quarter turn)' => [7, 'F C / E B / D A'],
            '8 — quarter turn anticlockwise' => [8, 'C F / B E / A D'],
        ];
    }

    #[Test]
    #[DataProvider('orientations')]
    public function it_uprights_every_exif_orientation(int $orientation, string $expected): void
    {
        $upright = ImageOrientation::apply($this->storedGrid(), $orientation);

        $this->assertSame($expected, $this->render($upright), "EXIF orientation {$orientation} is mapped wrongly.");

        imagedestroy($upright);
    }

    /**
     * The transposing half of the table (5–8) must also report the swapped
     * shape — 3×2 stored becomes 2 wide by 3 tall once upright.
     */
    #[Test]
    #[DataProvider('orientations')]
    public function transposing_orientations_swap_the_axes(int $orientation, string $expected): void
    {
        $upright = ImageOrientation::apply($this->storedGrid(), $orientation);
        $transposes = ImageOrientation::transposes($orientation);

        $this->assertSame($transposes ? 2 : 3, imagesx($upright));
        $this->assertSame($transposes ? 3 : 2, imagesy($upright));

        imagedestroy($upright);
    }

    /** The 3×2 stored grid: A B C over D E F. */
    private function storedGrid(): GdImage
    {
        $image = imagecreatetruecolor(3, 2);
        $this->assertInstanceOf(GdImage::class, $image);

        foreach ([['A', 'B', 'C'], ['D', 'E', 'F']] as $y => $row) {
            foreach ($row as $x => $cell) {
                [$r, $g, $b] = self::CELLS[$cell];
                imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, $r, $g, $b));
            }
        }

        return $image;
    }

    /** Reads the pixels back as letters: "A B C / D E F". */
    private function render(GdImage $image): string
    {
        $names = [];

        foreach (self::CELLS as $cell => [$r, $g, $b]) {
            $names["{$r},{$g},{$b}"] = $cell;
        }

        $rows = [];

        for ($y = 0; $y < imagesy($image); $y++) {
            $cells = [];

            for ($x = 0; $x < imagesx($image); $x++) {
                $colour = imagecolorat($image, $x, $y);
                $key = (($colour >> 16) & 0xFF).','.(($colour >> 8) & 0xFF).','.($colour & 0xFF);
                $cells[] = $names[$key] ?? '?';
            }

            $rows[] = implode(' ', $cells);
        }

        return implode(' / ', $rows);
    }
}
