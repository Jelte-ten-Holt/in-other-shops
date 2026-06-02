<?php

declare(strict_types=1);

namespace InOtherShops\Variants\Filament;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Inventory\Actions\AdjustStock;
use InOtherShops\Inventory\Enums\StockMovementReason;
use InOtherShops\Variants\Actions\DeleteVariant;
use InOtherShops\Variants\Contracts\HasVariants;
use InOtherShops\Variants\Models\Variant;
use InOtherShops\Variants\Variants;

/**
 * Reusable Filament fragment a consumer attaches to its variant-owning resource
 * (e.g. a Product form). Declares the owner's axes and edits existing variants'
 * SKU / price / stock via the manual-sync convention (`fillFormData` /
 * `saveFormData`).
 *
 * Out of scope here (built consumer-side against the real product form):
 * generating new variant combinations (call the `GenerateVariants` action from
 * a Filament Action) and the owner-price cascade modal. The price field below
 * edits the configured default currency only — manage the full multi-currency
 * matrix elsewhere if needed.
 */
final class VariantsSchema
{
    public static function axesField(): Select
    {
        return Select::make('_variant_options')
            ->label('Varies by')
            ->multiple()
            ->options(fn (): array => Variants::option()::query()->get()
                ->mapWithKeys(fn (Model $option): array => [$option->id => $option->translated('name') ?? $option->slug])
                ->all())
            ->helperText('The attributes this product varies by. Add values to each option in the Options catalog.')
            ->preload();
    }

    public static function variantsRepeater(): Repeater
    {
        return Repeater::make('_variants')
            ->label('Variants')
            ->addable(false)
            ->orderColumn('position')
            ->itemLabel(fn (array $state): ?string => $state['summary'] ?? null)
            ->schema([
                Hidden::make('id'),
                Hidden::make('summary'),
                TextInput::make('sku')->label('SKU')->maxLength(255),
                TextInput::make('price')
                    ->label('Price ('.self::editingCurrency()->value.')')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Smallest currency subunit (cents).'),
                TextInput::make('stock')->label('Stock')->numeric(),
            ])
            ->columns(3)
            ->defaultItems(0);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFormData(Model&HasVariants $record, array $data): array
    {
        $currency = self::editingCurrency();

        $data['_variant_options'] = $record->options()->pluck('options.id')->all();

        $data['_variants'] = $record->variants()->with('optionValues.option')->get()
            ->map(fn (Variant $variant): array => [
                'id' => $variant->id,
                'summary' => $variant->optionSummary(),
                'sku' => $variant->sku,
                'price' => $variant->priceFor($currency)?->amount,
                'stock' => $variant->stockLevel(),
            ])
            ->all();

        return $data;
    }

    /**
     * Sync declared axes and persist per-variant SKU / price / stock edits;
     * variants removed from the repeater are deleted (guard-protected).
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveFormData(Model&HasVariants $record, array $data): void
    {
        self::syncAxes($record, $data['_variant_options'] ?? []);
        self::persistVariantRows($record, $data['_variants'] ?? []);
    }

    /** @param array<int> $optionIds */
    private static function syncAxes(Model&HasVariants $record, array $optionIds): void
    {
        $pivot = [];
        foreach (array_values($optionIds) as $position => $optionId) {
            $pivot[$optionId] = ['position' => $position];
        }

        $record->options()->sync($pivot);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function persistVariantRows(Model&HasVariants $record, array $rows): void
    {
        $keptIds = [];

        foreach (array_values($rows) as $position => $row) {
            if (empty($row['id'])) {
                continue;
            }

            $variant = $record->variants()->find($row['id']);

            if ($variant === null) {
                continue;
            }

            $variant->update(['sku' => $row['sku'] ?? null, 'position' => $position]);
            self::applyPrice($variant, $row['price'] ?? null);
            self::applyStock($variant, $row['stock'] ?? null);
            $keptIds[] = $variant->id;
        }

        $record->variants()->whereNotIn('id', $keptIds)->get()
            ->each(fn (Variant $variant) => app(DeleteVariant::class)($variant));
    }

    private static function applyPrice(Variant $variant, ?int $amount): void
    {
        if ($amount === null) {
            return;
        }

        $currency = self::editingCurrency();
        $existing = $variant->priceFor($currency);

        if ($existing !== null) {
            $existing->update(['amount' => $amount]);

            return;
        }

        $variant->prices()->create([
            'amount' => $amount,
            'currency' => $currency->value,
            'minimum_quantity' => 1,
        ]);
    }

    private static function applyStock(Variant $variant, ?int $level): void
    {
        if ($level === null) {
            return;
        }

        $delta = $level - $variant->stockLevel();

        if ($delta === 0) {
            return;
        }

        app(AdjustStock::class)($variant, $delta, StockMovementReason::Adjusted, 'Admin variant stock edit');
    }

    private static function editingCurrency(): Currency
    {
        return Currency::from(config('commerce.cart.api.default_currency', 'EUR'));
    }
}
