<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Purchasing\Filament\Resources\PurchaseOrderResource\Pages\EditPurchaseOrder;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Tests\Support\BootsFilament;
use InOtherShops\Tests\TestCase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The supplier-invoice section of the purchase-order edit page, driven through
 * Livewire: what an admin types, and what they are told when it cannot be
 * stored. The rules themselves are SupplierInvoiceTest's.
 */
final class EditPurchaseOrderInvoiceTest extends TestCase
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
    public function an_invoice_with_its_rates_is_stored(): void
    {
        $order = PurchaseOrder::factory()->create();

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()])
            ->fillForm([
                'supplier_invoice_number' => 'INV-4471',
                'supplier_invoice_date' => '2026-11-05',
                'supplier_invoice_total' => '114.12',
                'supplier_invoice_tax_summary' => [['rate_bps' => '19', 'taxable_base' => '95.90', 'tax' => '18.22']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $order->fresh();
        $this->assertSame(11412, $stored->supplier_invoice_total);
        $this->assertSame([['rate_bps' => 1900, 'taxable_base' => 9590, 'tax' => 1822]], TaxBreakdownLine::serializeMany($stored->supplierInvoiceTaxSummary()));
    }

    #[Test]
    public function an_invoice_without_vat_rows_is_a_field_error_not_an_error_page(): void
    {
        $order = PurchaseOrder::factory()->create();

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()])
            ->fillForm([
                'supplier_invoice_number' => 'INV-1',
                'supplier_invoice_date' => '2026-11-05',
                'supplier_invoice_total' => '114.12',
            ])
            ->call('save')
            ->assertHasFormErrors(['supplier_invoice_tax_summary']);

        $this->assertNull($order->fresh()->supplier_invoice_number);
    }

    #[Test]
    public function a_total_the_rates_do_not_add_up_to_is_a_field_error(): void
    {
        $order = PurchaseOrder::factory()->create();

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()])
            ->fillForm([
                'supplier_invoice_number' => 'INV-1',
                'supplier_invoice_date' => '2026-11-05',
                'supplier_invoice_total' => '114.00',
                'supplier_invoice_tax_summary' => [['rate_bps' => '19', 'taxable_base' => '95.90', 'tax' => '18.22']],
            ])
            ->call('save')
            ->assertHasFormErrors(['supplier_invoice_total']);

        $this->assertNull($order->fresh()->supplier_invoice_total);
    }

    #[Test]
    public function a_refusal_the_form_lets_through_is_a_notification_and_writes_nothing(): void
    {
        $order = PurchaseOrder::factory()->create();

        // A date and a total but no invoice number: the form requires the rest
        // only once there is a number; the model refuses half an invoice.
        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()])
            ->fillForm([
                'supplier_invoice_date' => '2026-11-05',
                'supplier_invoice_total' => '114.12',
            ])
            ->call('save')
            ->assertNotified(__('shops-purchasing::purchaseorder.notifications.invoice_refused'));

        $this->assertNull($order->fresh()->supplier_invoice_date);
    }
}
