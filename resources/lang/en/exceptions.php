<?php

declare(strict_types=1);

return [
    'fiscal_integrity' => [
        'company_duplicate' => 'CRITICAL: Multiple active company fiscal configurations detected (IDs: :ids). Invoicing is blocked until resolved.',
        'user_duplicate'    => 'CRITICAL: Multiple active tax profiles detected for user :user_id (IDs: :ids). Invoicing to this user is blocked.',
    ],
];
