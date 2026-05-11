<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\CalculateTotal;
use InOtherShops\Pricing\Contracts\HasPrices;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
use InOtherShops\Pricing\Models\PriceList;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\Stubs\TestPriceable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Composes ResolvePrice + CalculateVoucherDiscount + CalculateTax into a
 * PriceBreakdown. The interesting behaviour is the composition itself —
 * particularly that tax is computed on the discounted subtotal, that lines
 * carry their own unit prices through, and that the total reconciles.
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
            taxRate: 0,
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
        // Non-uniform unit prices + quantities; an implementation that
        // confused sum-of-unit-prices with sum-of-line-totals would fail.
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
            taxRate: 0,
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
            taxRate: 0,
        );

        $this->assertSame('Apples', $breakdown->lines[0]->description);
        $this->assertSame('Bananas', $breakdown->lines[1]->description);
    }

    #[Test]
    public function missing_price_resolves_to_unit_price_zero_rather_than_throwing(): void
    {
        // Priceable with no Price row in the requested currency.
        $orphan = TestPriceable::factory()->create();

        $breakdown = ($this->calculate)(
            items: [['item' => $orphan, 'quantity' => 5, 'description' => 'Orphan']],
            currency: Currency::EUR,
            taxRate: 2100,
        );

        $this->assertSame(0, $breakdown->subtotal);
        $this->assertSame(0, $breakdown->total);
        $this->assertSame(0, $breakdown->lines[0]->unitPrice);
        $this->assertSame(0, $breakdown->lines[0]->lineTotal);
        $this->assertSame(5, $breakdown->lines[0]->quantity);
    }

    #[Test]
    public function shipping_cost_is_added_to_the_total_but_not_to_subtotal(): void
    {
        $widget = $this->priceable(amount: 1000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 0,
            shippingCost: 595,
        );

        $this->assertSame(1000, $breakdown->subtotal,
            'Subtotal must not include shipping — shipping is a separate column.');
        $this->assertSame(595, $breakdown->shippingCost);
        $this->assertSame(1595, $breakdown->total);
    }

    #[Test]
    public function tax_is_computed_on_subtotal_minus_discount_not_on_raw_subtotal(): void
    {
        // This is the key composition invariant: a voucher reduces the
        // taxable base. If tax were computed on raw subtotal a 100% voucher
        // would still produce tax > 0 — a real-money bug.
        Voucher::factory()->create([
            'code' => 'HALF',
            'amount' => 5000,
            'currency' => Currency::EUR,
        ]);

        $widget = $this->priceable(amount: 10000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 2000,
            voucherCode: 'HALF',
        );

        $this->assertSame(10000, $breakdown->subtotal);
        $this->assertSame(5000, $breakdown->discount);
        // 20% of (10000 - 5000) = 1000, not 20% of 10000 = 2000.
        $this->assertSame(1000, $breakdown->tax);
        $this->assertSame(6000, $breakdown->total,
            'total = subtotal - discount + tax + shipping = 10000 - 5000 + 1000 + 0.');
    }

    #[Test]
    public function omitting_the_voucher_code_skips_discount_resolution_entirely(): void
    {
        // Negative of the optional argument: if the action wrote `discount`
        // unconditionally (e.g. always called CalculateVoucherDiscount with
        // a null code) it would either throw or return a non-zero discount
        // for some accidental match. Pass a voucher that *exists* — a buggy
        // implementation that grabbed "any active voucher" would silently
        // apply it; the correct behavior is to not look at vouchers at all.
        Voucher::factory()->create([
            'code' => 'TRAP',
            'amount' => 9999,
            'currency' => Currency::EUR,
        ]);

        $widget = $this->priceable(amount: 5000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 0,
            // voucherCode intentionally omitted
        );

        $this->assertSame(0, $breakdown->discount);
        $this->assertNull($breakdown->voucherCode);
        $this->assertSame(5000, $breakdown->total);
    }

    #[Test]
    public function the_voucher_code_is_persisted_on_the_breakdown_when_passed(): void
    {
        Voucher::factory()->create([
            'code' => 'KEEP',
            'amount' => 100,
            'currency' => Currency::EUR,
        ]);
        $widget = $this->priceable(amount: 1000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 0,
            voucherCode: 'KEEP',
        );

        // CreateOrder reads this back off the breakdown to know whether to
        // commit usage — losing it here silently drops the usage record.
        $this->assertSame('KEEP', $breakdown->voucherCode);
    }

    #[Test]
    public function it_resolves_prices_against_the_passed_price_list_when_provided(): void
    {
        $priceList = PriceList::query()->create(['name' => 'VIP', 'slug' => 'vip']);

        $widget = TestPriceable::factory()->create();
        $widget->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);
        $widget->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 700,
            'minimum_quantity' => 1,
            'price_list_id' => $priceList->id,
        ]);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 0,
            priceList: $priceList,
        );

        $this->assertSame(700, $breakdown->subtotal,
            'When a price list is passed, ResolvePrice must prefer its entry over the base price.');
    }

    #[Test]
    public function it_falls_back_to_the_base_price_when_no_price_list_is_passed(): void
    {
        // Negative companion to the previous: same fixture, no priceList
        // argument — the base price must win, proving the priceList arg
        // is not silently ignored vs. silently always-applied.
        $priceList = PriceList::query()->create(['name' => 'VIP', 'slug' => 'vip']);

        $widget = TestPriceable::factory()->create();
        $widget->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 1000,
            'minimum_quantity' => 1,
            'price_list_id' => null,
        ]);
        $widget->prices()->create([
            'currency' => Currency::EUR->value,
            'amount' => 700,
            'minimum_quantity' => 1,
            'price_list_id' => $priceList->id,
        ]);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 0,
        );

        $this->assertSame(1000, $breakdown->subtotal);
    }

    #[Test]
    public function total_reconciles_with_subtotal_minus_discount_plus_tax_plus_shipping(): void
    {
        Voucher::factory()->create([
            'code' => 'ALL',
            'amount' => 500,
            'currency' => Currency::EUR,
        ]);

        $widget = $this->priceable(amount: 10000);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 2, 'description' => 'Widget']],
            currency: Currency::EUR,
            taxRate: 2100,
            shippingCost: 595,
            voucherCode: 'ALL',
        );

        // subtotal 20000, discount 500, taxable 19500, tax = floor(19500 * 2100 / 10000) = 4095
        // total = 20000 - 500 + 4095 + 595 = 24190
        $this->assertSame(20000, $breakdown->subtotal);
        $this->assertSame(500, $breakdown->discount);
        $this->assertSame(4095, $breakdown->tax);
        $this->assertSame(595, $breakdown->shippingCost);
        $this->assertSame(24190, $breakdown->total);
        $this->assertSame(
            $breakdown->subtotal - $breakdown->discount + $breakdown->tax + $breakdown->shippingCost,
            $breakdown->total,
        );
    }

    #[Test]
    public function empty_item_list_returns_a_zero_breakdown(): void
    {
        $breakdown = ($this->calculate)(
            items: [],
            currency: Currency::EUR,
            taxRate: 2100,
        );

        $this->assertSame(0, $breakdown->subtotal);
        $this->assertSame(0, $breakdown->tax);
        $this->assertSame(0, $breakdown->total);
        $this->assertSame([], $breakdown->lines);
    }

    #[Test]
    public function the_currency_is_passed_through_to_the_breakdown_unchanged(): void
    {
        $widget = $this->priceable(amount: 1000, currency: Currency::USD);

        $breakdown = ($this->calculate)(
            items: [['item' => $widget, 'quantity' => 1, 'description' => 'Widget']],
            currency: Currency::USD,
            taxRate: 0,
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
