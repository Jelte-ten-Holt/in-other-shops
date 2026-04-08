<?php

declare(strict_types=1);

namespace InOtherShops\Translation;

use InOtherShops\Translation\Models\Translation as TranslationModel;

final class Translation
{
    public static function translation(): TranslationModel
    {
        $class = config('translation.models.translation', TranslationModel::class);

        return new $class;
    }
}
