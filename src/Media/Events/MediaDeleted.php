<?php

declare(strict_types=1);

namespace InOtherShops\Media\Events;

use InOtherShops\Media\Enums\MediaType;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class MediaDeleted
{
    use Dispatchable;

    public function __construct(
        public int $mediaId,
        public string $filename,
        public MediaType $type,
    ) {}
}
