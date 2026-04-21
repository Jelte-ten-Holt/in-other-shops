<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament;

use InOtherShops\Currency\Enums\Currency;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

final class PricingSchema
{
    public static function priceRepeater(string $relationship = 'prices'): Repeater
    {
        return Repeater::make($relationship)
            ->relationship()
            ->schema([
                self::currencySelect(),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                TextInput::make('compare_at_amount')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null),
                Select::make('price_list_id')
                    ->relationship('priceList', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('minimum_quantity')
                    ->numeric()
                    ->default(1)
                    ->minValue(1),
            ])
            ->columns(2);
    }

    public static function currencySelect(string $name = 'currency'): Select
    {
        $enabled = Currency::enabled();

        $select = Select::make($name)
            ->options(Currency::enabledOptions())
            ->required();

        if (count($enabled) === 1) {
            $select->default($enabled[0]->value)
                ->disabled()
                ->dehydrated();
        }

        return $select;
    }
}
