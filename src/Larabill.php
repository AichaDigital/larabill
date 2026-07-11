<?php

declare(strict_types=1);

namespace AichaDigital\Larabill;

/**
 * Larabill Main Class
 *
 * Professional billing and invoicing module for Laravel.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class Larabill
{
    /**
     * Get package version.
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Get package description.
     */
    public static function description(): string
    {
        return 'Professional Billing & Invoicing for Laravel';
    }
}
