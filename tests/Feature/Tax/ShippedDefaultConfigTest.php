<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tax;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Location\Models\Address;
use InOtherShops\Tax\Actions\ResolveTaxRate;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pin the *shipped* `config/tax.php` defaults. Every other Tax test in this
 * suite overrides the relevant config keys in `defineEnvironment`, which
 * silently turns those tests into "tests of my override" rather than tests
 * of the package contract. A regression to the shipped country list, the
 * shipped `home_jurisdiction = null` semantics, or the shipped `export_rate`
 * would land invisibly without one test running on the untouched config.
 */
final class ShippedDefaultConfigTest extends TestCase
{
    use RefreshDatabase;

    private ResolveTaxRate $resolve;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve = new ResolveTaxRate;
    }

    #[Test]
    public function shipped_jurisdictions_lists_the_eu_with_27_member_states(): void
    {
        // Hard-pin the count and a representative sample so a typo'd member
        // state (or accidental Brexit-style removal) trips this test.
        $jurisdictions = config('tax.jurisdictions');

        $this->assertArrayHasKey('eu', $jurisdictions);
        $this->assertSame('European Union', $jurisdictions['eu']['name']);
        $this->assertCount(27, $jurisdictions['eu']['countries']);
        foreach (['DE', 'FR', 'NL', 'IT', 'ES', 'PL', 'GR'] as $country) {
            $this->assertContains($country, $jurisdictions['eu']['countries'],
                "EU jurisdiction must include {$country}.");
        }
    }

    #[Test]
    public function shipped_home_jurisdiction_is_null_so_any_unmapped_country_can_fall_back_to_default(): void
    {
        // The shipped semantics: with no home_jurisdiction set, a country
        // outside any TaxRate row falls back to the global `is_default` row
        // — not to the synthetic export rate. This is the consumer-friendly
        // default that doesn't assume a seller country.
        $this->assertNull(config('tax.home_jurisdiction'));

        TaxRate::factory()->forCountry('NL', 2100)->default()->create();

        $rate = ($this->resolve)($this->address('US'));

        $this->assertNotNull($rate);
        $this->assertSame('NL', $rate->country_code,
            'With shipped home_jurisdiction=null, an unmapped country must fall through to the default row.');
        $this->assertTrue($rate->is_default);
    }

    #[Test]
    public function shipped_export_rate_is_zero_rated(): void
    {
        $exportRate = config('tax.export_rate');

        $this->assertSame(0, $exportRate['rate_bps'],
            'Shipped export_rate must be zero-rated — EU export rule.');
        $this->assertSame('Zero-rated export', $exportRate['name']);
    }

    private function address(string $countryCode): Address
    {
        $address = new Address;
        $address->country_code = $countryCode;

        return $address;
    }
}
