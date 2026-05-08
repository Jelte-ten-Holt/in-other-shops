<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tax;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Location\Models\Address;
use InOtherShops\Tax\Actions\ResolveTaxRate;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class JurisdictionAwareResolutionTest extends TestCase
{
    use RefreshDatabase;

    private ResolveTaxRate $resolve;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve = new ResolveTaxRate;

        config()->set('tax.jurisdictions', [
            'eu' => [
                'name' => 'European Union',
                'countries' => ['DE', 'FR', 'NL', 'IT'],
            ],
        ]);
        config()->set('tax.home_jurisdiction', 'eu');
        config()->set('tax.export_rate', [
            'rate_bps' => 0,
            'name' => 'Zero-rated export',
        ]);
    }

    #[Test]
    public function eu_country_with_no_explicit_row_falls_back_to_default(): void
    {
        TaxRate::factory()->forCountry('DE', 1900, 'Germany VAT 19%')->default()->create();

        $rate = ($this->resolve)($this->makeAddress('FR'));

        $this->assertNotNull($rate);
        $this->assertSame(1900, $rate->rate_bps);
        $this->assertSame('DE', $rate->country_code);
        $this->assertTrue($rate->is_default);
    }

    #[Test]
    public function eu_country_with_explicit_row_uses_that_row(): void
    {
        TaxRate::factory()->forCountry('DE', 1900)->default()->create();
        TaxRate::factory()->forCountry('FR', 2000, 'France VAT 20%')->create();

        $rate = ($this->resolve)($this->makeAddress('FR'));

        $this->assertNotNull($rate);
        $this->assertSame('FR', $rate->country_code);
        $this->assertSame(2000, $rate->rate_bps);
    }

    #[Test]
    public function non_eu_country_returns_zero_rated_export_rate(): void
    {
        TaxRate::factory()->forCountry('DE', 1900)->default()->create();

        $rate = ($this->resolve)($this->makeAddress('US'));

        $this->assertNotNull($rate);
        $this->assertSame(0, $rate->rate_bps);
        $this->assertSame('US', $rate->country_code);
        $this->assertSame('Zero-rated export', $rate->name);
        $this->assertFalse($rate->is_default);
    }

    #[Test]
    public function non_eu_country_ignores_default_row(): void
    {
        // Even when a default exists, a country outside the home jurisdiction
        // must not inherit the seller's home rate.
        TaxRate::factory()->forCountry('DE', 1900)->default()->create();

        $rate = ($this->resolve)($this->makeAddress('GB'));

        $this->assertNotNull($rate);
        $this->assertSame(0, $rate->rate_bps);
    }

    #[Test]
    public function non_eu_country_with_explicit_row_uses_that_row(): void
    {
        // A non-EU country with its own configured rate should still take
        // precedence — covers UK selling above £135 / Switzerland special
        // arrangements once those rows are added.
        TaxRate::factory()->forCountry('DE', 1900)->default()->create();
        TaxRate::factory()->forCountry('GB', 2000, 'UK VAT 20%')->create();

        $rate = ($this->resolve)($this->makeAddress('GB'));

        $this->assertNotNull($rate);
        $this->assertSame(2000, $rate->rate_bps);
        $this->assertSame('GB', $rate->country_code);
    }

    #[Test]
    public function null_home_jurisdiction_preserves_legacy_default_fallback(): void
    {
        config()->set('tax.home_jurisdiction', null);
        TaxRate::factory()->forCountry('NL', 2100)->default()->create();

        $rate = ($this->resolve)($this->makeAddress('US'));

        $this->assertNotNull($rate);
        $this->assertSame('NL', $rate->country_code);
        $this->assertTrue($rate->is_default);
    }

    #[Test]
    public function export_rate_can_be_overridden_via_config(): void
    {
        config()->set('tax.export_rate', [
            'rate_bps' => 100,
            'name' => 'Custom export rate',
        ]);
        TaxRate::factory()->forCountry('DE', 1900)->default()->create();

        $rate = ($this->resolve)($this->makeAddress('US'));

        $this->assertNotNull($rate);
        $this->assertSame(100, $rate->rate_bps);
        $this->assertSame('Custom export rate', $rate->name);
    }

    #[Test]
    public function jurisdiction_lookup_is_case_insensitive(): void
    {
        TaxRate::factory()->forCountry('DE', 1900)->default()->create();

        $rate = ($this->resolve)($this->makeAddress('fr'));

        $this->assertNotNull($rate);
        $this->assertSame(1900, $rate->rate_bps); // Falls back to home default, not export.
    }

    private function makeAddress(string $countryCode): Address
    {
        $address = new Address;
        $address->country_code = $countryCode;

        return $address;
    }
}
