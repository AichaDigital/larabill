<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

enum GroupedPaymentStatus: int
{
    case POSTED   = 0;
    case REVERSED = 1;

    public function label(): string
    {
        return match ($this) {
            self::POSTED   => __('larabill::enums.grouped_payment_status.posted'),
            self::REVERSED => __('larabill::enums.grouped_payment_status.reversed'),
        };
    }

    /** @return array<int, string> */
    public static function toArray(): array
    {
        return [
            self::POSTED->value   => self::POSTED->label(),
            self::REVERSED->value => self::REVERSED->label(),
        ];
    }
}
