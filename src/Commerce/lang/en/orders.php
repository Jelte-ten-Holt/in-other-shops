<?php

declare(strict_types=1);

/**
 * Order admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Status, Currency, Email, …) come from
 * `shops-common::fields.*`.
 */
return [
    'tabs' => [
        'details' => 'Details',
        'order_lines' => 'Order Lines',
        'addresses' => 'Addresses',
    ],
    'fields' => [
        'item' => 'Item',
        'unit_price' => 'Unit price',
        'line_total' => 'Line total',
        'customer' => 'Customer',
        'order_number' => 'Order number',
        'subtotal' => 'Subtotal',
        'tax' => 'Tax',
        'discount' => 'Discount',
        'total' => 'Total',
        'shipping_cost' => 'Shipping cost',
        'new_status' => 'New Status',
        'reason' => 'Reason',
        'refund_amount' => 'Amount to refund (minor units)',
        'refund_amount_help' => 'Leave blank for the full remaining balance.',
        'restock' => 'Restock these items (optional)',
        'restock_help' => 'Releases the chosen reservations back to available stock.',
    ],
    'columns' => [
        'refund' => 'Refund',
        'shipping' => 'Shipping',
    ],
    'placeholders' => [
        'guest' => 'Guest',
        'guest_no_customer' => 'Guest (no customer)',
    ],
    'refund_state' => [
        'refunded' => 'Refunded',
        'partial' => 'Partial',
    ],
    'actions' => [
        'update_status' => 'Update Status',
        'partial_refund' => 'Partial refund',
        'refund_and_cancel' => 'Refund & cancel order',
        'refund_and_cancel_modal' => 'Refunds the full remaining balance, cancels the order, and releases all its reserved stock.',
    ],
    'notifications' => [
        'fully_refunded_title' => 'This order is fully refunded',
        'fully_refunded_body' => 'It is still Confirmed — do not fulfil or ship it without checking.',
        'partially_refunded_title' => 'This order is partially refunded',
        'refund_refused' => 'Refund refused',
        'refund_failed' => 'Refund failed',
        'refund_issued' => 'Refund issued',
    ],
    'address' => [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'line_1' => 'Address line 1',
        'line_2' => 'Address line 2',
        'city' => 'City',
        'state' => 'State',
        'postal_code' => 'Postal code',
        'phone' => 'Phone',
        'address' => 'Address',
    ],
];
