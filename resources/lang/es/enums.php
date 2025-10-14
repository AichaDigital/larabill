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
    ],

    'unit_category' => [
        'count'  => 'Unidad',
        'weight' => 'Peso',
        'volume' => 'Volumen',
        'length' => 'Longitud',
        'area'   => 'Área',
        'time'   => 'Tiempo',
    ],
];
