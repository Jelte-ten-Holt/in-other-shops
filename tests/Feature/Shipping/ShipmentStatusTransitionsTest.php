<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Shipping\Actions\DispatchShipment;
use InOtherShops\Shipping\Actions\MarkShipmentDelivered;
use InOtherShops\Shipping\Actions\MarkShipmentLost;
use InOtherShops\Shipping\Actions\MarkShipmentReady;
use InOtherShops\Shipping\Actions\MarkShipmentReturnedToSender;
use InOtherShops\Shipping\Actions\UpdateShipmentStatus;
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

    /**
     * The widening (v0.64.0), and the reason it exists. Untracked post is a
     * real service — the cheaper of two methods at a shop posting small
     * handmade parcels — and before this the ONLY route to InTransit demanded
     * a tracking number. Those shipments sat at Ready forever, which silently
     * disabled every feature keyed on dispatch: the "your order shipped" mail
     * has nothing to fire on, and a review-invite sweep reading `shipped_at`
     * finds null and never becomes due, with the suite green throughout.
     *
     * So the assertions that matter here are the last two: `shipped_at` is
     * stamped and the event fires WITHOUT tracking. Deleting either must break
     * this test.
     */
    #[Test]
    public function an_untracked_parcel_can_be_dispatched_and_still_stamps_and_announces(): void
    {
        Event::fake([ShipmentDispatched::class]);
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::InTransit, $shipment->status);
        $this->assertNull($shipment->carrier);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->tracking_url);
        $this->assertNotNull($shipment->shipped_at);
        Event::assertDispatched(ShipmentDispatched::class);
    }

    /**
     * A carrier without a number derives no URL. The template would otherwise
     * substitute an empty string and produce a link to the carrier's
     * "not found" page — a worse outcome than no link, because it looks like
     * tracking that has gone wrong rather than tracking that was never bought.
     */
    #[Test]
    public function a_carrier_without_a_tracking_number_derives_no_url(): void
    {
        config()->set('shipping.carriers.dhl', [
            'tracking_url_template' => 'https://example.test/track/{tracking_number}',
        ]);
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment, carrier: 'dhl');

        $this->assertNull($shipment->refresh()->tracking_url);
    }

    /**
     * Blank strings are absent, not values. With `required()` gone from the
     * admin form an untouched Filament input arrives as '' — the callsite
     * normalizes it, and this pins the action behaving sanely if a caller
     * forgets.
     */
    #[Test]
    public function empty_strings_are_treated_as_absent_tracking(): void
    {
        config()->set('shipping.carriers.dhl', [
            'tracking_url_template' => 'https://example.test/track/{tracking_number}',
        ]);
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment, '', 'dhl');

        $this->assertNull($shipment->refresh()->tracking_url);
    }

    /**
     * A re-ship after ReturnedToSender is a legitimate SECOND dispatch —
     * returned, address fixed, re-posted — and `shipped_at` moves with it.
     * Consumers scope send-once claims per dispatch episode on that timestamp,
     * so a stale one would suppress the mail for exactly the parcel a buyer
     * most needs to hear about.
     */
    #[Test]
    public function a_re_dispatch_after_a_return_stamps_a_fresh_shipped_at(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Ready);

        app(DispatchShipment::class)($shipment, 'TRK123', 'dhl');
        $firstShippedAt = $shipment->refresh()->shipped_at;

        app(MarkShipmentReturnedToSender::class)($shipment, 'Address incomplete');
        app(UpdateShipmentStatus::class)($shipment, ShipmentStatus::Pending);
        app(MarkShipmentReady::class)($shipment);

        Carbon::setTestNow(Carbon::now()->addDays(3));
        app(DispatchShipment::class)($shipment);
        Carbon::setTestNow();

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::InTransit, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertTrue($shipment->shipped_at->greaterThan($firstShippedAt));
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

        try {
            app(MarkShipmentReady::class)($shipment);
            $this->fail('Expected InvalidShipmentStatusTransitionException.');
        } catch (InvalidShipmentStatusTransitionException) {
            // expected
        }

        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status,
            'Terminal status must not be overwritten when the guard rejects the transition.');
    }

    #[Test]
    public function lost_is_terminal(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Lost);

        try {
            app(MarkShipmentDelivered::class)($shipment);
            $this->fail('Expected InvalidShipmentStatusTransitionException.');
        } catch (InvalidShipmentStatusTransitionException) {
            // expected
        }

        $this->assertSame(ShipmentStatus::Lost, $shipment->fresh()->status);
    }

    #[Test]
    public function pending_cannot_jump_to_delivered(): void
    {
        $shipment = $this->shipment(ShipmentStatus::Pending);

        try {
            app(MarkShipmentDelivered::class)($shipment);
            $this->fail('Expected InvalidShipmentStatusTransitionException.');
        } catch (InvalidShipmentStatusTransitionException) {
            // expected
        }

        $this->assertSame(ShipmentStatus::Pending, $shipment->fresh()->status);
    }

    private function shipment(ShipmentStatus $status): Shipment
    {
        $order = Order::factory()->create();

        return Shipment::factory()->status($status)->for($order, 'shippable')->create();
    }
}
