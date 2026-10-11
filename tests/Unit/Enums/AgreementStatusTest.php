<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;

describe('AgreementStatus Enum', function () {
    it('has exactly the five ADR-015 §3.1 lifecycle states', function () {
        $names = array_map(fn (AgreementStatus $case) => $case->name, AgreementStatus::cases());

        expect($names)->toBe(['PENDING', 'ACTIVE', 'SUSPENDED', 'GUARANTEE', 'CLOSED']);
    });

    it('is string-backed with the lowercase state values', function () {
        expect(AgreementStatus::PENDING->value)->toBe('pending')
            ->and(AgreementStatus::ACTIVE->value)->toBe('active')
            ->and(AgreementStatus::SUSPENDED->value)->toBe('suspended')
            ->and(AgreementStatus::GUARANTEE->value)->toBe('guarantee')
            ->and(AgreementStatus::CLOSED->value)->toBe('closed');
    });

    it('carries no other values than the lowercase strings of its case names', function () {
        foreach (AgreementStatus::cases() as $case) {
            expect($case->value)->toBeString()
                ->and($case->value)->toBe(strtolower($case->name));
        }
    });
});
