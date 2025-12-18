<?php

declare(strict_types=1);

return [
    'fiscal_integrity' => [
        'subject_global' => '[CRÍTICO] Integridad Fiscal Comprometida - Facturación Bloqueada',
        'subject_atomic' => '[URGENTE] Problema de Integridad Fiscal en Usuario',
        'greeting'       => 'Se ha detectado un problema crítico de integridad fiscal.',
        'global_impact'  => 'IMPACTO: TODA la facturación está bloqueada hasta que se resuelva este problema.',
        'atomic_impact'  => 'IMPACTO: La facturación al usuario :user_id está bloqueada hasta que se resuelva.',
        'duplicate_ids'  => 'Configuraciones duplicadas detectadas (IDs): :ids',
        'action'         => 'Resolver en Panel de Administración',
        'urgency'        => 'Este problema requiere atención INMEDIATA. Las facturas no pueden emitirse hasta que se corrija.',
    ],
];
