<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum CreditMovementKind: int
{
    case SURPLUS  = 0;
    case TOP_UP   = 1;
    case APPLIED  = 2;
    case REFUNDED = 3;

    public function isEntry(): bool
    {
        return match ($this) {
            self::SURPLUS, self::TOP_UP   => true,
            self::APPLIED, self::REFUNDED => false,
        };
    }

    public function isExit(): bool
    {
        return ! $this->isEntry();
    }

    public function label(): string
    {
        return match ($this) {
            self::SURPLUS  => __('larabill::enums.credit_movement_kind.surplus'),
            self::TOP_UP   => __('larabill::enums.credit_movement_kind.top_up'),
            self::APPLIED  => __('larabill::enums.credit_movement_kind.applied'),
            self::REFUNDED => __('larabill::enums.credit_movement_kind.refunded'),
        };
    }

    /** @return array<int, string> */
    public static function toArray(): array
    {
        return [
            self::SURPLUS->value  => self::SURPLUS->label(),
            self::TOP_UP->value   => self::TOP_UP->label(),
            self::APPLIED->value  => self::APPLIED->label(),
            self::REFUNDED->value => self::REFUNDED->label(),
        ];
    }
}
