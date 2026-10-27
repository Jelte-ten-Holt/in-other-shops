<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Commerce;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Commerce\Filament\Resources\OrderResource\Pages\EditOrder;
use InOtherShops\Commerce\Order\Models\Order;
use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package order page, mounted without a consumer override, must be able to
 * save an order. Until v0.72.0 `currencyOptions()` was `[]`, and the required
 * currency select validated against no options, so every save failed.
 */
final class EditOrderCurrencyTest extends TestCase
{
    use BootsFilament;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn (): bool => true);
        $this->actingAs(new GenericUser(['id' => 1]));
    }

    #[Test]
    public function an_order_saves_on_the_package_page_with_every_currency_enabled(): void
    {
        config(['currency.enabled' => null]);
        $order = Order::factory()->create(['currency' => Currency::EUR]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldExists('currency')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Currency::EUR, $order->fresh()->currency);
    }

    #[Test]
    public function one_enabled_currency_fixes_the_field_and_the_order_still_saves(): void
    {
        config(['currency.enabled' => ['EUR']]);
        $order = Order::factory()->create(['currency' => Currency::EUR]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldExists('currency_display')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Currency::EUR, $order->fresh()->currency);
    }

    #[Test]
    public function the_offered_currencies_are_the_enabled_ones(): void
    {
        config(['currency.enabled' => ['EUR', 'USD']]);
        $order = Order::factory()->create(['currency' => Currency::USD]);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertFormFieldExists('currency')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Currency::USD, $order->fresh()->currency);
    }
}
