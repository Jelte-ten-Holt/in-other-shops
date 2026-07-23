<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use PHPUnit\Framework\Attributes\Test;
use InOtherShops\Tests\TestCase;

/**
 * Order::isDeletable() (audit M3 / D4) — the single predicate the Filament
 * delete, bulk-delete, and address-delete actions all gate on. Only a fresh
 * Pending order with no payment row may be deleted; a payment row or any status
 * past Pending makes deleting it destroy the record of money that moved.
 */
final class OrderDeletableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_fresh_pending_order_with_no_payment_is_deletable(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $this->assertTrue($order->isDeletable());
    }

    #[Test]
    public function an_order_with_a_payment_row_is_not_deletable(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $this->paymentFor($order);

        $this->assertFalse($order->fresh()->isDeletable(),
            'A Pending order that already has a payment row must not be deletable.');
    }

    #[Test]
    public function a_payment_less_order_past_pending_is_not_deletable(): void
    {
        foreach ([OrderStatus::Confirmed, OrderStatus::Cancelled] as $status) {
            $order = Order::factory()->create(['status' => $status]);

            $this->assertFalse($order->isDeletable(),
                "A {$status->value} order must not be deletable, payments or not.");
        }
    }

    private function paymentFor(Order $order): Payment
    {
        return Payment::factory()->for($order, 'payable')->create([
            'gateway' => 'fake',
            'amount' => 1000,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);
    }
}
