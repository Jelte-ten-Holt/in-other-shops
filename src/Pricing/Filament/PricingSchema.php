<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Filament;

use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Currency\Enums\Currency;

/**
 * Reusable Filament form fragments for the Pricing domain. Field factories are
 * the single source of truth — both {@see priceRepeater()} and the
 * PricesRelationManager compose from them, so money fields render in
 * euros/pounds (and store cents) identically everywhere.
 */
final class PricingSchema
{
    private const COMPARE_AT_TOOLTIP = "Only use a price this item was genuinely sold at recently. Inventing a higher 'original' price to fake a discount is illegal under EU pricing rules (Omnibus Directive).";

    public static function priceRepeater(string $relationship = 'prices'): Repeater
    {
        return Repeater::make($relationship)
            ->relationship()
            ->schema([
                self::currencySelect(),
                self::amountField(),
                self::compareAtAmountField(),
                self::compareAtUntilField(),
                self::priceListSelect(),
                self::minimumQuantityField(),
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

    /**
     * The actual selling price. Displayed in major units (euros/pounds),
     * stored as integer cents.
     */
    public static function amountField(): TextInput
    {
        return self::moneyField('amount')->required();
    }

    /**
     * The strikethrough ("compare-at") price. Carries the Omnibus-Directive
     * tooltip and Guard B — see {@see compareAtAmountRule()}.
     */
    public static function compareAtAmountField(): TextInput
    {
        return self::moneyField('compare_at_amount')
            ->label('Strikethrough price')
            ->hintIcon('heroicon-m-exclamation-triangle', tooltip: self::COMPARE_AT_TOOLTIP)
            ->rule(static fn (?Model $record): Closure => self::compareAtAmountRule($record));
    }

    /**
     * Guard B — Omnibus speed-bump. A strikethrough may only reference a price
     * the item was actually sold at, so on create (no price history) it is
     * blocked outright, and on edit it may not exceed the price already on
     * record. Heuristic, not an invariant: lives in the form layer, not on the
     * model. Returned as a standalone closure so it is unit-testable.
     */
    public static function compareAtAmountRule(?Model $record): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($value === null || $value === '') {
                return;
            }

            if ($record === null) {
                $fail("A strikethrough can't be set when first creating a price — there's no prior price the item was sold at. Save the price, then add a strikethrough.");

                return;
            }

            if ((int) round((float) $value * 100) > (int) $record->amount) {
                $fail('The strikethrough price cannot be higher than what this item is currently priced at. Use a price it was actually sold at before.');
            }
        };
    }

    /**
     * The instant the strikethrough window closes. When it passes, the
     * pricing:expire-compare-at command promotes compare_at_amount to the
     * actual price. Only meaningful while a strikethrough is set.
     */
    public static function compareAtUntilField(): DateTimePicker
    {
        return DateTimePicker::make('compare_at_until')
            ->label('Strikethrough ends')
            ->seconds(false)
            ->after('now')
            ->visible(fn (Get $get): bool => filled($get('compare_at_amount')))
            ->helperText('When this passes, the strikethrough price becomes the actual price and the strikethrough is cleared.');
    }

    public static function priceListSelect(): Select
    {
        return Select::make('price_list_id')
            ->relationship('priceList', 'name')
            ->searchable()
            ->preload();
    }

    public static function minimumQuantityField(): TextInput
    {
        return TextInput::make('minimum_quantity')
            ->numeric()
            ->default(1)
            ->minValue(1);
    }

    /**
     * A money input: shown in major units, dehydrated to integer cents.
     */
    private static function moneyField(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->step(0.01)
            ->minValue(0)
            ->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state / 100, 2, '.', '') : null)
            ->dehydrateStateUsing(fn ($state) => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null);
    }
}
