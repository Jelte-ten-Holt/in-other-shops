<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Forms\Components\Select;

/**
 * Normalizes a Select whose model attribute casts to a backed enum so the
 * component always holds the scalar value: enum instances are unwrapped on
 * format and on hydration. Shared by the Commerce currency selects, which
 * previously each hand-rolled both closures.
 */
final class BackedEnumState
{
    public static function normalize(Select $select): Select
    {
        return $select
            ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state)
            ->afterStateHydrated(function (Select $component, $state): void {
                if ($state instanceof \BackedEnum) {
                    $component->state($state->value);
                }
            });
    }
}
