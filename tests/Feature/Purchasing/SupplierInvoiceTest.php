<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Purchasing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Purchasing\Exceptions\SupplierInvoiceException;
use InOtherShops\Purchasing\Models\PurchaseOrder;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The supplier invoice and its payment on a purchase order (bookkeeping brief
 * §12 P-4): whole or absent, and a total its rates add up to. Every refusal is
 * asserted to have written nothing.
 */
final class SupplierInvoiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_whole_invoice_is_stored_with_its_summary_as_integer_rows_in_one_key_order(): void
    {
        $order = PurchaseOrder::factory()->create();

        $order->update([
            ...$this->invoice(),
            // As a form posts it: numeric strings, keys in another order.
            'supplier_invoice_tax_summary' => [['tax' => '1822', 'rate_bps' => '1900', 'taxable_base' => '9590']],
        ]);

        $stored = $order->fresh();
        $this->assertSame('INV-4471', $stored->supplier_invoice_number);
        $this->assertSame('2026-11-05', $stored->supplier_invoice_date->toDateString());
        $this->assertSame(11412, $stored->supplier_invoice_total);
        $this->assertSame(
            '[{"rate_bps":1900,"taxable_base":9590,"tax":1822}]',
            $stored->getRawOriginal('supplier_invoice_tax_summary'),
        );
    }

    #[Test]
    public function an_order_without_an_invoice_stores_none_and_an_empty_summary_counts_as_none(): void
    {
        $order = PurchaseOrder::factory()->create(['supplier_invoice_tax_summary' => []]);

        $this->assertNull($order->fresh()->getRawOriginal('supplier_invoice_tax_summary'));
    }

    /** @return array<string, array{string}> */
    public static function invoiceParts(): array
    {
        return [
            'no number' => ['supplier_invoice_number'],
            'no date' => ['supplier_invoice_date'],
            'no total' => ['supplier_invoice_total'],
            'no summary' => ['supplier_invoice_tax_summary'],
        ];
    }

    #[Test]
    #[DataProvider('invoiceParts')]
    public function half_an_invoice_is_refused_and_nothing_is_written(string $missing): void
    {
        $order = PurchaseOrder::factory()->create();

        try {
            $order->update([...$this->invoice(), $missing => null]);
            $this->fail('Half an invoice was stored.');
        } catch (SupplierInvoiceException $e) {
            $this->assertStringContainsString('together', $e->getMessage());
        }

        $this->assertNull($order->fresh()->supplier_invoice_number);
    }

    #[Test]
    public function a_total_its_rates_do_not_add_up_to_is_refused_and_nothing_is_written(): void
    {
        $order = PurchaseOrder::factory()->create();

        $this->expectException(SupplierInvoiceException::class);
        $this->expectExceptionMessage('11400');

        try {
            $order->update([...$this->invoice(), 'supplier_invoice_total' => 11400]);
        } finally {
            $this->assertNull($order->fresh()->supplier_invoice_total);
        }
    }

    #[Test]
    public function a_negative_amount_in_the_summary_is_refused(): void
    {
        $order = PurchaseOrder::factory()->create();

        $this->expectException(SupplierInvoiceException::class);

        try {
            $order->update([
                ...$this->invoice(),
                'supplier_invoice_total' => 7768,
                'supplier_invoice_tax_summary' => [['rate_bps' => 1900, 'taxable_base' => 9590, 'tax' => -1822]],
            ]);
        } finally {
            $this->assertNull($order->fresh()->supplier_invoice_total);
        }
    }

    #[Test]
    public function a_payment_with_its_date_and_amount_is_stored(): void
    {
        $order = PurchaseOrder::factory()->create();

        $order->update(['paid_at' => CarbonImmutable::parse('2026-11-11 23:00:00', 'UTC'), 'amount_paid' => 11412]);

        $this->assertSame('2026-11-11 23:00:00', $order->fresh()->paid_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(11412, $order->fresh()->amount_paid);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function partialPayments(): array
    {
        return [
            'date without amount' => [['paid_at' => '2026-11-11 23:00:00']],
            'amount without date' => [['amount_paid' => 11412]],
            'zero amount' => [['paid_at' => '2026-11-11 23:00:00', 'amount_paid' => 0]],
            'negative amount' => [['paid_at' => '2026-11-11 23:00:00', 'amount_paid' => -100]],
        ];
    }

    /** @param  array<string, mixed>  $payment */
    #[Test]
    #[DataProvider('partialPayments')]
    public function a_payment_missing_its_date_or_a_positive_amount_is_refused_and_nothing_is_written(array $payment): void
    {
        $order = PurchaseOrder::factory()->create();

        try {
            $order->update($payment);
            $this->fail('A partial payment was stored.');
        } catch (SupplierInvoiceException) {
            // Refused, as it should be.
        }

        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull($order->fresh()->amount_paid);
    }

    /** @return array<string, mixed> */
    private function invoice(): array
    {
        return [
            'supplier_invoice_number' => 'INV-4471',
            'supplier_invoice_date' => '2026-11-05',
            'supplier_invoice_total' => 11412,
            'supplier_invoice_tax_summary' => [['rate_bps' => 1900, 'taxable_base' => 9590, 'tax' => 1822]],
        ];
    }
}
