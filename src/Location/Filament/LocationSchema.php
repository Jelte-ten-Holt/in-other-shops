<?php

declare(strict_types=1);

namespace InOtherShops\Location\Filament;

use InOtherShops\Location\Enums\AddressType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

final class LocationSchema
{
    public static function addressRepeater(string $relationship = 'addresses'): Repeater
    {
        return Repeater::make($relationship)
            ->relationship()
            ->schema([
                Select::make('type')
                    ->options([
                        AddressType::Shipping->value => AddressType::Shipping->label(),
                        AddressType::Billing->value => AddressType::Billing->label(),
                    ])
                    ->required(),
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('line_1')
                    ->label('Address line 1')
                    ->required()
                    ->maxLength(255),
                TextInput::make('line_2')
                    ->label('Address line 2')
                    ->maxLength(255),
                TextInput::make('city')
                    ->required()
                    ->maxLength(255),
                TextInput::make('state')
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->required()
                    ->maxLength(20),
                TextInput::make('country_code')
                    ->label('Country code')
                    ->required()
                    ->maxLength(2)
                    ->minLength(2),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(50),
            ])
            ->columns(2);
    }
}
