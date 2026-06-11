<?php

declare(strict_types=1);

namespace InOtherShops\Support\Filament;

use Filament\Forms\Components\TextInput;
use InOtherShops\Currency\Support\DisplayLocale;
use InOtherShops\Currency\Support\MoneyFormatter;

/**
 * The package's single home for Filament money/percent state transforms:
 * integer cents <-> "10.00" decimal display, and basis points -> "21%"
 * labels. Every domain money field routes through here so the rounding,
 * separator, and null semantics can never drift apart again.
 *
 * GUARD RAIL — inputs stay ->numeric() (`<input type="number">`), and
 * formatCents/dehydrateCents stay period-hardcoded machine formats. The
 * browser localizes the *display* of a number input to the admin's own
 * settings (German machine shows 10,50) but always fills from and submits
 * period-normalized values — which is exactly what makes dehydrateCents's
 * float cast safe. Do NOT swap these for masked text inputs to control the
 * separator: a masked input in a comma-decimal locale submits the literal
 * "10,50", and (float) "10,50" === 10.0 — a silent 50-cent loss on every
 * admin save. If display control is ever truly needed, the mask must ship
 * together with a strict locale-aware parser (see the price format
 * consistency brief §3, which specs it in full). Locale-aware *display*
 * formatting belongs to Currency::format()/percentLabel(), never to input
 * state.
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
     * Basis points to a display label with trailing zeros stripped, in the
     * display locale's convention: 2100 -> "21%", 750 -> "7.5%" ('en') /
     * "7,5 %" ('de'). Trailing-zero stripping kept from the
     * package-tightening release (D2): tax-rate columns previously showed
     * "21.00%". Ambient resolution requires a booted app — pass $locale
     * explicitly in container-less contexts.
     */
    public static function percentLabel(int $bps, ?string $locale = null): string
    {
        return MoneyFormatter::formatPercent($locale ?? DisplayLocale::resolve(), $bps / 10000);
    }
}
