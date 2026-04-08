<?php

declare(strict_types=1);

namespace InOtherShops\Media\Events;

use InOtherShops\Media\Models\Media;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MediaStored
{
    use Dispatchable;

    public function __construct(
        public Media $media,
        public string $collection,
    ) {}
}
