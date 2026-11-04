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
        'stock' => 'Stock',
    ],
    'stock_state' => [
        'stale' => 'Holding stock :days days',
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
        'mark_paid_modal' => 'Records a payment of the full total, received outside the card checkout (a bank transfer), on the day you give. The order is then handled as if paid by card: it is confirmed and the customer is sent the order confirmation. Its prices, lines and addresses can no longer be changed afterwards. Stock is not adjusted: this order holds no stock, so keep the pieces\' availability right yourself.',
        'mark_paid_modal_holding_stock' => 'Records a payment of the full total, received outside the card checkout (a bank transfer), on the day you give. The order is then handled as if paid by card: it is confirmed and the customer is sent the order confirmation. Its prices, lines and addresses can no longer be changed afterwards. The stock it holds is confirmed with the payment.',
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
        'frozen_body' => 'Its prices, lines and addresses are the record of the sale and can no longer be changed.',
        'status_updated' => 'Order status updated to :status',
        'status_refused' => 'Status not changed',
    ],
    'stock' => [
        'mode' => 'Stock',
        'modes' => [
            'stock' => 'Take from stock',
            'hold' => 'Take from a hold',
            'none' => 'Don\'t take stock',
            'none_help' => 'For an order whose stock was already corrected by hand.',
        ],
        'hold' => 'Hold',
        'split' => [
            'label' => 'Stock',
            'from_hold' => ':count from :hold',
            'from_stock' => ':count from stock',
            'backordered' => ':count backordered',
            'component' => ':item: :split',
        ],
        'errors' => [
            'short' => 'Only :available in stock.',
            'short_held' => ':available in stock; :held — choose “Take from a hold”.',
            'held_in' => ':count held in :hold',
            'short_after_hold' => ':from_hold from :hold; only :available in stock for the rest.',
        ],
        'lines_locked' => 'This order holds stock for its lines, so they are locked. To change them, delete the order and create it again.',
        'held_summary' => 'Stock held',
        'held_line' => ':count × :item (:source)',
        'from_stock' => 'from stock',
        'from_hold' => 'from :hold',
        'notifications' => [
            'refused' => 'Order not created',
            'try_again' => 'Someone else was changing the same stock. Nothing was created — try again.',
            'hold_missing' => 'The hold no longer exists.',
        ],
    ],
];
