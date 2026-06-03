<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use InOtherShops\Pricing\Actions\CalculateIncludedTax;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tax *contained* in a gross amount. Inputs are deliberately not
 * round-divisible — a 7% reduced rate, odd amounts, and a half-cent boundary —
 * so a rounding-mode regression can't hide behind evenly-dividing numbers.
 */
final class CalculateIncludedTaxTest extends TestCase
{
    private CalculateIncludedTax $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new CalculateIncludedTax;
    }

    #[Test]
    public function it_extracts_the_included_tax_at_the_standard_rate(): void
    {
        // €25.00 gross at 19%: net = round(25000000 / 11900) = 2101; tax = 399.
        $this->assertSame(399, ($this->calc)(2500, 1900));
    }

    #[Test]
    public function it_extracts_the_included_tax_at_the_reduced_rate_on_an_odd_amount(): void
    {
        // €9.99 gross at 7%: net = round(9990000 / 10700) = 934; tax = 65.
        $this->assertSame(65, ($this->calc)(999, 700));
    }

    #[Test]
    public function a_zero_rate_yields_zero_tax(): void
    {
        $this->assertSame(0, ($this->calc)(2500, 0));
    }

    #[Test]
    public function a_zero_amount_yields_zero_tax(): void
    {
        $this->assertSame(0, ($this->calc)(0, 1900));
    }

    #[Test]
    public function it_rounds_the_net_half_away_from_zero_at_the_boundary(): void
    {
        // gross 3 at 100%: net = round(30000 / 20000) = round(1.5) = 2; tax = 1.
        // Pins the rounding direction — a switch to floor/bankers' would return 0 or 2.
        $this->assertSame(1, ($this->calc)(3, 10000));
    }

    #[Test]
    public function gross_always_equals_net_plus_tax(): void
    {
        foreach ([[999, 700], [2500, 1900], [12345, 1900], [1, 1900], [7777, 700]] as [$gross, $rate]) {
            $tax = ($this->calc)($gross, $rate);
            $net = $gross - $tax;
            $this->assertSame($gross, $net + $tax, "gross {$gross} at {$rate}bps must reconcile");
        }
    }
}
