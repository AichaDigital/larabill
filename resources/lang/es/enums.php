<?php

declare(strict_types=1);

return [
    'item_type' => [
        'good'    => 'Bien',
        'service' => 'Servicio',
    ],

    'serie' => [
        'proforma'      => 'Proforma',
        'invoice'       => 'Factura',
        'rectificative' => 'Rectificativa',
    ],

    'status' => [
        'draft'     => 'Borrador',
        'sent'      => 'Enviada',
        'paid'      => 'Pagada',
        'overdue'   => 'Vencida',
        'cancelled' => 'Cancelada',
        'pending'   => 'Pendiente',
        'converted' => 'Convertida',
    ],

    'unit_category' => [
        'count'  => 'Unidad',
        'weight' => 'Peso',
        'volume' => 'Volumen',
        'length' => 'Longitud',
        'area'   => 'Área',
        'time'   => 'Tiempo',
    ],

    'user_relationship' => [
        'direct'    => 'Cliente Directo',
        'delegated' => 'Cliente Delegado',
    ],
];
