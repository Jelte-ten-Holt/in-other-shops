<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament;

use InOtherShops\Purchasing\Contracts\HasPurchases;
use InOtherShops\Support\Filament\MoneyFields;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Filament form fragments for purchase-order lines. The product picker discovers
 * purchasable models by walking the morph map and filtering for {@see HasPurchases}
 * — a model declares "I can be purchased" by implementing the contract; nothing
 * is registered per-schema. Options are keyed `"{alias}:{id}"` so two morph types
 * sharing an integer id never collide.
 */
final class PurchasingSchema
{
    public static function purchaseLinesRepeater(string $name = 'lines'): Repeater
    {
        return Repeater::make($name)
            ->schema(self::purchaseLineFields())
            ->columns(2)
            ->defaultItems(1)
            ->addActionLabel('Add line');
    }

    /**
     * @return array<Component>
     */
    public static function purchaseLineFields(): array
    {
        return [
            ...self::purchasableSelectFields(),
            TextInput::make('description')
                ->required()
                ->maxLength(255),
            TextInput::make('sku')
                ->label('SKU')
                ->maxLength(255),
            TextInput::make('quantity_ordered')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(1)
                ->default(1),
            MoneyFields::moneyInput('unit_cost')
                ->label('Unit cost (net)')
                ->required(),
            // nullable: input_vat is a nullable column — blank means "not
            // recorded", not zero VAT.
            MoneyFields::moneyInput('input_vat', nullable: true)
                ->label('Input VAT (reclaimable)')
                ->nullable(),
        ];
    }

    /**
     * @return array<Component>
     */
    private static function purchasableSelectFields(): array
    {
        $models = self::discoverPurchasableModels();

        if ($models === []) {
            return [];
        }

        return [
            Hidden::make('purchasable_type'),
            Hidden::make('purchasable_id'),
            Select::make('_purchasable')
                ->label('Product')
                ->options(fn (): array => self::buildPurchasableOptions($models))
                ->searchable()
                ->preload()
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) use ($models): void {
                    self::handlePurchasableSelected($state, $set, $models);
                }),
        ];
    }

    /**
     * Walk the morph map and keep classes implementing HasPurchases.
     *
     * @return array<string, class-string<HasPurchases>>
     */
    public static function discoverPurchasableModels(): array
    {
        $models = [];

        foreach (Relation::morphMap() as $alias => $class) {
            if (is_string($class) && is_a($class, HasPurchases::class, true)) {
                $models[$alias] = $class;
            }
        }

        return $models;
    }

    /**
     * @param  array<string, class-string<HasPurchases>>  $models
     * @return array<string, string>  "{alias}:{id}" => label
     */
    public static function buildPurchasableOptions(array $models): array
    {
        $options = [];

        foreach ($models as $alias => $class) {
            $titleColumn = $class::purchasableTitleColumn();

            foreach ($class::query()->pluck($titleColumn, 'id') as $id => $title) {
                $options["{$alias}:{$id}"] = $title;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, class-string<HasPurchases>>  $models
     */
    private static function handlePurchasableSelected(?string $state, Set $set, array $models): void
    {
        if ($state === null || ! str_contains($state, ':')) {
            $set('purchasable_type', null);
            $set('purchasable_id', null);

            return;
        }

        [$alias, $id] = explode(':', $state, 2);

        if (! isset($models[$alias])) {
            return;
        }

        $model = $models[$alias]::query()->find((int) $id);

        if ($model === null) {
            return;
        }

        $set('purchasable_type', $alias);
        $set('purchasable_id', (int) $id);

        $snapshot = $model->toPurchaseLineData();
        $set('description', $snapshot['description']);
        $set('sku', $snapshot['sku']);
    }
}
