<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Payment\Enums\PaymentStatus;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Shipping\Models\Shipment;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Audit M5 — shipments dispatched against an unpaid payable break invoicing
 * and customer trust. The model-level guard is duck-typed so Shipping doesn't
 * gain a hard dependency on Payment in the domain graph.
 */
final class ShipmentShippableIsPaidTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_false_when_the_shippable_has_no_succeeded_payments_against_a_nonzero_due(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 2500]);

        $shipment = Shipment::factory()->for($payable, 'shippable')->create();

        $this->assertFalse($shipment->shippableIsPaid());
    }

    #[Test]
    public function it_returns_true_when_succeeded_payments_cover_the_total_due(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 2500]);

        Payment::factory()->for($payable, 'payable')->create([
            'amount' => 2500,
            'currency' => Currency::EUR,
            'status' => PaymentStatus::Succeeded,
        ]);

        $shipment = Shipment::factory()->for($payable, 'shippable')->create();

        $this->assertTrue($shipment->shippableIsPaid());
    }

    #[Test]
    public function it_is_permissive_when_the_shippable_does_not_implement_isPaid(): void
    {
        // A shippable that has no Payment integration at all (no isPaid method)
        // should be allowed to dispatch. Otherwise the guard would block a
        // domain that ships things outside the Order/Payment flow.
        $shippable = \InOtherShops\Tests\Stubs\TestShippableCartable::factory()->create();
        $shipment = Shipment::factory()->for($shippable, 'shippable')->create();

        $this->assertFalse(method_exists($shippable, 'isPaid'),
            'Test stub must not implement isPaid for this case to be meaningful.');
        $this->assertTrue($shipment->shippableIsPaid());
    }

    #[Test]
    public function it_is_permissive_when_the_shippable_is_missing(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 2500]);
        $shipment = Shipment::factory()->for($payable, 'shippable')->create();

        // Orphan the relation — the payable was deleted but the shipment still
        // points at the now-missing row.
        $payable->delete();
        $shipment->refresh();

        $this->assertNull($shipment->shippable);
        $this->assertTrue($shipment->shippableIsPaid());
    }
}
