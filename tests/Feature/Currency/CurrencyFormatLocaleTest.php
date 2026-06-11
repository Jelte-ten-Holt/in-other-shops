<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Currency;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Currency\Support\DisplayLocale;
use InOtherShops\Support\Filament\MoneyFields;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The locale-aware money display contract: Currency::format() follows
 * the display-locale resolution chain (explicit arg > DisplayLocale
 * override > currency.display_locale config > app locale), and CLDR
 * conventions cover symbol placement, not just separators.
 *
 * ICU renders the gap between amount and symbol with a no-break space
 * (U+00A0, or U+202F on newer ICU) — normalize() folds both to a plain
 * space so assertions don't break on an ICU upgrade.
 */
final class CurrencyFormatLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        DisplayLocale::clear();

        parent::tearDown();
    }

    private function normalize(string $formatted): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $formatted);
    }

    #[Test]
    public function english_app_locale_formats_symbol_first_with_period(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('€12.50', Currency::EUR->format(1250));
        $this->assertSame('$12.50', Currency::USD->format(1250));
        $this->assertSame('£12.50', Currency::GBP->format(1250));
        $this->assertSame('€1,234.50', Currency::EUR->format(123450));
    }

    #[Test]
    public function german_app_locale_formats_comma_decimal_symbol_after(): void
    {
        $this->app->setLocale('de');

        $this->assertSame('12,50 €', $this->normalize(Currency::EUR->format(1250)));
        $this->assertSame('1.234,50 €', $this->normalize(Currency::EUR->format(123450)));
    }

    #[Test]
    public function spanish_app_locale_formats_comma_decimal_symbol_after(): void
    {
        $this->app->setLocale('es');

        $this->assertSame('12,50 €', $this->normalize(Currency::EUR->format(1250)));
    }

    #[Test]
    public function explicit_locale_argument_beats_everything(): void
    {
        $this->app->setLocale('en');
        config(['currency.display_locale' => 'en']);
        DisplayLocale::set('en');

        $this->assertSame('12,50 €', $this->normalize(Currency::EUR->format(1250, 'de')));
    }

    #[Test]
    public function display_locale_override_beats_config_and_app_locale(): void
    {
        $this->app->setLocale('en');
        config(['currency.display_locale' => 'en']);
        DisplayLocale::set('de');

        $this->assertSame('12,50 €', $this->normalize(Currency::EUR->format(1250)));
    }

    #[Test]
    public function config_display_locale_beats_app_locale(): void
    {
        $this->app->setLocale('de');
        config(['currency.display_locale' => 'en']);

        $this->assertSame('€12.50', Currency::EUR->format(1250));
    }

    #[Test]
    public function shipped_config_default_is_null_following_the_app_locale(): void
    {
        // array_key_exists separates "ships null" from "provider stopped
        // merging the key" — same discipline as EnabledCurrenciesConfigTest.
        $this->assertTrue(array_key_exists('display_locale', config('currency')));
        $this->assertNull(config('currency.display_locale'));
    }

    #[Test]
    public function percent_label_follows_the_display_locale_ambiently(): void
    {
        $this->app->setLocale('de');

        $this->assertSame('7,5 %', $this->normalize(MoneyFields::percentLabel(750)));
        $this->assertSame('21 %', $this->normalize(MoneyFields::percentLabel(2100)));
    }
}
