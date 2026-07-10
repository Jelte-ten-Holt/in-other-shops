<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Filament;

use InOtherShops\Commerce\Order\Contracts\HasOrders;
use InOtherShops\Support\Filament\BackedEnumState;
use InOtherShops\Support\Filament\MoneyFields;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Relations\Relation;

final class CommerceSchema
{
    /**
     * Build an order lines repeater with orderable product selection.
     *
     * Orderable models are discovered from the morph map (any class implementing
     * {@see HasOrders}) — a consumer registers a product as orderable simply by
     * implementing the contract and adding it to the morph map; nothing is passed
     * here. Mirrors {@see \InOtherShops\Purchasing\Filament\PurchasingSchema}.
     *
     * @param  array<string, string>  $currencyOptions  Fallback options for the currency select when no orderable is selected
     */
    public static function orderLinesRepeater(
        string $relationship = 'lines',
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
            ->schema(self::orderLineFields($currencyOptions))
            ->columns(2);
    }

    /**
     * @param  array<string, string>  $currencyOptions
     * @return array<Component>
     */
    public static function orderLineFields(array $currencyOptions = []): array
    {
        $orderableModels = self::discoverOrderableModels();

        $fields = [];

        if ($orderableModels !== []) {
            $fields = self::orderableSelectFields($orderableModels, $currencyOptions);
        }

        $fields[] = TextInput::make('description')
            ->label(__('shops-common::fields.description'))
            ->required()
            ->maxLength(255);

        $fields[] = TextInput::make('sku')
            ->label(__('shops-common::fields.sku'))
            ->maxLength(255);

        array_push($fields, ...self::currencyFields($orderableModels, $currencyOptions));

        $fields[] = MoneyFields::moneyInput('unit_price')
            ->label(__('shops-commerce::orders.fields.unit_price'))
            ->required()
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                self::recalculateLineTotal($state, $set, $get);
            });

        $fields[] = TextInput::make('quantity')
            ->label(__('shops-common::fields.quantity'))
            ->required()
            ->numeric()
            ->default(1)
            ->minValue(1)
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                self::recalculateLineTotal($state, $set, $get);
            });

        $fields[] = MoneyFields::moneyInput('line_total')
            ->label(__('shops-commerce::orders.fields.line_total'))
            ->required();

        return $fields;
    }

    /**
     * Walk the morph map and keep classes implementing HasOrders.
     *
     * @return array<string, class-string<HasOrders>>
     */
    public static function discoverOrderableModels(): array
    {
        $models = [];

        foreach (Relation::morphMap() as $alias => $class) {
            if (is_string($class) && is_a($class, HasOrders::class, true)) {
                $models[$alias] = $class;
            }
        }

        return $models;
    }

    /**
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     * @return array<string, string>  "{alias}:{id}" => label
     */
    public static function buildOrderableOptions(array $orderableModels): array
    {
        $options = [];

        foreach ($orderableModels as $alias => $modelClass) {
            foreach ($modelClass::query()->pluck('name', 'id') as $id => $title) {
                $options["{$alias}:{$id}"] = $title;
            }
        }

        return $options;
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

        $select = BackedEnumState::normalize(Select::make('currency'))
            ->label(__('shops-common::fields.currency'))
            ->required()
            ->live()
            ->dehydrated()
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
        $model = self::resolveOrderable($get('orderable_type'), $get('orderable_id'), $orderableModels);

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
    private static function orderableSelectFields(array $orderableModels, array $currencyOptions): array
    {
        return [
            Hidden::make('orderable_type'),
            Hidden::make('orderable_id'),

            Select::make('_orderable')
                ->label(__('shops-commerce::orders.fields.item'))
                ->options(fn (): array => self::buildOrderableOptions($orderableModels))
                ->searchable()
                ->preload()
                ->dehydrated(false)
                ->live()
                ->afterStateHydrated(function (Select $component, Get $get): void {
                    $type = $get('orderable_type');
                    $id = $get('orderable_id');

                    if ($type !== null && $id !== null) {
                        $component->state("{$type}:{$id}");
                    }
                })
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
        if ($state === null || ! str_contains($state, ':')) {
            $set('orderable_type', null);
            $set('orderable_id', null);

            return;
        }

        [$alias, $id] = explode(':', $state, 2);

        $model = self::resolveOrderable($alias, $id, $orderableModels);

        if ($model === null) {
            return;
        }

        $set('orderable_type', $alias);
        $set('orderable_id', (int) $id);

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
     * Resolve a HasOrders model from its morph alias + id.
     *
     * @param  array<string, class-string<HasOrders>>  $orderableModels
     */
    private static function resolveOrderable(int|string|null $alias, int|string|null $id, array $orderableModels): ?HasOrders
    {
        if ($alias === null || $id === null || ! isset($orderableModels[$alias])) {
            return null;
        }

        /** @var HasOrders|null $model */
        $model = $orderableModels[$alias]::query()->find((int) $id);

        return $model;
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
        $model = self::resolveOrderable($get('orderable_type'), $get('orderable_id'), $orderableModels);

        if ($model === null || $currencyCode === null || $currencyCode === '') {
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
