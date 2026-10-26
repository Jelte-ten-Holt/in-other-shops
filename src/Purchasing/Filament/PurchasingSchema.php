<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Filament;

use InOtherShops\Purchasing\Contracts\HasPurchases;
use InOtherShops\Support\Filament\ClaimedDay;
use InOtherShops\Support\Filament\MoneyFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Closure;
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
            ->addActionLabel(__('shops-purchasing::purchaseline.add_line'));
    }

    /**
     * @return array<Component>
     */
    public static function purchaseLineFields(): array
    {
        return [
            ...self::purchasableSelectFields(),
            // Optional: a line backed by a selected product snapshots its
            // description automatically (see handlePurchasableSelected). Only a
            // line with neither a product nor typed text is rejected — by the
            // CreatePurchaseOrder action, which is the real invariant.
            Textarea::make('description')
                ->label(__('shops-common::fields.description'))
                ->rows(2)
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('sku')
                ->label(__('shops-common::fields.sku'))
                ->maxLength(255),
            TextInput::make('quantity_ordered')
                ->label(__('shops-purchasing::purchaseline.quantity_ordered'))
                ->required()
                ->numeric()
                ->integer()
                ->minValue(1)
                ->default(1),
            MoneyFields::moneyInput('unit_cost')
                ->label(__('shops-purchasing::purchaseline.unit_cost'))
                ->required(),
            // nullable: input_vat is a nullable column — blank means "not
            // recorded", not zero VAT.
            MoneyFields::moneyInput('input_vat', nullable: true)
                ->label(__('shops-purchasing::purchaseline.input_vat'))
                ->nullable(),
        ];
    }

    /**
     * The supplier's invoice as printed on it: number, date, gross total and
     * VAT per rate. The model refuses half an invoice and a total its rates do
     * not add up to; the form says so first, field by field. An invoice without
     * VAT (reverse charge, a small business) is one row at 0 %.
     *
     * @return array<Component>
     */
    public static function supplierInvoiceFields(): array
    {
        return [
            TextInput::make('supplier_invoice_number')
                ->label(__('shops-purchasing::purchaseorder.fields.supplier_invoice_number'))
                ->maxLength(255),
            DatePicker::make('supplier_invoice_date')
                ->label(__('shops-purchasing::purchaseorder.fields.supplier_invoice_date'))
                ->requiredWith('supplier_invoice_number'),
            MoneyFields::moneyInput('supplier_invoice_total', nullable: true)
                ->label(__('shops-purchasing::purchaseorder.fields.supplier_invoice_total'))
                ->helperText(__('shops-purchasing::purchaseorder.fields.supplier_invoice_total_help'))
                ->requiredWith('supplier_invoice_number')
                ->rule(fn (Get $get): Closure => self::totalMatchesRates($get)),
            Repeater::make('supplier_invoice_tax_summary')
                ->label(__('shops-purchasing::purchaseorder.fields.supplier_invoice_tax_summary'))
                ->helperText(__('shops-purchasing::purchaseorder.fields.supplier_invoice_tax_summary_help'))
                ->requiredWith('supplier_invoice_number')
                ->schema([
                    TextInput::make('rate_bps')
                        ->label(__('shops-purchasing::purchaseorder.fields.vat_rate'))
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->suffix('%')
                        ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : (string) ((int) $state / 100))
                        ->dehydrateStateUsing(fn (mixed $state): int => MoneyFields::dehydrateBps($state)),
                    MoneyFields::moneyInput('taxable_base')
                        ->label(__('shops-purchasing::purchaseorder.fields.net'))
                        ->required(),
                    MoneyFields::moneyInput('tax')
                        ->label(__('shops-purchasing::purchaseorder.fields.vat'))
                        ->required(),
                ])
                ->columns(3)
                ->defaultItems(0)
                ->addActionLabel(__('shops-purchasing::purchaseorder.actions.add_rate'))
                ->columnSpanFull(),
        ];
    }

    /**
     * The gross total typed from the invoice must be the net plus VAT of the
     * rates below it — the same check the model makes on save, as a field error.
     */
    private static function totalMatchesRates(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $rows = $get('supplier_invoice_tax_summary') ?? [];

            if ($value === null || $value === '' || $rows === []) {
                return;
            }

            $summed = 0;

            foreach ($rows as $row) {
                $summed += MoneyFields::dehydrateCents($row['taxable_base'] ?? 0) + MoneyFields::dehydrateCents($row['tax'] ?? 0);
            }

            if (MoneyFields::dehydrateCents($value) !== $summed) {
                $fail(__('shops-purchasing::purchaseorder.fields.supplier_invoice_total_mismatch', [
                    'summed' => MoneyFields::formatCents($summed),
                ]));
            }
        };
    }

    /**
     * Our payment of the invoice: the day it was paid and the amount, together.
     *
     * @return array<Component>
     */
    public static function supplierPaymentFields(): array
    {
        return [
            ClaimedDay::picker('paid_at')
                ->label(__('shops-purchasing::purchaseorder.fields.paid_on'))
                ->requiredWith('amount_paid'),
            MoneyFields::moneyInput('amount_paid', nullable: true)
                ->label(__('shops-purchasing::purchaseorder.fields.amount_paid'))
                ->requiredWith('paid_at'),
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
                ->label(__('shops-purchasing::purchaseline.product'))
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
