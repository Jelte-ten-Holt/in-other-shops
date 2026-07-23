<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament\Resources;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Purchasing\Filament\Resources\SupplierResource\Pages;
use InOtherShops\Purchasing\Models\Supplier;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use InOtherShops\Support\Filament\NavigationGroup;
use InOtherShops\Support\Filament\PackageResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends PackageResource
{
    protected static ?string $model = Supplier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Purchasing;

    protected static function labelKey(): string
    {
        return 'shops-purchasing::supplier';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('shops-purchasing::supplier.section.supplier'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('shops-common::fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('contact_email')
                            ->label(__('shops-purchasing::supplier.fields.contact_email'))
                            ->email()
                            ->maxLength(255),
                        Select::make('default_currency')
                            ->label(__('shops-purchasing::supplier.fields.default_currency'))
                            ->options(Currency::enabledOptions())
                            ->default(Currency::EUR->value)
                            ->required(),
                        TextInput::make('payment_terms')
                            ->label(__('shops-purchasing::supplier.fields.payment_terms'))
                            ->maxLength(255)
                            ->placeholder(__('shops-purchasing::supplier.fields.payment_terms_placeholder')),
                        Textarea::make('notes')
                            ->label(__('shops-common::fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('shops-common::fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('contact_email')
                    ->label(__('shops-purchasing::supplier.fields.contact_email'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('default_currency')
                    ->label(__('shops-purchasing::supplier.fields.default_currency'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state),
                Tables\Columns\TextColumn::make('purchase_orders_count')
                    ->label(__('shops-purchasing::supplier.columns.pos'))
                    ->counts('purchaseOrders')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('shops-common::fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
