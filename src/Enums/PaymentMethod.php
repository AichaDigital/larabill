<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum PaymentMethod: int
{
    case BankTransfer = 0;
    case Card         = 1;
    case Cash         = 2;
    case Other        = 3;

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => __('larabill::enums.payment_method.bank_transfer'),
            self::Card         => __('larabill::enums.payment_method.card'),
            self::Cash         => __('larabill::enums.payment_method.cash'),
            self::Other        => __('larabill::enums.payment_method.other'),
        };
    }

    /** @return array<int, string> */
    public static function toArray(): array
    {
        return [
            self::BankTransfer->value => self::BankTransfer->label(),
            self::Card->value         => self::Card->label(),
            self::Cash->value         => self::Cash->label(),
            self::Other->value        => self::Other->label(),
        ];
    }
}
