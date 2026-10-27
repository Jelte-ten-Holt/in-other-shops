<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Resources\Read;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InOtherShops\Agent\Http\Support\ReadFormat;
use InOtherShops\Purchasing\Models\PurchaseOrderLine;

/**
 * A purchase order with its lines, the supplier's invoice and our payment of
 * it, and the supplier. Left out on purpose: `notes` on both.
 *
 * @mixin \InOtherShops\Purchasing\Models\PurchaseOrder
 */
final class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $supplier = $this->supplier;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => ReadFormat::value($this->status),
            'currency' => ReadFormat::value($this->currency),
            'ordered_at' => ReadFormat::at($this->ordered_at),
            'expected_delivery_at' => ReadFormat::day($this->expected_delivery_at),
            'shipping_cost' => $this->shipping_cost,
            'customs_cost' => $this->customs_cost,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'supplier_invoice_date' => ReadFormat::day($this->supplier_invoice_date),
            'supplier_invoice_total' => $this->supplier_invoice_total,
            'supplier_invoice_tax_summary' => $this->supplier_invoice_tax_summary === null
                ? null
                : ReadFormat::summary($this->supplierInvoiceTaxSummary()),
            'paid_at' => ReadFormat::at($this->paid_at),
            'amount_paid' => $this->amount_paid,
            'lines' => $this->lines->sortBy('id')->values()->map(fn (PurchaseOrderLine $line): array => [
                'id' => $line->id,
                'purchasable_type' => $line->purchasable_type,
                'purchasable_id' => $line->purchasable_id,
                'description' => $line->description,
                'sku' => $line->sku,
                'quantity_ordered' => $line->quantity_ordered,
                'quantity_received' => $line->quantity_received,
                'unit_cost' => $line->unit_cost,
                'input_vat' => $line->input_vat,
                'tax_category' => ReadFormat::value($line->tax_category),
                'line_cost' => $line->line_cost,
            ])->all(),
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contact_email' => $supplier->contact_email,
                'default_currency' => ReadFormat::value($supplier->default_currency),
                'payment_terms' => $supplier->payment_terms,
                'vat_id' => $supplier->vat_id,
                'country_code' => $supplier->country_code,
                'address' => $supplier->address_line_1 === null ? null : [
                    'line_1' => $supplier->address_line_1,
                    'line_2' => $supplier->address_line_2,
                    'city' => $supplier->city,
                    'postal_code' => $supplier->postal_code,
                    'country_code' => $supplier->country_code,
                ],
            ],
            'created_at' => ReadFormat::at($this->created_at),
            'updated_at' => ReadFormat::at($this->updated_at),
        ];
    }
}
