<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum AllocationStatus: int
{
    case ACTIVE   = 0;
    case REVERSED = 1;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE   => __('larabill::enums.allocation_status.active'),
            self::REVERSED => __('larabill::enums.allocation_status.reversed'),
        };
    }

    /** @return array<int, string> */
    public static function toArray(): array
    {
        return [
            self::ACTIVE->value   => self::ACTIVE->label(),
            self::REVERSED->value => self::REVERSED->label(),
        ];
    }
}
