<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Forms\Components\TextInput;

/**
 * The package's single home for Filament money/percent state transforms:
 * integer cents <-> "10.00" decimal display, and basis points -> "21%"
 * labels. Every domain money field routes through here so the rounding,
 * separator, and null semantics can never drift apart again.
 *
 * Two explicit knobs, both tracking column semantics at the call site:
 *  - `nullable`: empty input dehydrates to null (nullable columns:
 *    Price.compare_at_amount, PurchaseOrderLine.input_vat) instead of 0
 *    (non-nullable money columns).
 *  - `zeroWhenEmpty`: a null state displays as '0.00' and the field
 *    defaults to '0.00' (the Order scalar money fields' convention).
 *
 * Documented limitation: hardcodes 2-decimal currencies. Every enabled
 * currency (EUR/USD/GBP) is 2-decimal; if a 0- or 3-decimal currency ever
 * lands, these helpers grow a Currency parameter and use
 * Currency::decimals() — see the package-tightening brief, WI-5.
 */
final class MoneyFields
{
    public static function moneyInput(string $name, bool $nullable = false, bool $zeroWhenEmpty = false): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->default($zeroWhenEmpty ? '0.00' : null)
            ->formatStateUsing(fn ($state) => self::formatCents($state) ?? ($zeroWhenEmpty ? '0.00' : null))
            ->dehydrateStateUsing(fn ($state) => self::dehydrateCents($state, $nullable));
    }

    /** Integer cents (or null) to a 2-decimal display string. */
    public static function formatCents(mixed $state): ?string
    {
        return $state !== null
            ? number_format((int) $state / 100, 2, '.', '')
            : null;
    }

    /** Decimal display input back to integer cents. */
    public static function dehydrateCents(mixed $state, bool $nullable = false): ?int
    {
        if ($nullable && ($state === null || $state === '')) {
            return null;
        }

        return $state !== null ? (int) round((float) $state * 100) : 0;
    }

    /** Admin-facing percentage ("10.5") to stored basis points (1050). */
    public static function dehydrateBps(mixed $state): int
    {
        return (int) round((float) $state * 100);
    }

    /**
     * Basis points to a display label with trailing zeros stripped:
     * 2100 -> "21%", 750 -> "7.5%". The one normalization the
     * package-tightening release deliberately changed (D2): tax-rate
     * columns previously showed "21.00%".
     */
    public static function percentLabel(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
    }
}
