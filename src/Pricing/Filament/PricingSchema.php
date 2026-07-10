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
use InOtherShops\Support\Filament\MoneyFields;

/**
 * Reusable Filament form fragments for the Pricing domain. Field factories are
 * the single source of truth — both {@see priceRepeater()} and the
 * PricesRelationManager compose from them, so money fields render in
 * euros/pounds (and store cents) identically everywhere.
 */
final class PricingSchema
{
    public static function priceRepeater(string $relationship = 'prices'): Repeater
    {
        return Repeater::make($relationship)
            ->relationship()
            ->schema([
                self::currencySelect(),
                self::amountField(),
                self::compareAtAmountField(),
                self::compareAtUntilField(),
                self::minimumQuantityField(),
            ])
            ->columns(2);
    }

    public static function currencySelect(string $name = 'currency'): Select
    {
        $enabled = Currency::enabled();

        $select = Select::make($name)
            ->label(__('shops-common::fields.currency'))
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
        return self::moneyField('amount')
            ->label(__('shops-pricing::price.amount'))
            ->required();
    }

    /**
     * The strikethrough ("compare-at") price. Carries the Omnibus-Directive
     * tooltip and Guard B — see {@see compareAtAmountRule()}.
     */
    public static function compareAtAmountField(): TextInput
    {
        return self::moneyField('compare_at_amount')
            ->label(__('shops-pricing::price.compare_at_amount'))
            ->hintIcon('heroicon-m-exclamation-triangle', tooltip: __('shops-pricing::price.compare_at_tooltip'))
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
                $fail(__('shops-pricing::price.compare_at_create_blocked'));

                return;
            }

            if ((int) round((float) $value * 100) > (int) $record->amount) {
                $fail(__('shops-pricing::price.compare_at_too_high'));
            }
        };
    }

    /**
     * The instant the strikethrough window closes. When it passes, the
     * pricing:expire-compare-at command promotes compare_at_amount to the
     * actual price. Only meaningful while a strikethrough is set.
     *
     * The picker is pinned to the app timezone explicitly (audit A-1): the
     * column stores naive timestamps and the expiry command compares against
     * `now()`, both in `config('app.timezone')`. Pinning the picker to the same
     * zone keeps the admin's wall-clock entry, the stored value, and the
     * scheduler consistent — and documents that the consumer should set
     * `app.timezone` to the shop's operating zone (e.g. Europe/Berlin) so the
     * admin isn't entering UTC by accident. Leaving it implicit invites a
     * silent one-to-two-hour drift on the strikethrough end time.
     */
    public static function compareAtUntilField(): DateTimePicker
    {
        return DateTimePicker::make('compare_at_until')
            ->label(__('shops-pricing::price.compare_at_until'))
            ->seconds(false)
            ->after('now')
            ->timezone(config('app.timezone'))
            ->visible(fn (Get $get): bool => filled($get('compare_at_amount')))
            ->helperText(__('shops-pricing::price.compare_at_until_help', ['timezone' => config('app.timezone')]));
    }

    /**
     * Not part of the default schemas (priceRepeater / PricesRelationManager):
     * price lists have a full backend (PriceList model, ResolvePrice fallback)
     * but no admin resource to manage them, so exposing the select only offers
     * a footgun — a price assigned to a non-default list is invisible to the
     * storefront, which resolves the is_default list with a list-less
     * fallback. Compose this in deliberately when price lists become a real
     * admin-managed feature (which also needs a PriceListResource).
     */
    public static function priceListSelect(): Select
    {
        return Select::make('price_list_id')
            ->label(__('shops-pricing::price.price_list'))
            ->relationship('priceList', 'name')
            ->searchable()
            ->preload();
    }

    public static function minimumQuantityField(): TextInput
    {
        return TextInput::make('minimum_quantity')
            ->label(__('shops-pricing::price.minimum_quantity'))
            ->numeric()
            ->default(1)
            ->minValue(1);
    }

    /**
     * A money input: shown in major units, dehydrated to integer cents.
     */
    private static function moneyField(string $name): TextInput
    {
        // nullable: compare_at_amount is a nullable column — clearing the
        // input must store NULL, not 0.
        return MoneyFields::moneyInput($name, nullable: true)
            ->step(0.01);
    }
}
