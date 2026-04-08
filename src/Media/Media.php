<?php

declare(strict_types=1);

namespace InOtherShops\Media;

use InOtherShops\Media\Models\Media as MediaModel;
use InOtherShops\Media\Models\Mediable;

final class Media
{
    public static function media(): MediaModel
    {
        $class = config('media.models.media', MediaModel::class);

        return new $class;
    }

    public static function mediable(): Mediable
    {
        $class = config('media.models.mediable', Mediable::class);

        return new $class;
    }
}
