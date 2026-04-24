<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\ShowOrder;
use InOtherShops\Commerce\Commerce;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Support\AgentTestUser;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class ShowOrderToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        request()->attributes->set('agent.is_admin', true);
    }

    #[Test]
    public function it_returns_an_order_with_lines_and_payments_summary(): void
    {
        $order = Order::factory()->withLines(count: 2)->create();

        Payment::factory()->for($order, 'payable')->succeeded()->create(['amount' => 500]);
        Payment::factory()->for($order, 'payable')->create(['amount' => 500]);

        $result = app(ShowOrder::class)(['id' => $order->id]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['id' => $order->id], $result['target']);
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

        $this->assertFalse($result['ok']);
        $this->assertSame(['id' => 999999], $result['target']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    #[Test]
    public function non_admin_caller_sees_their_own_order(): void
    {
        $customer = Commerce::customer()::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        request()->attributes->set('agent.is_admin', false);
        request()->attributes->set('agent.user', new AgentTestUser($customer));

        $result = app(ShowOrder::class)(['id' => $order->id]);

        $this->assertTrue($result['ok']);
        $this->assertSame($order->id, $result['data']['id']);
    }

    #[Test]
    public function non_admin_caller_cannot_see_another_customers_order(): void
    {
        $alice = Commerce::customer()::factory()->create();
        $bob = Commerce::customer()::factory()->create();
        $bobsOrder = Order::factory()->create(['customer_id' => $bob->id]);

        request()->attributes->set('agent.is_admin', false);
        request()->attributes->set('agent.user', new AgentTestUser($alice));

        $result = app(ShowOrder::class)(['id' => $bobsOrder->id]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    #[Test]
    public function non_admin_without_customer_cannot_see_any_order(): void
    {
        $order = Order::factory()->create();

        request()->attributes->set('agent.is_admin', false);
        request()->attributes->set('agent.user', null);

        $result = app(ShowOrder::class)(['id' => $order->id]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['error']['code']);
    }
}
