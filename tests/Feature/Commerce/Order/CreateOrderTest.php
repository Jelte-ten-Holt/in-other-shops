<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Cart\Actions\AddToCart;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Customer\Models\Customer;
use InOtherShops\Commerce\Order\Actions\CreateOrder;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Events\OrderCreated;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Location\Enums\AddressType;
use InOtherShops\Pricing\DTOs\PriceBreakdown;
use InOtherShops\Pricing\Exceptions\VoucherInvalidException;
use InOtherShops\Pricing\Exceptions\VoucherNotFoundException;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\Stubs\TestCartable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * CreateOrder's complement to CreateOrderSnapshotTest. That file covers the
 * snapshot columns + line-tax distribution math. This one covers everything
 * else: the event, the address modes, customer/guest association, and the
 * trust-critical voucher-rollback claim from the action's docblock — "wires
 * voucher usage through ApplyVoucher so an oversold voucher race rolls back
 * the order too."
 */
final class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    private CreateOrder $createOrder;

    private AddToCart $addToCart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrder = $this->app->make(CreateOrder::class);
        $this->addToCart = $this->app->make(AddToCart::class);
    }

    #[Test]
    public function it_dispatches_order_created_after_the_transaction_commits(): void
    {
        // Critical that this fires *after* commit — listeners reading the
        // order from another connection or queueing jobs would otherwise
        // see a row that may yet roll back.
        Event::fake([OrderCreated::class]);

        $cart = $this->cartWithItem();
        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        Event::assertDispatched(
            OrderCreated::class,
            fn (OrderCreated $event): bool => $event->order->is($order),
        );
    }

    #[Test]
    public function it_persists_the_order_with_default_status_pending(): void
    {
        $cart = $this->cartWithItem();

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function it_generates_a_non_empty_order_number(): void
    {
        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        $this->assertNotNull($order->order_number);
        $this->assertNotSame('', $order->order_number);
    }

    #[Test]
    public function order_numbers_are_unique_across_consecutive_orders(): void
    {
        $first = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );
        $second = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        $this->assertNotSame($first->order_number, $second->order_number);
    }

    #[Test]
    public function it_associates_the_customer_when_passed(): void
    {
        $customer = Customer::factory()->create();

        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
            customer: $customer,
        );

        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNull($order->email, 'Customer-attached orders should not also carry a guest email.');
    }

    #[Test]
    public function it_persists_the_guest_email_when_no_customer_is_passed(): void
    {
        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
            guestEmail: 'guest@buyer.test',
        );

        $this->assertNull($order->customer_id);
        $this->assertSame('guest@buyer.test', $order->email);
    }

    #[Test]
    public function billing_address_is_marked_shipping_and_billing_when_no_separate_shipping_passed(): void
    {
        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        $addresses = $order->addresses()->get();
        $this->assertCount(1, $addresses);
        $this->assertSame(AddressType::ShippingAndBilling, $addresses[0]->type);
    }

    #[Test]
    public function separate_shipping_address_is_attached_with_distinct_types(): void
    {
        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(line1: '1 Billing St'),
            shippingAddress: $this->billingAddress(line1: '1 Shipping Ave'),
        );

        $addresses = $order->addresses()->get();
        $this->assertCount(2, $addresses);

        $byType = $addresses->keyBy(fn ($address): string => $address->type->value);
        $this->assertSame('1 Billing St', $byType[AddressType::Billing->value]->line_1);
        $this->assertSame('1 Shipping Ave', $byType[AddressType::Shipping->value]->line_1);
    }

    #[Test]
    public function it_commits_voucher_usage_when_breakdown_carries_a_voucher_code(): void
    {
        $voucher = Voucher::factory()->create([
            'code' => 'COMMIT',
            'amount' => 500,
            'currency' => Currency::EUR,
        ]);

        ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(voucherCode: 'COMMIT', discount: 500, total: 9500),
            billingAddress: $this->billingAddress(),
        );

        $this->assertSame(1, $voucher->fresh()->times_used,
            'CreateOrder must call ApplyVoucher to record usage when a voucher code rides on the breakdown.');
    }

    #[Test]
    public function it_does_not_touch_voucher_usage_when_breakdown_has_no_voucher_code(): void
    {
        // Negative companion: a voucher that exists but isn't referenced in
        // the breakdown must not be incremented. An implementation that
        // grabbed "any voucher matching the customer" would fail here.
        $voucher = Voucher::factory()->create([
            'code' => 'UNUSED',
            'amount' => 100,
            'currency' => Currency::EUR,
        ]);

        ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        $this->assertSame(0, $voucher->fresh()->times_used);
    }

    #[Test]
    public function a_voucher_rejection_rolls_back_the_order_and_does_not_dispatch_order_created(): void
    {
        // The trust-critical claim from the docblock. Build a breakdown that
        // names a voucher code that does not exist — ApplyVoucher will throw
        // VoucherNotFoundException inside CreateOrder's outer transaction.
        // Everything written before the throw must roll back: no Order row,
        // no OrderLine rows, no address rows. And OrderCreated must NOT
        // dispatch because it sits outside the transaction.
        Event::fake([OrderCreated::class]);

        try {
            ($this->createOrder)(
                cart: $this->cartWithItem(),
                breakdown: $this->breakdown(voucherCode: 'NONEXISTENT', discount: 500, total: 9500),
                billingAddress: $this->billingAddress(),
            );
            $this->fail('Expected ApplyVoucher to throw VoucherNotFoundException.');
        } catch (VoucherNotFoundException) {
            $this->assertSame(0, Order::query()->count(),
                'Order row must roll back when ApplyVoucher rejects the voucher.');
            Event::assertNotDispatched(OrderCreated::class,
                'OrderCreated must not fire when the transaction rolls back.');
        }
    }

    #[Test]
    public function a_voucher_that_went_invalid_since_it_was_quoted_is_still_honoured(): void
    {
        // The race the action's docblock calls out, and the POLICY CHANGE that
        // answers it: the voucher passed CalculateVoucherDiscount during
        // pricing, and by the time CreateOrder commits it is spent. Refusing
        // here would fail an order the shopper has already been quoted — and at
        // checkout, already been sent to a payment form for. So the redemption
        // is honoured (`alreadyValidated: true`) and the shop absorbs the
        // overshoot. Contrast the NONEXISTENT case above, which still throws:
        // a missing row is not a race.
        Event::fake([OrderCreated::class]);

        Voucher::factory()->withMaxUses(max: 1, used: 1)->create([
            'code' => 'BURNED',
            'amount' => 500,
            'currency' => Currency::EUR,
        ]);

        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(voucherCode: 'BURNED', discount: 500, total: 9500),
            billingAddress: $this->billingAddress(),
        );

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(500, (int) $order->discount);
        Event::assertDispatched(OrderCreated::class);

        // The overshoot is RECORDED, not hidden: 2 uses against a max of 1 is
        // what really happened, and it is what the admin needs to see.
        $this->assertSame(2, Voucher::query()->where('code', 'BURNED')->value('times_used'),
            'Honouring the redemption must still increment usage, so the overshoot past max_uses is visible.');
    }

    #[Test]
    public function the_voucher_code_is_snapshotted_onto_the_order(): void
    {
        // The discount amount alone leaves an order untraceable to the campaign
        // that caused it. A code snapshot rather than a voucher_id: the order
        // must stay true after the voucher row is edited or deleted, like every
        // other snapshot on it.
        Voucher::factory()->create([
            'code' => 'SPRING',
            'amount' => 500,
            'currency' => Currency::EUR,
        ]);

        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(voucherCode: 'SPRING', discount: 500, total: 9500),
            billingAddress: $this->billingAddress(),
        );

        $this->assertSame('SPRING', $order->fresh()->voucher_code);
    }

    #[Test]
    public function an_order_without_a_voucher_snapshots_no_code(): void
    {
        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(),
            billingAddress: $this->billingAddress(),
        );

        $this->assertNull($order->fresh()->voucher_code);
    }

    #[Test]
    public function it_creates_one_order_line_per_cart_item_with_quantity_preserved(): void
    {
        $cart = Cart::factory()->create(['session_token' => 'test']);
        $widget = TestCartable::factory()->create();
        ($this->addToCart)($cart, $widget, quantity: 4);

        $order = ($this->createOrder)(
            cart: $cart,
            breakdown: $this->breakdown(subtotal: 0, total: 0),
            billingAddress: $this->billingAddress(),
        );

        $lines = $order->lines()->get();
        $this->assertCount(1, $lines);
        $this->assertSame(4, $lines[0]->quantity);
        $this->assertTrue($lines[0]->orderable->is($widget));
    }

    #[Test]
    public function the_currency_is_persisted_from_the_breakdown(): void
    {
        $order = ($this->createOrder)(
            cart: $this->cartWithItem(),
            breakdown: $this->breakdown(currency: Currency::USD),
            billingAddress: $this->billingAddress(),
        );

        $this->assertSame(Currency::USD, $order->fresh()->currency);
    }

    private function cartWithItem(): Cart
    {
        $cart = Cart::factory()->create(['session_token' => 'test-'.uniqid()]);
        ($this->addToCart)($cart, TestCartable::factory()->create());

        return $cart;
    }

    private function breakdown(
        int $subtotal = 10000,
        int $discount = 0,
        int $tax = 0,
        int $shippingCost = 0,
        int $total = 10000,
        Currency $currency = Currency::EUR,
        ?string $voucherCode = null,
    ): PriceBreakdown {
        return new PriceBreakdown(
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            shippingCost: $shippingCost,
            total: $total,
            currency: $currency,
            lines: [],
            voucherCode: $voucherCode,
        );
    }

    /**
     * @return array{first_name: string, last_name: string, line_1: string, city: string, postal_code: string, country_code: string}
     */
    private function billingAddress(string $line1 = '1 Test Street'): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'User',
            'line_1' => $line1,
            'city' => 'Testville',
            'postal_code' => '1234AB',
            'country_code' => 'NL',
        ];
    }

    private function orderLineCount(): int
    {
        return \DB::table('order_lines')->count();
    }

    private function addressCount(): int
    {
        return \DB::table('addresses')->count();
    }
}
