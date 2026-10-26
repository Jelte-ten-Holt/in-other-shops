<?php

declare(strict_types=1);

namespace InOtherShops\Purchasing\Models;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\DTOs\TaxBreakdownLine;
use InOtherShops\Purchasing\Database\Factories\PurchaseOrderFactory;
use InOtherShops\Purchasing\Enums\PurchaseOrderStatus;
use InOtherShops\Purchasing\Exceptions\SupplierInvoiceException;
use InOtherShops\Purchasing\Purchasing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inbound purchase order: what we bought from a supplier, at what (net) cost,
 * and where it is in the order → receive lifecycle. Monetary fields are integer
 * cents, net of reclaimable VAT (input VAT lives per line). `total` is the net
 * landed cost: line subtotal + shipping + customs.
 *
 * The supplier's invoice and our payment of it ride on the order (bookkeeping
 * brief §12 P-4). Two invariants hold on every save: the invoice is whole or
 * absent, its gross total being the sum of its per-rate net and tax; and a
 * payment has both its date and a positive amount.
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static string $factory = PurchaseOrderFactory::class;

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'currency' => Currency::class,
            'ordered_at' => 'datetime',
            'expected_delivery_at' => 'date',
            'shipping_cost' => 'integer',
            'customs_cost' => 'integer',
            'subtotal' => 'integer',
            'total' => 'integer',
            'supplier_invoice_date' => 'date',
            'supplier_invoice_total' => 'integer',
            'supplier_invoice_tax_summary' => 'array',
            'paid_at' => 'datetime',
            'amount_paid' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrder $order): void {
            $order->guardSupplierInvoice();
            $order->guardPayment();
        });
    }

    /**
     * The supplier invoice's VAT per rate bracket, the shape orders use.
     *
     * @return list<TaxBreakdownLine>
     */
    public function supplierInvoiceTaxSummary(): array
    {
        return TaxBreakdownLine::listFromRows($this->supplier_invoice_tax_summary);
    }

    /**
     * Normalises the summary through TaxBreakdownLine (integer cents, one key
     * order; an empty list is no summary) and refuses a partial invoice or one
     * whose total its summary does not add up to.
     */
    private function guardSupplierInvoice(): void
    {
        $summary = $this->supplierInvoiceTaxSummary();
        $this->supplier_invoice_tax_summary = $summary === [] ? null : TaxBreakdownLine::serializeMany($summary);

        $parts = [
            $this->supplier_invoice_number,
            $this->supplier_invoice_date,
            $this->supplier_invoice_total,
            $this->supplier_invoice_tax_summary,
        ];
        $present = count(array_filter($parts, fn (mixed $part): bool => $part !== null && $part !== ''));

        if ($present === 0) {
            return;
        }

        if ($present < count($parts)) {
            throw SupplierInvoiceException::incomplete();
        }

        $summed = 0;

        foreach ($summary as $line) {
            if ($line->rateBps < 0 || $line->taxableBase < 0 || $line->tax < 0) {
                throw SupplierInvoiceException::negativeAmount();
            }

            $summed += $line->taxableBase + $line->tax;
        }

        if ($summed !== $this->supplier_invoice_total) {
            throw SupplierInvoiceException::totalDisagrees($this->supplier_invoice_total, $summed);
        }
    }

    private function guardPayment(): void
    {
        if ($this->paid_at === null && $this->amount_paid === null) {
            return;
        }

        if ($this->paid_at === null || $this->amount_paid === null || $this->amount_paid <= 0) {
            throw SupplierInvoiceException::paymentIncomplete();
        }
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Purchasing::supplier());
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Purchasing::purchaseOrderLine());
    }
}
