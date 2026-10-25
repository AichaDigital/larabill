<?php

declare(strict_types=1);

return [
    'item_type' => [
        'good'    => 'Good',
        'service' => 'Service',
    ],

    'serie' => [
        'proforma'      => 'Proforma',
        'invoice'       => 'Invoice',
        'rectificative' => 'Rectificative',
    ],

    'status' => [
        'draft'          => 'Draft',
        'sent'           => 'Sent',
        'paid'           => 'Paid',
        'overdue'        => 'Overdue',
        'cancelled'      => 'Cancelled',
        'pending'        => 'Pending',
        'converted'      => 'Converted',
        'converting'     => 'Converting',
        'partially_paid' => 'Partially paid',
    ],

    'grouped_payment_status' => [
        'posted'   => 'Posted',
        'reversed' => 'Reversed',
    ],

    'payment_method' => [
        'bank_transfer' => 'Bank transfer',
        'card'          => 'Card',
        'cash'          => 'Cash',
        'other'         => 'Other',
    ],

    'allocation_status' => [
        'active'   => 'Active',
        'reversed' => 'Reversed',
    ],

    'credit_movement_kind' => [
        'surplus'  => 'Surplus',
        'top_up'   => 'Top-up',
        'applied'  => 'Applied',
        'refunded' => 'Refunded',
    ],

    'unit_category' => [
        'count'  => 'Count',
        'weight' => 'Weight',
        'volume' => 'Volume',
        'length' => 'Length',
        'area'   => 'Area',
        'time'   => 'Time',
    ],

    'user_relationship' => [
        'direct'    => 'Direct Client',
        'delegated' => 'Delegated Client',
    ],
];
