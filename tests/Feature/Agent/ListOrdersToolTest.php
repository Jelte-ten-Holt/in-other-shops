<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use InOtherShops\Agent\Tools\ListOrders;
use InOtherShops\Commerce\Order\Enums\OrderStatus;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class ListOrdersToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_orders_paginated_newest_first(): void
    {
        $older = Order::factory()->create(['created_at' => now()->subDays(2)]);
        $newer = Order::factory()->create(['created_at' => now()->subDay()]);

        $result = app(ListOrders::class)([]);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['data']);
        $this->assertSame($newer->id, $result['data'][0]['id']);
        $this->assertSame($older->id, $result['data'][1]['id']);
        $this->assertSame(2, $result['meta']['total']);
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        Order::factory()->status(OrderStatus::Pending)->create();
        $shipped = Order::factory()->status(OrderStatus::Shipped)->create();

        $result = app(ListOrders::class)(['status' => 'shipped']);

        $this->assertCount(1, $result['data']);
        $this->assertSame($shipped->id, $result['data'][0]['id']);
        $this->assertSame('shipped', $result['data'][0]['status']);
    }

    #[Test]
    public function it_filters_by_created_at_range(): void
    {
        Order::factory()->create(['created_at' => now()->subDays(10)]);
        $inside = Order::factory()->create(['created_at' => now()->subDays(3)]);
        Order::factory()->create(['created_at' => now()]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->subDays(2)->toDateString();

        $result = app(ListOrders::class)(['from' => $from, 'to' => $to]);

        $this->assertCount(1, $result['data']);
        $this->assertSame($inside->id, $result['data'][0]['id']);
    }

    #[Test]
    public function it_applies_per_page_and_page(): void
    {
        Order::factory()->count(5)->create();

        $first = app(ListOrders::class)(['per_page' => 2, 'page' => 1]);
        $second = app(ListOrders::class)(['per_page' => 2, 'page' => 2]);

        $this->assertCount(2, $first['data']);
        $this->assertCount(2, $second['data']);
        $this->assertSame(1, $first['meta']['current_page']);
        $this->assertSame(2, $second['meta']['current_page']);
        $this->assertSame(3, $first['meta']['last_page']);
    }

    #[Test]
    public function it_throws_on_unknown_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ListOrders::class)(['status' => 'not-a-status']);
    }
}
