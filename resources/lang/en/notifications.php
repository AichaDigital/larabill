<?php

declare(strict_types=1);

return [
    'fiscal_integrity' => [
        'subject_global' => '[CRITICAL] Fiscal Integrity Compromised - Invoicing Blocked',
        'subject_atomic' => '[URGENT] User Fiscal Integrity Issue',
        'greeting'       => 'A critical fiscal integrity issue has been detected.',
        'global_impact'  => 'IMPACT: ALL invoicing is blocked until this issue is resolved.',
        'atomic_impact'  => 'IMPACT: Invoicing to user :user_id is blocked until resolved.',
        'duplicate_ids'  => 'Duplicate configurations detected (IDs): :ids',
        'action'         => 'Resolve in Admin Panel',
        'urgency'        => 'This issue requires IMMEDIATE attention. Invoices cannot be issued until corrected.',
    ],
];
