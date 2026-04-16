<?php

declare(strict_types=1);

namespace InOtherShops\Media;

use InOtherShops\Media\Models\Media as MediaModel;
use InOtherShops\Media\Models\Mediable;

final class Media
{
    /** @return class-string<MediaModel> */
    public static function media(): string
    {
        return config('media.models.media', MediaModel::class);
    }

    /** @return class-string<Mediable> */
    public static function mediable(): string
    {
        return config('media.models.mediable', Mediable::class);
    }
}
