<?php

declare(strict_types=1);

namespace InOtherShops\Media\Actions;

use InOtherShops\Media\Events\MediaDeleted;
use InOtherShops\Media\Models\Media;

final class DeleteMedia
{
    public function __invoke(Media $media): void
    {
        $mediaId = $media->id;
        $filename = $media->filename;
        $type = $media->type;

        $media->delete();

        MediaDeleted::dispatch($mediaId, $filename, $type);
    }
}
