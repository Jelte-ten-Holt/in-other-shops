<?php

declare(strict_types=1);

/**
 * Order admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Status, Currency, Email, …) come from
 * `shops-common::fields.*`.
 */
return [
    'model' => 'order',
    'model_plural' => 'orders',
    'nav' => 'Orders',
    'relation_title' => 'Orders',

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
        'voucher_code' => 'Voucher code',
        'paid_on' => 'Paid on',
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
        'mark_paid' => 'Mark paid',
        'mark_paid_modal' => 'Records a payment of the full total, received outside the card checkout (a bank transfer), on the day you give. The order is then handled as if paid by card: it is confirmed and the customer is sent the order confirmation. Its prices, lines and addresses can no longer be changed afterwards.',
    ],
    'notifications' => [
        'fully_refunded_title' => 'This order is fully refunded',
        'fully_refunded_body' => 'It is still Confirmed — do not fulfil or ship it without checking.',
        'partially_refunded_title' => 'This order is partially refunded',
        'refund_refused' => 'Refund refused',
        'refund_failed' => 'Refund failed',
        'refund_issued' => 'Refund issued',
        'marked_paid' => 'Payment recorded',
        'mark_paid_refused' => 'Could not record the payment',
        'frozen_title' => 'This order is paid',
        'frozen_body' => 'Its prices, lines and addresses are the record of the sale and can no longer be changed. Notes and status still can.',
        'status_updated' => 'Order status updated to :status',
        'status_refused' => 'Status not changed',
    ],
];
