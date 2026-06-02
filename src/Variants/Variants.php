<?php

declare(strict_types=1);

namespace InOtherShops\Variants;

use InOtherShops\Variants\Models\Option;
use InOtherShops\Variants\Models\OptionValue;
use InOtherShops\Variants\Models\Variant;

final class Variants
{
    /** @return class-string<Option> */
    public static function option(): string
    {
        return config('variants.models.option', Option::class);
    }

    /** @return class-string<OptionValue> */
    public static function optionValue(): string
    {
        return config('variants.models.option_value', OptionValue::class);
    }

    /** @return class-string<Variant> */
    public static function variant(): string
    {
        return config('variants.models.variant', Variant::class);
    }
}
