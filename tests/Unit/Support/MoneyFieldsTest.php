<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\Support;

use InOtherShops\Support\Filament\MoneyFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterization snapshots for the money/percent transforms. Expected
 * values are pinned from the pre-extraction inline closures (PricingSchema,
 * PurchasingSchema, CommerceSchema, OrderResource, VoucherResource,
 * TaxRateResource) — these tests existed BEFORE the call sites migrated,
 * so a transform that drifts from the legacy behavior fails here even
 * though no Filament test renders the field. The single deliberate change
 * is percentLabel's stripped-zeros format (brief D2).
 */
final class MoneyFieldsTest extends TestCase
{
    /** @return array<string, array{mixed, ?string}> */
    public static function formatCentsCases(): array
    {
        return [
            'zero' => [0, '0.00'],
            'one cent' => [1, '0.01'],
            'sub-euro' => [999, '9.99'],
            'large — no thousands separator' => [100000, '1000.00'],
            'null stays null' => [null, null],
            'numeric string from raw attribute' => ['1500', '15.00'],
        ];
    }

    #[Test]
    #[DataProvider('formatCentsCases')]
    public function format_cents_matches_legacy_closures(mixed $state, ?string $expected): void
    {
        $this->assertSame($expected, MoneyFields::formatCents($state));
    }

    /** @return array<string, array{mixed, ?int}> */
    public static function dehydrateNonNullableCases(): array
    {
        return [
            'decimal input' => ['10.00', 1000],
            'rounds half up' => ['10.005', 1001],
            'integer-ish input' => ['7', 700],
            'null coerces to zero' => [null, 0],
            'empty string coerces to zero' => ['', 0],
            'zero' => ['0', 0],
        ];
    }

    #[Test]
    #[DataProvider('dehydrateNonNullableCases')]
    public function dehydrate_cents_non_nullable_matches_legacy(mixed $state, ?int $expected): void
    {
        $this->assertSame($expected, MoneyFields::dehydrateCents($state));
    }

    /** @return array<string, array{mixed, ?int}> */
    public static function dehydrateNullableCases(): array
    {
        return [
            'decimal input' => ['12.34', 1234],
            'null stays null' => [null, null],
            'empty string stays null' => ['', null],
        ];
    }

    #[Test]
    #[DataProvider('dehydrateNullableCases')]
    public function dehydrate_cents_nullable_matches_legacy(mixed $state, ?int $expected): void
    {
        $this->assertSame($expected, MoneyFields::dehydrateCents($state, nullable: true));
    }

    #[Test]
    public function dehydrate_bps_matches_the_voucher_percentage_leg(): void
    {
        $this->assertSame(2100, MoneyFields::dehydrateBps('21'));
        $this->assertSame(1050, MoneyFields::dehydrateBps('10.5'));
        $this->assertSame(0, MoneyFields::dehydrateBps('0'));
    }

    /**
     * Locale passed explicitly: this suite runs without a container, and
     * percentLabel's ambient resolution (DisplayLocale) needs a booted app.
     * Ambient resolution is covered in Feature/Currency/CurrencyFormatLocaleTest.
     *
     * @return array<string, array{int, string}>
     */
    public static function percentLabelCases(): array
    {
        return [
            'whole percent strips decimals (D2: tax showed 21.00%)' => [2100, '21%'],
            'half percent keeps one decimal' => [750, '7.5%'],
            'two decimals kept when significant' => [1234, '12.34%'],
            'zero-rated' => [0, '0%'],
            'one hundred percent' => [10000, '100%'],
        ];
    }

    #[Test]
    #[DataProvider('percentLabelCases')]
    public function percent_label_strips_trailing_zeros(int $bps, string $expected): void
    {
        $this->assertSame($expected, MoneyFields::percentLabel($bps, locale: 'en'));
    }
}
