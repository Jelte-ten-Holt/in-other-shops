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
                    ->label(__('shops-common::fields.type'))
                    ->options([
                        AddressType::Shipping->value => AddressType::Shipping->label(),
                        AddressType::Billing->value => AddressType::Billing->label(),
                    ])
                    ->required(),
                TextInput::make('first_name')
                    ->label(__('shops-location::address.first_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->label(__('shops-location::address.last_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('line_1')
                    ->label(__('shops-location::address.line_1'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('line_2')
                    ->label(__('shops-location::address.line_2'))
                    ->maxLength(255),
                TextInput::make('city')
                    ->label(__('shops-location::address.city'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('state')
                    ->label(__('shops-location::address.state'))
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label(__('shops-location::address.postal_code'))
                    ->required()
                    ->maxLength(20),
                TextInput::make('country_code')
                    ->label(__('shops-common::fields.country_code'))
                    ->required()
                    ->maxLength(2)
                    ->minLength(2),
                TextInput::make('phone')
                    ->label(__('shops-location::address.phone'))
                    ->tel()
                    ->maxLength(50),
            ])
            ->columns(2);
    }
}
