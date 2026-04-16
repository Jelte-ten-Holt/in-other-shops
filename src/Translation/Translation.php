<?php

declare(strict_types=1);

namespace InOtherShops\Translation;

use InOtherShops\Translation\Models\Translation as TranslationModel;

final class Translation
{
    /** @return class-string<TranslationModel> */
    public static function translation(): string
    {
        return config('translation.models.translation', TranslationModel::class);
    }
}
