<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament;

use InOtherShops\Commerce\Order\Contracts\HasOrders;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class CommerceSchema
{
    /**
     * Build an order lines repeater, optionally with orderable product selection.
     *
     * @param  array<string, class-string<HasOrders>>  $orderableModels  Morph alias => model class, e.g. ['product' => Product::class]
     * @param  array<string, string>  $currencyOptions  Fallback options for the currency select when no orderable is selected
     */
    public static function orderLinesRepeater(
        string $relationship = 'lines',
        array $orderableModels = [],
        string $orderableTitleColumn = 'name',
        array $currencyOptions = [],
    ): Repeater {
        return Repeater::make($relationship)
            ->relationship()
            ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                if (isset($data['currency']) && $data['currency'] instanceof \BackedEnum) {
                    $data['currency'] = $data['currency']->value;
                }

                return $data;
            })
            ->schema(self::orderLineFields($orderableModels, $orderableTitleColumn, $currencyOptions))
            ->columns(2);
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @param  array<string, string>  $currencyOptions
     * @return array<Component>
     */
    public static function orderLineFields(
        array $orderableModels = [],
        string $orderableTitleColumn = 'name',
        array $currencyOptions = [],
    ): array {
        $fields = [];

        if ($orderableModels !== []) {
            $fields = self::orderableSelectFields($orderableModels, $orderableTitleColumn, $currencyOptions);
        }

        $fields[] = TextInput::make('description')
            ->required()
            ->maxLength(255);

        $fields[] = TextInput::make('sku')
            ->label('SKU')
            ->maxLength(255);

        array_push($fields, ...self::currencyFields($orderableModels, $currencyOptions));

        $fields[] = TextInput::make('unit_price')
            ->required()
            ->numeric()
            ->minValue(0)
            ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : null)
            ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0)
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                self::recalculateLineTotal($state, $set, $get);
            });

        $fields[] = TextInput::make('quantity')
            ->required()
            ->numeric()
            ->default(1)
            ->minValue(1)
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                self::recalculateLineTotal($state, $set, $get);
            });

        $fields[] = TextInput::make('line_total')
            ->required()
            ->numeric()
            ->minValue(0)
            ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : null)
            ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round((float) $state * 100) : 0);

        return $fields;
    }

    /** @return array<Component> */
    private static function currencyFields(array $orderableModels, array $currencyOptions): array
    {
        if (count($currencyOptions) === 1) {
            $value = array_key_first($currencyOptions);

            return [
                Hidden::make('currency')
                    ->default($value)
                    ->dehydrateStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : ($state ?? $value)),
            ];
        }

        $select = Select::make('currency')
            ->required()
            ->live()
            ->dehydrated()
            ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state)
            ->afterStateHydrated(function (Select $component, $state): void {
                if ($state instanceof \BackedEnum) {
                    $component->state($state->value);
                }
            })
            ->afterStateUpdated(function (string|\BackedEnum|null $state, Set $set, Get $get) use ($orderableModels): void {
                $currencyCode = $state instanceof \BackedEnum ? $state->value : $state;
                self::fillPriceFromOrderable($currencyCode, $set, $get, $orderableModels);
            });

        if ($orderableModels !== []) {
            $select->options(function (Get $get) use ($orderableModels, $currencyOptions): array {
                return self::resolveOrderableCurrencyOptions($get, $orderableModels, $currencyOptions);
            });

            $select->selectablePlaceholder(function (Get $get) use ($orderableModels, $currencyOptions): bool {
                $options = self::resolveOrderableCurrencyOptions($get, $orderableModels, $currencyOptions);

                return count($options) !== 1;
            });
        } else {
            $select->options($currencyOptions);
        }

        return [$select];
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @param  array<string, string>  $currencyOptions
     * @return array<string, string>
     */
    private static function resolveOrderableCurrencyOptions(Get $get, array $orderableModels, array $currencyOptions): array
    {
        $orderableId = $get('orderable_id');

        if ($orderableId === null) {
            return $currencyOptions;
        }

        [, $model] = self::findOrderableById((int) $orderableId, $orderableModels);

        if ($model === null) {
            return $currencyOptions;
        }

        $currencies = $model->availableCurrencies();

        if ($currencies === []) {
            return $currencyOptions;
        }

        $options = [];
        foreach ($currencies as $code) {
            $options[$code] = $code;
        }

        return $options;
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @param  array<string, string>  $currencyOptions
     * @return array<Component>
     */
    private static function orderableSelectFields(array $orderableModels, string $orderableTitleColumn, array $currencyOptions): array
    {
        return [
            Hidden::make('orderable_type'),

            Select::make('orderable_id')
                ->label('Item')
                ->options(fn () => self::buildOrderableOptions($orderableModels, $orderableTitleColumn))
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get) use ($orderableModels, $currencyOptions): void {
                    self::handleOrderableSelected($state, $set, $get, $orderableModels, $currencyOptions);
                }),
        ];
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @param  array<string, string>  $currencyOptions
     */
    private static function handleOrderableSelected(
        ?string $state,
        Set $set,
        Get $get,
        array $orderableModels,
        array $currencyOptions,
    ): void {
        if ($state === null) {
            $set('orderable_type', null);

            return;
        }

        [$morphAlias, $model] = self::findOrderableById((int) $state, $orderableModels);

        if ($model === null) {
            return;
        }

        $set('orderable_type', $morphAlias);

        $currencies = $model->availableCurrencies();

        if (count($currencies) === 1) {
            $set('currency', $currencies[0]);
            self::fillOrderLineFromModel($model, $currencies[0], $set, $get);

            return;
        }

        $currencyCode = $get('currency') ?? '';

        if ($currencyCode !== '' && in_array($currencyCode, $currencies, true)) {
            self::fillOrderLineFromModel($model, $currencyCode, $set, $get);
        }
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @return array<string, string>
     */
    private static function buildOrderableOptions(array $orderableModels, string $orderableTitleColumn): array
    {
        $options = [];

        foreach ($orderableModels as $modelClass) {
            foreach ($modelClass::query()->pluck($orderableTitleColumn, 'id') as $id => $title) {
                $options[$id] = $title;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @return array{string|null, HasOrders|null}
     */
    private static function findOrderableById(int $id, array $orderableModels): array
    {
        foreach ($orderableModels as $morphAlias => $modelClass) {
            $model = $modelClass::find($id);

            if ($model !== null) {
                return [$morphAlias, $model];
            }
        }

        return [null, null];
    }

    private static function fillOrderLineFromModel(HasOrders $model, string $currencyCode, Set $set, Get $get): void
    {
        if ($currencyCode === '') {
            return;
        }

        $data = $model->toOrderLineData($currencyCode);

        $set('description', $data['description']);
        $set('sku', $data['sku']);
        $set('currency', $data['currency']);

        $displayPrice = number_format($data['unit_price'] / 100, 2, '.', '');
        $set('unit_price', $displayPrice);

        $quantity = (int) ($get('quantity') ?: 1);
        $set('line_total', number_format(($data['unit_price'] / 100) * $quantity, 2, '.', ''));
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     */
    private static function fillPriceFromOrderable(?string $currencyCode, Set $set, Get $get, array $orderableModels): void
    {
        $orderableId = $get('orderable_id');

        if ($orderableId === null || $currencyCode === null || $currencyCode === '') {
            return;
        }

        [, $model] = self::findOrderableById((int) $orderableId, $orderableModels);

        if ($model === null) {
            return;
        }

        self::fillOrderLineFromModel($model, $currencyCode, $set, $get);
    }

    private static function recalculateLineTotal(?string $state, Set $set, Get $get): void
    {
        $unitPrice = (float) ($get('unit_price') ?: 0);
        $quantity = (int) ($get('quantity') ?: 1);
        $set('line_total', number_format($unitPrice * $quantity, 2, '.', ''));
    }
}
