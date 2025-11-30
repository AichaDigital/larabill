<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Setting Type Enum
 *
 * Represents the type of company template setting.
 */
enum SettingType: int
{
    case TEMPLATE      = 0;  // Invoice template setting
    case NOTES         = 1;  // Invoice notes setting
    case PAYMENT_TERMS = 2;  // Payment terms setting

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::TEMPLATE      => 'Template',
            self::NOTES         => 'Notes',
            self::PAYMENT_TERMS => 'Payment Terms',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::TEMPLATE      => 'Invoice template configuration.',
            self::NOTES         => 'Default notes to include on invoices.',
            self::PAYMENT_TERMS => 'Payment terms and conditions.',
        };
    }
}
