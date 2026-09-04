<?php

declare(strict_types=1);

namespace InOtherShops\Media\Support;

use InOtherShops\Media\Models\Media;

/**
 * The one image payload every consumer surface renders from (D8) — the shape
 * a card, a slider figure, a lightbox and a hero all hand to `<img>`. Same
 * convention as `Commerce\Order\Support\OrderSummary`: a static `for()`
 * returning package data, nothing presentational.
 *
 * `srcset` is `null` until variants exist, else a list of candidates —
 * `[['url' => …, 'width' => …], …]` — ascending, with the ORIGINAL as the
 * widest candidate when its width is known. The package never assembles the
 * attribute string: `sizes` is a layout decision and the consumer's tiny JS
 * helper builds both from this list. `url` is always the original (D9): the
 * fallback is the file that exists today, no `<picture>` needed.
 */
final class ImagePayload
{
    /** @return array{url: string, alt: ?string, description: ?string, width: ?int, height: ?int, srcset: ?list<array{url: string, width: int}>} */
    public static function for(Media $media): array
    {
        return [
            'url' => $media->url(),
            'alt' => $media->alt,
            'description' => $media->description,
            'width' => $media->width,
            'height' => $media->height,
            'srcset' => $media->srcset(),
        ];
    }
}
