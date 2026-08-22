<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Commerce\Order\Actions\UpdateOrderStatus;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * An order that is never paid must not eat a voucher use, exactly as it must
 * not hold onto the stock it reserved. Wired through OrderStatusChanged so it
 * covers every cancel path — the expiry sweep, a consumer's cancel-and-replace,
 * an admin cancelling in Filament.
 */
final class ReleaseVoucherOnOrderCancelledTest extends TestCase
{
    use RefreshDatabase;

    private UpdateOrderStatus $updateStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updateStatus = $this->app->make(UpdateOrderStatus::class);
    }

    #[Test]
    public function cancelling_an_unpaid_order_gives_its_voucher_use_back(): void
    {
        Voucher::factory()->withMaxUses(max: 10, used: 3)->create(['code' => 'ABANDONED']);
        $order = $this->orderWithVoucher('ABANDONED', OrderStatus::Pending);

        ($this->updateStatus)($order, OrderStatus::Cancelled);

        $this->assertSame(2, Voucher::query()->where('code', 'ABANDONED')->value('times_used'));
    }

    #[Test]
    public function cancelling_a_paid_order_does_not_give_the_use_back(): void
    {
        // Confirmed → Cancelled is a cancelled PAID order. Whether the use
        // returns is a refund-policy question, and answering it here would
        // decide it silently for every consumer.
        Voucher::factory()->withMaxUses(max: 10, used: 3)->create(['code' => 'PAID']);
        $order = $this->orderWithVoucher('PAID', OrderStatus::Confirmed);

        ($this->updateStatus)($order, OrderStatus::Cancelled);

        $this->assertSame(3, Voucher::query()->where('code', 'PAID')->value('times_used'));
    }

    #[Test]
    public function cancelling_an_order_without_a_voucher_touches_nothing(): void
    {
        Voucher::factory()->withMaxUses(max: 10, used: 3)->create(['code' => 'UNRELATED']);
        $order = $this->orderWithVoucher(null, OrderStatus::Pending);

        ($this->updateStatus)($order, OrderStatus::Cancelled);

        $this->assertSame(3, Voucher::query()->where('code', 'UNRELATED')->value('times_used'));
    }

    private function orderWithVoucher(?string $code, OrderStatus $status): Order
    {
        return Order::factory()->create([
            'status' => $status,
            'voucher_code' => $code,
        ]);
    }
}
