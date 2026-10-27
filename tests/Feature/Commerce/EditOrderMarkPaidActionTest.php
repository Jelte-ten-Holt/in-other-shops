<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\EditOrder;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Payment\Models\Payment;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;

/**
 * "Mark paid" on the order edit page, driven through Livewire, and the refund
 * actions staying away from a manual payment. The rules are
 * RecordManualPaymentTest's.
 */
final class EditOrderMarkPaidActionTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'UTC'));
    }

    #[Test]
    public function marking_an_order_paid_records_the_claimed_day_and_says_so(): void
    {
        $order = Order::factory()->create(['total' => 9590]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->callAction('markPaid', data: ['paid_on' => '2026-11-23'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('shops-commerce::orders.notifications.marked_paid'));

        $payment = $order->payments()->sole();
        $this->assertSame('manual', $payment->gateway);
        $this->assertSame('2026-11-23T00:00:00+00:00', $payment->paid_at->utc()->toIso8601String());
    }

    #[Test]
    public function a_refused_mark_paid_is_a_notification_not_an_error_page(): void
    {
        $order = Order::factory()->create(['total' => 9590]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->callAction('markPaid', data: ['paid_on' => '2026-12-24'])
            ->assertNotified(__('shops-commerce::orders.notifications.mark_paid_refused'));

        $this->assertSame(0, $order->payments()->count());
    }

    #[Test]
    public function mark_paid_is_offered_on_an_unpaid_order_and_withdrawn_once_it_is_paid(): void
    {
        $order = Order::factory()->create(['total' => 9590]);
        $this->assertSame('markPaid', EditOrder::markPaidAction()->getName());
        $this->assertTrue($this->visible(EditOrder::markPaidAction(), $order));

        Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => 9590, 'gateway' => 'manual']);

        $this->assertFalse($this->visible(EditOrder::markPaidAction(), $order->fresh()));
    }

    #[Test]
    public function the_refund_actions_are_not_offered_for_a_manual_payment(): void
    {
        $order = Order::factory()->create(['total' => 9590]);
        Payment::factory()->succeeded()->for($order, 'payable')->create(['amount' => 9590, 'gateway' => 'manual']);

        $this->assertFalse($this->visible(EditOrder::partialRefundAction(), $order));
        $this->assertFalse($this->visible(EditOrder::refundAndCancelAction(), $order));
    }

    private function visible(Action $action, Order $order): bool
    {
        $property = (new ReflectionObject($action))->getProperty('isVisible');
        $value = $property->getValue($action);

        return $value instanceof Closure ? (bool) $value($order) : (bool) $value;
    }
}
