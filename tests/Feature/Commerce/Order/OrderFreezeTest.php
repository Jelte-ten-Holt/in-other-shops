<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Exceptions\OrderFrozenException;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Commerce\Order\Models\OrderLine;
use InOtherShops\Location\Models\Address;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Freeze on payment (bookkeeping brief §12 P-1): once money moved for an order,
 * what the customer was charged for — money fields, lines, addresses — stays as
 * it was, and the order cannot be deleted. Each refusal asserts nothing changed.
 */
final class OrderFreezeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{PaymentStatus, bool}> */
    public static function paymentStatuses(): array
    {
        return [
            'succeeded' => [PaymentStatus::Succeeded, true],
            'partially refunded' => [PaymentStatus::PartiallyRefunded, true],
            'refunded' => [PaymentStatus::Refunded, true],
            'pending' => [PaymentStatus::Pending, false],
            'failed' => [PaymentStatus::Failed, false],
        ];
    }

    #[Test]
    #[DataProvider('paymentStatuses')]
    public function an_order_is_frozen_once_a_payment_for_it_succeeded_refunded_since_or_not(PaymentStatus $status, bool $frozen): void
    {
        $order = $this->order(paid: false);
        Payment::factory()->for($order, 'payable')->create(['status' => $status, 'amount' => 10000]);

        $this->assertSame($frozen, $order->isFrozen());
    }

    /**
     * What the customer was charged and under which number, with a changed
     * value for each. Kept apart from Order::FROZEN_WHEN_PAID on purpose: a
     * column dropped from the constant fails its case here, and one added to
     * it fails the_frozen_columns_are_exactly_the_charged_ones.
     */
    private const array CHANGED = [
        'order_number' => 'ORD-RENAMED',
        'currency' => 'USD',
        'subtotal' => 9000,
        'discount' => 500,
        'voucher_code' => 'SPRING',
        'shipping_method_identifier' => 'express',
        'shipping_cost' => 900,
        'shipping_cost_currency' => 'USD',
        'tax' => 1500,
        'tax_rate_bps' => 2100,
        'tax_rate_country_code' => 'NL',
        'tax_summary' => [['rate_bps' => 1900, 'taxable_base' => 100, 'tax' => 19]],
        'total' => 9000,
    ];

    /** @return array<string, array{string, mixed}> */
    public static function frozenFields(): array
    {
        $cases = [];

        foreach (self::CHANGED as $field => $value) {
            $cases[$field] = [$field, $value];
        }

        return $cases;
    }

    #[Test]
    public function the_frozen_columns_are_exactly_the_charged_ones(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::CHANGED), Order::FROZEN_WHEN_PAID);
    }

    #[Test]
    #[DataProvider('frozenFields')]
    public function a_paid_orders_money_field_is_refused_and_left_as_it_was(string $field, mixed $value): void
    {
        $order = $this->order(paid: true);
        $before = $order->fresh()->getAttributes();

        try {
            $order->update([$field => $value]);
            $this->fail("A paid order's {$field} changed.");
        } catch (OrderFrozenException $e) {
            $this->assertStringContainsString($field, $e->getMessage());
        }

        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    #[Test]
    public function a_paid_orders_notes_email_and_status_stay_editable(): void
    {
        $order = $this->order(paid: true);

        $order->update(['notes' => 'Customer asked for gift wrap', 'email' => 'new@example.test', 'status' => OrderStatus::Confirmed]);

        $this->assertSame('Customer asked for gift wrap', $order->fresh()->notes);
        $this->assertSame('new@example.test', $order->fresh()->email);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    #[Test]
    public function an_unpaid_orders_money_fields_stay_editable(): void
    {
        $order = $this->order(paid: false);

        $order->update(['total' => 9000, 'subtotal' => 9000]);

        $this->assertSame(9000, $order->fresh()->total);
    }

    #[Test]
    public function a_paid_order_cannot_be_deleted(): void
    {
        $order = $this->order(paid: true);

        $this->expectException(OrderFrozenException::class);

        try {
            $order->delete();
        } finally {
            $this->assertTrue(Order::query()->whereKey($order->getKey())->exists());
        }
    }

    #[Test]
    public function a_paid_orders_lines_cannot_be_changed_added_or_removed(): void
    {
        $order = $this->order(paid: true);
        $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

        $this->assertRefused(fn () => $line->update(['quantity' => 2]));
        $this->assertRefused(fn () => OrderLine::factory()->create(['order_id' => $order->getKey()]));
        $this->assertRefused(fn () => $line->delete());

        $this->assertSame(1, OrderLine::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(1, $line->fresh()->quantity);
    }

    #[Test]
    public function an_unpaid_orders_lines_stay_editable(): void
    {
        $order = $this->order(paid: false);
        $line = OrderLine::query()->where('order_id', $order->getKey())->sole();

        $line->update(['quantity' => 2]);

        $this->assertSame(2, $line->fresh()->quantity);
    }

    #[Test]
    public function a_paid_orders_addresses_cannot_be_changed_added_or_removed(): void
    {
        $order = $this->order(paid: true);
        $address = $order->addresses()->sole();

        $this->assertRefused(fn () => $address->update(['line_1' => 'Somewhere else 1']));
        $this->assertRefused(fn () => Address::factory()->for($order, 'addressable')->create());
        $this->assertRefused(fn () => $address->delete());

        $this->assertSame(1, $order->addresses()->count());
        $this->assertNotSame('Somewhere else 1', $address->fresh()->line_1);
    }

    #[Test]
    public function addresses_of_anything_but_a_paid_order_stay_editable(): void
    {
        $unpaid = $this->order(paid: false);
        $address = $unpaid->addresses()->sole();

        $address->update(['line_1' => 'Somewhere else 1']);

        $this->assertSame('Somewhere else 1', $address->fresh()->line_1);
    }

    private function order(bool $paid): Order
    {
        $order = Order::factory()->create(['total' => 10000, 'subtotal' => 10000]);
        OrderLine::factory()->create(['order_id' => $order->getKey()]);
        Address::factory()->for($order, 'addressable')->create();

        if ($paid) {
            Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => 10000, 'gateway' => 'fake']);
        }

        return $order;
    }

    private function assertRefused(callable $change): void
    {
        try {
            $change();
            $this->fail('A paid order changed.');
        } catch (OrderFrozenException) {
            $this->addToAssertionCount(1);
        }
    }
}
