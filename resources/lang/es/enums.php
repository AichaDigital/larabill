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
        'draft'          => 'Borrador',
        'sent'           => 'Enviada',
        'paid'           => 'Pagada',
        'overdue'        => 'Vencida',
        'cancelled'      => 'Cancelada',
        'pending'        => 'Pendiente',
        'converted'      => 'Convertida',
        'converting'     => 'Convirtiendo',
        'partially_paid' => 'Cobrada parcialmente',
    ],

    'grouped_payment_status' => [
        'posted'   => 'Registrado',
        'reversed' => 'Revertido',
    ],

    'payment_method' => [
        'bank_transfer' => 'Transferencia',
        'card'          => 'Tarjeta',
        'cash'          => 'Efectivo',
        'other'         => 'Otro',
    ],

    'allocation_status' => [
        'active'   => 'Activa',
        'reversed' => 'Revertida',
    ],

    'credit_movement_kind' => [
        'surplus'  => 'Sobrante',
        'top_up'   => 'Recarga',
        'applied'  => 'Aplicado',
        'refunded' => 'Devuelto',
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
