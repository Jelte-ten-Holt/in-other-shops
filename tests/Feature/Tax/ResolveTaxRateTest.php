<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Tax;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Location\Models\Address;
use InOtherShops\Tax\Actions\ResolveTaxRate;
use InOtherShops\Tax\Models\TaxRate;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ResolveTaxRateTest extends TestCase
{
    use RefreshDatabase;

    private ResolveTaxRate $resolve;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolve = new ResolveTaxRate;
    }

    #[Test]
    public function it_resolves_by_country_code(): void
    {
        TaxRate::factory()->forCountry('NL', 2100, 'Netherlands VAT')->create();
        TaxRate::factory()->forCountry('DE', 1900, 'Germany VAT')->create();

        $address = $this->makeAddress('NL');

        $rate = ($this->resolve)($address);

        $this->assertNotNull($rate);
        $this->assertSame('NL', $rate->country_code);
        $this->assertSame(2100, $rate->rate_bps);
    }

    #[Test]
    public function it_matches_country_code_case_insensitively(): void
    {
        TaxRate::factory()->forCountry('FR', 2000)->create();

        $address = $this->makeAddress('fr');

        $rate = ($this->resolve)($address);

        $this->assertNotNull($rate);
        $this->assertSame('FR', $rate->country_code);
    }

    #[Test]
    public function it_falls_back_to_default_when_no_country_match(): void
    {
        TaxRate::factory()->forCountry('NL', 2100)->default()->create();

        $address = $this->makeAddress('US');

        $rate = ($this->resolve)($address);

        $this->assertNotNull($rate);
        $this->assertSame('NL', $rate->country_code);
        $this->assertTrue($rate->is_default);
    }

    #[Test]
    public function it_prefers_country_match_over_default(): void
    {
        TaxRate::factory()->forCountry('NL', 2100)->default()->create();
        TaxRate::factory()->forCountry('DE', 1900)->create();

        $address = $this->makeAddress('DE');

        $rate = ($this->resolve)($address);

        $this->assertNotNull($rate);
        $this->assertSame('DE', $rate->country_code);
    }

    #[Test]
    public function it_returns_null_when_no_match_and_no_default(): void
    {
        TaxRate::factory()->forCountry('NL', 2100)->create();

        $address = $this->makeAddress('US');

        $this->assertNull(($this->resolve)($address));
    }

    private function makeAddress(string $countryCode): Address
    {
        $address = new Address;
        $address->country_code = $countryCode;

        return $address;
    }
}
