<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\ShowOrder;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ShowOrderToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_an_order_with_lines_and_payments_summary(): void
    {
        $order = Order::factory()->withLines(count: 2)->create();

        Payment::factory()->for($order, 'payable')->succeeded()->create(['amount' => 500]);
        Payment::factory()->for($order, 'payable')->create(['amount' => 500]);

        $result = app(ShowOrder::class)(['id' => $order->id]);

        $this->assertTrue($result['found']);
        $this->assertSame($order->id, $result['data']['id']);
        $this->assertCount(2, $result['data']['lines']);
        $this->assertSame(2, $result['data']['payments']['count']);
        $this->assertSame(500, $result['data']['payments']['total_paid']);
        $this->assertFalse($result['data']['payments']['is_paid']);
    }

    #[Test]
    public function it_returns_not_found_for_unknown_id(): void
    {
        $result = app(ShowOrder::class)(['id' => 999999]);

        $this->assertFalse($result['found']);
        $this->assertSame(999999, $result['id']);
    }
}
