<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Payment;

use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Stubs\TestPayable;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class IsPaidTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_is_unpaid_when_there_are_no_payments(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        $this->assertSame(0, $payable->totalPaid());
        $this->assertFalse($payable->isPaid());
    }

    #[Test]
    public function it_is_unpaid_when_only_pending_payments_exist(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->create(['amount' => 1000]);

        $this->assertSame(0, $payable->totalPaid());
        $this->assertFalse($payable->isPaid());
    }

    #[Test]
    public function it_is_unpaid_when_single_succeeded_payment_is_less_than_total_due(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->succeeded()->create(['amount' => 400]);

        $this->assertSame(400, $payable->totalPaid());
        $this->assertFalse($payable->isPaid(),
            'Partial payment must not mark the payable as fully paid (the old bug).');
    }

    #[Test]
    public function it_is_paid_when_succeeded_payment_equals_total_due(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->succeeded()->create(['amount' => 1000]);

        $this->assertSame(1000, $payable->totalPaid());
        $this->assertTrue($payable->isPaid());
    }

    #[Test]
    public function it_is_paid_when_multiple_succeeded_payments_sum_to_total_due(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->succeeded()->create(['amount' => 400]);
        Payment::factory()->for($payable, 'payable')->succeeded()->create(['amount' => 600]);

        $this->assertSame(1000, $payable->totalPaid());
        $this->assertTrue($payable->isPaid());
    }

    #[Test]
    public function it_is_paid_when_succeeded_payments_overshoot_total_due(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->succeeded()->create(['amount' => 1500]);

        $this->assertTrue($payable->isPaid());
    }

    #[Test]
    public function failed_and_cancelled_payments_do_not_contribute(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->failed()->create(['amount' => 1000]);

        $this->assertSame(0, $payable->totalPaid());
        $this->assertFalse($payable->isPaid());
    }

    #[Test]
    public function a_fully_refunded_payment_contributes_zero(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->refunded()->create(['amount' => 1000]);

        $this->assertSame(0, $payable->totalPaid(),
            'Refunded status is excluded from totalPaid; amount_refunded equals amount anyway.');
        $this->assertFalse($payable->isPaid());
    }

    #[Test]
    public function a_partially_refunded_payment_contributes_its_net(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')
            ->partiallyRefunded(refunded: 300)
            ->create(['amount' => 1000]);

        $this->assertSame(700, $payable->totalPaid());
        $this->assertFalse($payable->isPaid(),
            'Net 700 does not meet the 1000 total due.');
    }

    #[Test]
    public function a_succeeded_plus_partially_refunded_sum_their_nets(): void
    {
        $payable = TestPayable::factory()->create(['total_due' => 1000]);

        Payment::factory()->for($payable, 'payable')->succeeded()->create(['amount' => 600]);
        Payment::factory()->for($payable, 'payable')
            ->partiallyRefunded(refunded: 100)
            ->create(['amount' => 500]);

        $this->assertSame(1000, $payable->totalPaid(),
            '600 + (500 - 100) = 1000.');
        $this->assertTrue($payable->isPaid());
    }
}
