<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Location;

use InOtherShops\Location\Countries;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Countries ships no data — it reads ICU/CLDR through ext-intl. These tests
 * therefore pin the CONTRACT (resolution order, sorting, fallbacks) rather
 * than exact wording, except where a name is stable enough to be worth
 * asserting. ICU wording can shift between ICU versions; the override config
 * is the answer when it does, which is exactly why the override is tested.
 */
final class CountriesTest extends TestCase
{
    #[Test]
    public function it_names_a_country_in_the_requested_locale(): void
    {
        $this->assertSame('Germany', Countries::name('DE', 'en'));
        $this->assertSame('Alemania', Countries::name('DE', 'es'));
    }

    #[Test]
    public function it_falls_back_to_the_app_locale(): void
    {
        app()->setLocale('es');

        $this->assertSame('Alemania', Countries::name('DE'));
    }

    #[Test]
    public function it_accepts_a_lowercase_or_padded_code(): void
    {
        $this->assertSame('Germany', Countries::name('de', 'en'));
        $this->assertSame('Germany', Countries::name('  DE  ', 'en'));
    }

    /**
     * The reason the override map exists: a shop may disagree with ICU's
     * wording, and must be able to say so without a package release.
     */
    #[Test]
    public function a_consumer_override_wins_over_icu(): void
    {
        config(['location.country_names' => ['CZ' => ['es' => 'República Checa']]]);

        $this->assertSame('República Checa', Countries::name('CZ', 'es'));

        // Untouched locales still come from ICU.
        $this->assertSame('Czechia', Countries::name('CZ', 'en'));
    }

    #[Test]
    public function an_override_for_another_locale_does_not_leak(): void
    {
        config(['location.country_names' => ['DE' => ['fr' => 'Allemagne']]]);

        $this->assertSame('Germany', Countries::name('DE', 'en'));
    }

    #[Test]
    public function an_unresolvable_code_falls_back_to_itself(): void
    {
        // XX is unassigned — better a legible code than a blank option.
        $this->assertSame('XX', Countries::name('XX', 'en'));
    }

    #[Test]
    public function an_empty_code_is_empty(): void
    {
        $this->assertSame('', Countries::name('', 'en'));
        $this->assertSame('', Countries::name('   ', 'en'));
    }

    /**
     * Sorting on the localized NAME rather than the code is the whole reason to
     * have names — "AT, AU, BE, BG" reads as noise to a shopper.
     */
    #[Test]
    public function options_sort_by_localized_name_not_by_code(): void
    {
        $options = Countries::options(['NL', 'DE', 'FR'], 'es');

        $this->assertSame(
            ['Alemania', 'Francia', 'Países Bajos'],
            array_column($options, 'name'),
        );

        // The same three codes in the same input order, named in another
        // language, come back in a different order — because the sort follows
        // the name, not the code.
        $this->assertSame(
            ['France', 'Germany', 'Netherlands'],
            array_column(Countries::options(['NL', 'DE', 'FR'], 'en'), 'name'),
        );
    }

    /**
     * Without accent-aware collation Spanish sorts every accented name away
     * from its unaccented neighbours — "Bélgica" after "Bulgaria", not before.
     */
    #[Test]
    public function options_sort_accented_names_in_their_natural_place(): void
    {
        $names = array_column(Countries::options(['BG', 'BE', 'AT'], 'es'), 'name');

        $this->assertSame(['Austria', 'Bélgica', 'Bulgaria'], $names);
    }

    #[Test]
    public function options_carry_the_code_alongside_the_name(): void
    {
        $options = Countries::options(['DE'], 'en');

        $this->assertSame([['code' => 'DE', 'name' => 'Germany']], $options);
    }

    #[Test]
    public function options_normalize_and_deduplicate_codes(): void
    {
        $options = Countries::options(['de', 'DE', ' de ', '', 'FR'], 'en');

        // France before Germany: the output is name-sorted, not input-ordered.
        $this->assertSame(['FR', 'DE'], array_column($options, 'code'));
    }

    #[Test]
    public function options_of_nothing_is_an_empty_list(): void
    {
        $this->assertSame([], Countries::options([]));
    }

    /**
     * The package must not presume any shop's destinations — which countries
     * are offered belongs to the consumer, this class only names them.
     */
    #[Test]
    public function the_shipped_override_map_is_empty(): void
    {
        $this->assertSame([], config('location.country_names'));
    }
}
