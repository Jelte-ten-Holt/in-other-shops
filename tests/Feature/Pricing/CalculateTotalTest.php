<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CalculateTotal;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\Enums\TaxMode;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Composes ResolvePrice + CalculateVoucherDiscount + per-bracket included tax.
 * Stored prices are gross (tax-inclusive); tax is the contained portion, derived
 * per rate bracket, never added on top. Items carry their own resolved taxRateBps.
 */
final class CalculateTotalTest extends TestCase
{
    use RefreshDatabase;

    private CalculateTotal $calculate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculate = $this->app->make(CalculateTotal::class);
    }

    #[Test]
    public function it_returns_subtotal_as_total_when_no_tax_voucher_or_shipping(): void
    {
        $widget = $this->priceable(amount: 2500);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
        );

        $this->assertSame(2500, $breakdown->subtotal);
        $this->assertSame(0, $breakdown->discount);
        $this->assertSame(0, $breakdown->tax);
        $this->assertSame(0, $breakdown->shippingCost);
        $this->assertSame(2500, $breakdown->total);
        $this->assertNull($breakdown->voucherCode);
    }

    #[Test]
    public function subtotal_sums_unit_price_times_quantity_across_non_uniform_lines(): void
    {
        $cheap = $this->priceable(amount: 1234);
        $mid = $this->priceable(amount: 2345);
        $pricey = $this->priceable(amount: 3456);

        $breakdown = ($this->calculate)(
            items: [
                ['item' => $cheap, 'quantity' => 3, 'description' => 'Cheap'],
                ['item' => $mid, 'quantity' => 2, 'description' => 'Mid'],
                ['item' => $pricey, 'quantity' => 1, 'description' => 'Pricey'],
            ],
            currency: Currency::EUR,
        );

        // 1234*3 + 2345*2 + 3456*1 = 3702 + 4690 + 3456 = 11848
        $this->assertSame(11848, $breakdown->subtotal);
        $this->assertCount(3, $breakdown->lines);
        $this->assertSame(3702, $breakdown->lines[0]->lineTotal);
        $this->assertSame(4690, $breakdown->lines[1]->lineTotal);
        $this->assertSame(3456, $breakdown->lines[2]->lineTotal);
    }

    #[Test]
    public function each_line_carries_the_description_passed_in(): void
    {
        $a = $this->priceable(amount: 100);
        $b = $this->priceable(amount: 200);

        $breakdown = ($this->calculate)(
            items: [
                ['item' => $a, 'quantity' => 1, 'description' => 'Apples'],
                ['item' => $b, 'quantity' => 1, 'description' => 'Bananas'],
            ],
            currency: Currency::EUR,
        );

        $this->assertSame('Apples', $breakdown->lines[0]->description);
        $this->assertSame('Bananas', $breakdown->lines[1]->description);
    }

    #[Test]
    public function missing_price_resolves_to_unit_price_zero_rather_than_throwing(): void
    {
        $orphan = TestPriceable::factory()->create();

        $breakdown = ($this->calculate)(
            items: [['item' => $orphan, 'quantity' => 5, 'description' => 'Orphan', 'taxRateBps' => 1900]],
            currency: Currency::EUR,
        );

        $this->assertSame(0, $breakdown->subtotal);
        $this->assertSame(0, $breakdown->tax);
        $this->assertSame(0, $breakdown->total);
        $this->assertSame([], $breakdown->taxBreakdown);
        $this->assertSame(0, $breakdown->lines[0]->unitPrice);
        $this->assertSame(5, $breakdown->lines[0]->quantity);
    }

    #[Test]
    public function shipping_cost_is_added_to_the_total_but_not_to_subtotal(): void
    {
        $widget = $this->priceable(amount: 1000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            shippingCost: 595,
        );

        $this->assertSame(1000, $breakdown->subtotal,
            'Subtotal must not include shipping — shipping is a separate column.');
        $this->assertSame(595, $breakdown->shippingCost);
        $this->assertSame(1595, $breakdown->total);
    }

    #[Test]
    public function included_tax_is_derived_from_the_discounted_gross_not_added_on_top(): void
    {
        // Gross €100.00, half-off voucher, 20%. The discounted gross is €50.00;
        // the tax is the portion *inside* that gross, not 20% added on top.
        Voucher::factory()->create(['code' => 'HALF', 'amount' => 5000, 'currency' => Currency::EUR]);
        $widget = $this->priceable(amount: 10000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget', 'taxRateBps' => 2000]],
            currency: Currency::EUR,
            voucherCode: 'HALF',
        );

        // discountedGross 5000; net = round(50000000/12000) = 4167; tax = 833.
        $this->assertSame(10000, $breakdown->subtotal);
        $this->assertSame(5000, $breakdown->discount);
        $this->assertSame(833, $breakdown->tax);
        // total = gross subtotal − discount + shipping; tax is a component of the gross, never added.
        $this->assertSame(5000, $breakdown->total);
        $this->assertCount(1, $breakdown->taxBreakdown);
        $this->assertSame(2000, $breakdown->taxBreakdown[0]->rateBps);
        $this->assertSame(4167, $breakdown->taxBreakdown[0]->taxableBase);
        $this->assertSame(833, $breakdown->taxBreakdown[0]->tax);
    }

    #[Test]
    public function a_full_voucher_drives_included_tax_to_zero(): void
    {
        Voucher::factory()->create(['code' => 'FULL', 'amount' => 10000, 'currency' => Currency::EUR]);
        $widget = $this->priceable(amount: 10000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget', 'taxRateBps' => 2000]],
            currency: Currency::EUR,
            voucherCode: 'FULL',
        );

        $this->assertSame(0, $breakdown->tax);
        $this->assertSame(0, $breakdown->total);
    }

    #[Test]
    public function a_mixed_rate_cart_taxes_each_bracket_at_its_own_rate(): void
    {
        // A 7% book next to a 19% die — the exact case single-rate carts got wrong.
        $book = $this->priceable(amount: 1000);
        $die = $this->priceable(amount: 2000);

        $breakdown = ($this->calculate)(
            items: [
                ['item' => $book, 'quantity' => 1, 'description' => 'Book', 'taxRateBps' => 700],
                ['item' => $die, 'quantity' => 1, 'description' => 'Die', 'taxRateBps' => 1900],
            ],
            currency: Currency::EUR,
        );

        // 7% of 1000 gross: net round(10000000/10700)=935, tax 65.
        // 19% of 2000 gross: net round(20000000/11900)=1681, tax 319.
        $this->assertSame(3000, $breakdown->subtotal);
        $this->assertSame(384, $breakdown->tax);
        $this->assertCount(2, $breakdown->taxBreakdown);

        // Brackets are ordered by rate ascending.
        $this->assertSame(700, $breakdown->taxBreakdown[0]->rateBps);
        $this->assertSame(935, $breakdown->taxBreakdown[0]->taxableBase);
        $this->assertSame(65, $breakdown->taxBreakdown[0]->tax);

        $this->assertSame(1900, $breakdown->taxBreakdown[1]->rateBps);
        $this->assertSame(1681, $breakdown->taxBreakdown[1]->taxableBase);
        $this->assertSame(319, $breakdown->taxBreakdown[1]->tax);
    }

    #[Test]
    public function each_bracket_reconciles_base_plus_tax_to_its_discounted_gross(): void
    {
        $book = $this->priceable(amount: 1000);
        $die = $this->priceable(amount: 2000);

        $breakdown = ($this->calculate)(
            items: [
                ['item' => $book, 'quantity' => 1, 'description' => 'Book', 'taxRateBps' => 700],
                ['item' => $die, 'quantity' => 1, 'description' => 'Die', 'taxRateBps' => 1900],
            ],
            currency: Currency::EUR,
        );

        $reconstructed = 0;
        foreach ($breakdown->taxBreakdown as $bracket) {
            $reconstructed += $bracket->taxableBase + $bracket->tax;
        }

        // Sum of (net + tax) across brackets == gross subtotal − discount.
        $this->assertSame($breakdown->subtotal - $breakdown->discount, $reconstructed);
        $this->assertSame(
            array_sum(array_map(fn ($b) => $b->tax, $breakdown->taxBreakdown)),
            $breakdown->tax,
        );
    }

    #[Test]
    public function shipping_carries_the_vat_of_a_single_rate_cart(): void
    {
        // G4: shipping is a taxable supply. On a single-rate cart it takes that
        // rate — the VAT inside the gross postage is extracted, not dropped.
        $widget = $this->priceable(amount: 10000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget', 'taxRateBps' => 1900]],
            currency: Currency::EUR,
            shippingCost: 500,
        );

        // Bracket gross = 10000 goods + 500 shipping = 10500 (inclusive).
        // net = round(105000000/11900) = 8824; tax = 1676.
        // total unchanged at 10000 + 500 = 10500 — only the tax split moves.
        $this->assertSame(10000, $breakdown->subtotal);
        $this->assertSame(500, $breakdown->shippingCost);
        $this->assertSame(1676, $breakdown->tax);
        $this->assertSame(10500, $breakdown->total);
        $this->assertCount(1, $breakdown->taxBreakdown);
        $this->assertSame(8824, $breakdown->taxBreakdown[0]->taxableBase);
        $this->assertSame(1676, $breakdown->taxBreakdown[0]->tax);
    }

    #[Test]
    public function shipping_vat_is_apportioned_across_brackets_by_gross_share(): void
    {
        // G4 + mixed rate: shipping follows the goods' rate mix (EU ancillary
        // rule). 595c of shipping over a 1000c (7%) + 2000c (19%) cart splits by
        // gross — 1000:2000 — to 198 : 397, with the rounding cent going to the
        // larger remainder (the 19% bracket).
        $book = $this->priceable(amount: 1000);
        $die = $this->priceable(amount: 2000);

        $breakdown = ($this->calculate)(
            items: [
                ['item' => $book, 'quantity' => 1, 'description' => 'Book', 'taxRateBps' => 700],
                ['item' => $die, 'quantity' => 1, 'description' => 'Die', 'taxRateBps' => 1900],
            ],
            currency: Currency::EUR,
            shippingCost: 595,
        );

        // 7%: gross 1000 + ship 198 = 1198 → net round(11980000/10700)=1120, tax 78.
        // 19%: gross 2000 + ship 397 = 2397 → net round(23970000/11900)=2014, tax 383.
        $this->assertSame(3000, $breakdown->subtotal);
        $this->assertSame(595, $breakdown->shippingCost);
        $this->assertSame(3595, $breakdown->total);
        $this->assertSame(461, $breakdown->tax);

        $this->assertSame(700, $breakdown->taxBreakdown[0]->rateBps);
        $this->assertSame(1120, $breakdown->taxBreakdown[0]->taxableBase);
        $this->assertSame(78, $breakdown->taxBreakdown[0]->tax);

        $this->assertSame(1900, $breakdown->taxBreakdown[1]->rateBps);
        $this->assertSame(2014, $breakdown->taxBreakdown[1]->taxableBase);
        $this->assertSame(383, $breakdown->taxBreakdown[1]->tax);

        // base + tax across brackets accounts for goods + shipping, to the cent.
        $reconstructed = 0;
        foreach ($breakdown->taxBreakdown as $bracket) {
            $reconstructed += $bracket->taxableBase + $bracket->tax;
        }
        $this->assertSame($breakdown->subtotal + $breakdown->shippingCost, $reconstructed);
    }

    #[Test]
    public function shipping_on_a_zero_rated_export_cart_carries_no_vat(): void
    {
        // G4 correctness vs. "tax shipping at the standard rate": a pure-export
        // (0%) cart's shipping must also be 0% — adding standard-rate VAT here
        // would invent tax on a zero-rated supply.
        $widget = $this->priceable(amount: 5000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Export', 'taxRateBps' => 0]],
            currency: Currency::EUR,
            shippingCost: 500,
        );

        $this->assertSame(0, $breakdown->tax);
        $this->assertSame(5500, $breakdown->total);
        $this->assertCount(1, $breakdown->taxBreakdown);
        $this->assertSame(0, $breakdown->taxBreakdown[0]->rateBps);
        $this->assertSame(5500, $breakdown->taxBreakdown[0]->taxableBase);
        $this->assertSame(0, $breakdown->taxBreakdown[0]->tax);
    }

    #[Test]
    public function discount_rounding_cent_follows_the_largest_bracket_remainder_not_the_highest_rate(): void
    {
        // G5: a cart-level voucher over a mixed-rate cart allocates by gross share,
        // largest-remainder. Here a 2000c (7%) + 1000c (19%) cart with a 1000c
        // voucher splits the discount 667 : 333 — the rounding cent lands on the
        // 7% bracket (its 2000-share remainder is larger). The old floor-and-dump
        // code forced the cent onto the *last* (19%) bracket, pushing its taxable
        // base down to 560 and understating its VAT; corrected it is 561.
        Voucher::factory()->create(['code' => 'TEN', 'amount' => 1000, 'currency' => Currency::EUR]);
        $book = $this->priceable(amount: 2000);
        $die = $this->priceable(amount: 1000);

        $breakdown = ($this->calculate)(
            items: [
                ['item' => $book, 'quantity' => 1, 'description' => 'Book', 'taxRateBps' => 700],
                ['item' => $die, 'quantity' => 1, 'description' => 'Die', 'taxRateBps' => 1900],
            ],
            currency: Currency::EUR,
            voucherCode: 'TEN',
        );

        // 7%: gross 2000 − disc 667 = 1333 → net round(13330000/10700)=1246, tax 87.
        // 19%: gross 1000 − disc 333 = 667  → net round(6670000/11900)=561,  tax 106.
        $this->assertSame(3000, $breakdown->subtotal);
        $this->assertSame(1000, $breakdown->discount);
        $this->assertSame(2000, $breakdown->total);
        $this->assertSame(193, $breakdown->tax);

        $this->assertSame(700, $breakdown->taxBreakdown[0]->rateBps);
        $this->assertSame(1246, $breakdown->taxBreakdown[0]->taxableBase);
        $this->assertSame(87, $breakdown->taxBreakdown[0]->tax);

        $this->assertSame(1900, $breakdown->taxBreakdown[1]->rateBps);
        $this->assertSame(561, $breakdown->taxBreakdown[1]->taxableBase);
        $this->assertSame(106, $breakdown->taxBreakdown[1]->tax);
    }

    #[Test]
    public function omitting_the_voucher_code_skips_discount_resolution_entirely(): void
    {
        Voucher::factory()->create(['code' => 'TRAP', 'amount' => 9999, 'currency' => Currency::EUR]);
        $widget = $this->priceable(amount: 5000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
        );

        $this->assertSame(0, $breakdown->discount);
        $this->assertNull($breakdown->voucherCode);
        $this->assertSame(5000, $breakdown->total);
    }

    #[Test]
    public function the_voucher_code_is_persisted_on_the_breakdown_when_passed(): void
    {
        Voucher::factory()->create(['code' => 'KEEP', 'amount' => 100, 'currency' => Currency::EUR]);
        $widget = $this->priceable(amount: 1000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            voucherCode: 'KEEP',
        );

        $this->assertSame('KEEP', $breakdown->voucherCode);
    }

    #[Test]
    public function it_resolves_prices_against_the_passed_price_list_when_provided(): void
    {
        $priceList = PriceList::query()->create(['name' => 'VIP', 'slug' => 'vip']);

        $widget = TestPriceable::factory()->create();
        $widget->prices()->create(['currency' => Currency::EUR->value, 'amount' => 1000, 'minimum_quantity' => 1, 'price_list_id' => null]);
        $widget->prices()->create(['currency' => Currency::EUR->value, 'amount' => 700, 'minimum_quantity' => 1, 'price_list_id' => $priceList->id]);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            priceList: $priceList,
        );

        $this->assertSame(700, $breakdown->subtotal,
            'When a price list is passed, ResolvePrice must prefer its entry over the base price.');
    }

    #[Test]
    public function it_falls_back_to_the_base_price_when_no_price_list_is_passed(): void
    {
        $priceList = PriceList::query()->create(['name' => 'VIP', 'slug' => 'vip']);

        $widget = TestPriceable::factory()->create();
        $widget->prices()->create(['currency' => Currency::EUR->value, 'amount' => 1000, 'minimum_quantity' => 1, 'price_list_id' => null]);
        $widget->prices()->create(['currency' => Currency::EUR->value, 'amount' => 700, 'minimum_quantity' => 1, 'price_list_id' => $priceList->id]);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
        );

        $this->assertSame(1000, $breakdown->subtotal);
    }

    #[Test]
    public function total_reconciles_as_gross_subtotal_minus_discount_plus_shipping(): void
    {
        Voucher::factory()->create(['code' => 'ALL', 'amount' => 500, 'currency' => Currency::EUR]);
        $widget = $this->priceable(amount: 10000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 2, 'description' => 'Widget', 'taxRateBps' => 2100]],
            currency: Currency::EUR,
            shippingCost: 595,
            voucherCode: 'ALL',
        );

        // subtotal 20000 (gross), discount 500, shipping 595 → the single 21%
        // bracket's taxable gross is 20000 − 500 + 595 = 20095 (G4: shipping is a
        // taxable supply, gross-inclusive, its VAT extracted alongside the goods).
        // net = round(200950000/12100) = 16607; tax = 3488 (a component, not added).
        // total = 20000 − 500 + 595 = 20095 — unchanged by the shipping-VAT fix.
        $this->assertSame(20000, $breakdown->subtotal);
        $this->assertSame(500, $breakdown->discount);
        $this->assertSame(3488, $breakdown->tax);
        $this->assertSame(595, $breakdown->shippingCost);
        $this->assertSame(20095, $breakdown->total);
        $this->assertSame(
            $breakdown->subtotal - $breakdown->discount + $breakdown->shippingCost,
            $breakdown->total,
        );
    }

    #[Test]
    public function empty_item_list_returns_a_zero_breakdown(): void
    {
        $breakdown = ($this->calculate)(items: [], currency: Currency::EUR);

        $this->assertSame(0, $breakdown->subtotal);
        $this->assertSame(0, $breakdown->tax);
        $this->assertSame(0, $breakdown->total);
        $this->assertSame([], $breakdown->lines);
        $this->assertSame([], $breakdown->taxBreakdown);
    }

    #[Test]
    public function it_defaults_to_the_inclusive_tax_mode(): void
    {
        $widget = $this->priceable(amount: 1000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget', 'taxRateBps' => 1900]],
            currency: Currency::EUR,
        );

        $this->assertSame(TaxMode::Inclusive, $breakdown->taxMode);
    }

    #[Test]
    public function the_exclusive_tax_mode_is_not_yet_implemented(): void
    {
        $widget = $this->priceable(amount: 1000);

        $this->expectException(RuntimeException::class);

        ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget', 'taxRateBps' => 1900]],
            currency: Currency::EUR,
            taxMode: TaxMode::Exclusive,
        );
    }

    #[Test]
    public function the_currency_is_passed_through_to_the_breakdown_unchanged(): void
    {
        $widget = $this->priceable(amount: 1000, currency: Currency::USD);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::USD,
        );

        $this->assertSame(Currency::USD, $breakdown->currency);
    }

    private function priceable(int $amount, Currency $currency = Currency::EUR): HasPrices&\Illuminate\Database\Eloquent\Model
    {
        $priceable = TestPriceable::factory()->create();
        $priceable->prices()->create([
            'currency' => $currency->value,
            'amount' => $amount,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);

        return $priceable;
    }
}
