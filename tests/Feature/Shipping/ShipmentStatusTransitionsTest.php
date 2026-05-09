<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Actions\DispatchShipment;
use InOtherShops\Shipping\Actions\MarkShipmentDelivered;
use InOtherShops\Shipping\Actions\MarkShipmentLost;
use InOtherShops\Shipping\Actions\MarkShipmentReady;
use InOtherShops\Shipping\Actions\MarkShipmentReturnedToSender;
use InOtherShops\Shipping\Enums\ShipmentStatus;
use InOtherShops\Shipping\Events\ShipmentDelivered;
use InOtherShops\Shipping\Events\ShipmentDispatched;
use InOtherShops\Shipping\Events\ShipmentLost;
use InOtherShops\Shipping\Events\ShipmentReady;
use InOtherShops\Shipping\Events\ShipmentReturnedToSender;
use InOtherShops\Shipping\Exceptions\InvalidShipmentStatusTransitionException;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ShipmentStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shipping.auto_create_shipment', false);
    }

    #[Test]
    public function mark_ready_transitions_pending_to_ready(): void
    {
        Event::fake([ShipmentReady::class]);
        $shipment = $this->shipment(ShipmentStatus::Pending);

        app(MarkShipmentReady::class)($shipment);

        $this->assertSame(ShipmentStatus::Ready, $shipment->refresh()->status);
        Event::assertDispatched(ShipmentReady::class);
    }

    #[Test]
    public function dispatch_shipment_records_carrier_tracking_and_shipped_at(): void
    {
        Event::fake([ShipmentDispatched::class]);
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment, 'TRK123', 'dhl');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::InTransit, $shipment->status);
        $this->assertSame('dhl', $shipment->carrier);
        $this->assertSame('TRK123', $shipment->tracking_number);
        $this->assertNotNull($shipment->shipped_at);
        Event::assertDispatched(ShipmentDispatched::class);
    }

    #[Test]
    public function dispatch_shipment_resolves_tracking_url_from_carrier_template(): void
    {
        config()->set('shipping.carriers.dhl', [
            'name' => 'DHL',
            'tracking_url_template' => 'https://example.test/track/{tracking_number}',
        ]);
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment, 'TRK123', 'dhl');

        $this->assertSame('https://example.test/track/TRK123', $shipment->refresh()->tracking_url);
    }

    #[Test]
    public function dispatch_shipment_prefers_explicit_tracking_url_over_template(): void
    {
        config()->set('shipping.carriers.dhl', [
            'tracking_url_template' => 'https://example.test/track/{tracking_number}',
        ]);
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)(
            $shipment,
            'TRK123',
            'dhl',
            'https://signed.example.test/abc123',
        );

        $this->assertSame('https://signed.example.test/abc123', $shipment->refresh()->tracking_url);
    }

    #[Test]
    public function dispatch_shipment_leaves_tracking_url_null_when_no_template(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment, 'TRK123', 'one_off_carrier');

        $this->assertNull($shipment->refresh()->tracking_url);
    }

    #[Test]
    public function mark_delivered_sets_delivered_at_and_dispatches_event(): void
    {
        Event::fake([ShipmentDelivered::class]);
        $shipment = $this->shipment(ShipmentStatus::InTransit);

        app(MarkShipmentDelivered::class)($shipment);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
        Event::assertDispatched(ShipmentDelivered::class);
    }

    #[Test]
    public function mark_returned_dispatches_event_with_reason(): void
    {
        Event::fake([ShipmentReturnedToSender::class]);
        $shipment = $this->shipment(ShipmentStatus::InTransit);

        app(MarkShipmentReturnedToSender::class)($shipment, 'address invalid');

        $this->assertSame(ShipmentStatus::ReturnedToSender, $shipment->refresh()->status);
        Event::assertDispatched(
            ShipmentReturnedToSender::class,
            fn (ShipmentReturnedToSender $event) => $event->reason === 'address invalid',
        );
    }

    #[Test]
    public function returned_to_sender_can_transition_back_to_pending(): void
    {
        $this->assertTrue(ShipmentStatus::ReturnedToSender->canTransitionTo(ShipmentStatus::Pending));
    }

    #[Test]
    public function mark_lost_dispatches_event_with_reason(): void
    {
        Event::fake([ShipmentLost::class]);
        $shipment = $this->shipment(ShipmentStatus::InTransit);

        app(MarkShipmentLost::class)($shipment, 'carrier admits loss');

        $this->assertSame(ShipmentStatus::Lost, $shipment->refresh()->status);
        Event::assertDispatched(
            ShipmentLost::class,
            fn (ShipmentLost $event) => $event->reason === 'carrier admits loss',
        );
    }

    #[Test]
    public function delivered_is_terminal(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Delivered);

        $this->expectException(InvalidShipmentStatusTransitionException::class);

        app(MarkShipmentReady::class)($shipment);
    }

    #[Test]
    public function lost_is_terminal(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Lost);

        $this->expectException(InvalidShipmentStatusTransitionException::class);

        app(MarkShipmentDelivered::class)($shipment);
    }

    #[Test]
    public function pending_cannot_jump_to_delivered(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Pending);

        $this->expectException(InvalidShipmentStatusTransitionException::class);

        app(MarkShipmentDelivered::class)($shipment);
    }

    private function shipment(ShipmentStatus $status): Shipment
    {
        $order = Order::factory()->create();

        return Shipment::factory()->status($status)->for($order, 'shippable')->create();
    }
}
