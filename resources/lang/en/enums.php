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
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'paid'      => 'Paid',
        'overdue'   => 'Overdue',
        'cancelled' => 'Cancelled',
        'pending'   => 'Pending',
        'converted' => 'Converted',
    ],

    'grouped_payment_status' => [
        'posted'   => 'Posted',
        'reversed' => 'Reversed',
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
