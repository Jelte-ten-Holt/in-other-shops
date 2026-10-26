<?php

declare(strict_types=1);

/**
 * Purchase order admin strings — `en` source of truth. Domain-specific strings
 * only; recurring field labels (Reference, Status, Notes, Created at) come from
 * `shops-common::fields.*`.
 */
return [
    'model' => 'purchase order',
    'model_plural' => 'purchase orders',
    'nav' => 'Purchase Orders',

    'section' => [
        'details' => 'Details',
        'lines' => 'Lines',
        'supplier_invoice' => 'Supplier invoice',
        'payment' => 'Payment',
    ],
    'fields' => [
        'supplier' => 'Supplier',
        'expected_delivery' => 'Expected delivery',
        'shipping_cost' => 'Shipping cost',
        'customs_cost' => 'Customs cost',
        'supplier_invoice_number' => 'Invoice number',
        'supplier_invoice_date' => 'Invoice date',
        'supplier_invoice_total' => 'Invoice total (gross)',
        'supplier_invoice_total_help' => 'As printed on the invoice. It must equal the net plus VAT of the rates below.',
        'supplier_invoice_total_mismatch' => 'The rates below add up to :summed (net plus VAT), not this total.',
        'supplier_invoice_tax_summary' => 'VAT per rate',
        'supplier_invoice_tax_summary_help' => 'One row per VAT rate on the invoice. An invoice without VAT (reverse charge, a small business) is one row at 0 %.',
        'vat_rate' => 'VAT rate',
        'net' => 'Net',
        'vat' => 'VAT',
        'paid_on' => 'Paid on',
        'amount_paid' => 'Amount paid',
    ],
    'columns' => [
        'total' => 'Total',
        'expected' => 'Expected',
    ],
    'actions' => [
        'place' => 'Mark ordered',
        'receive' => 'Receive',
        'cancel' => 'Cancel',
        'add_rate' => 'Add a VAT rate',
    ],
    'notifications' => [
        'placed' => 'Purchase order placed',
        'nothing_to_receive' => 'Nothing to receive',
        'items_received' => 'Items received',
        'receive_failed' => 'Could not receive items',
        'cancelled' => 'Purchase order cancelled',
        'cancel_failed' => 'Could not cancel',
        'invoice_refused' => 'The supplier invoice was not saved',
    ],
];
