<?php

declare(strict_types=1);

return [
    'fiscal_integrity' => [
        'company_duplicate' => 'CRÍTICO: Se han detectado múltiples configuraciones fiscales de empresa activas (IDs: :ids). La facturación está bloqueada hasta que se resuelva.',
        'user_duplicate'    => 'CRÍTICO: Se han detectado múltiples perfiles fiscales activos para el usuario :user_id (IDs: :ids). La facturación a este usuario está bloqueada.',
    ],
];
