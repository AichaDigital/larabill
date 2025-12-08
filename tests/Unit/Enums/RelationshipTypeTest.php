<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\RelationshipType;

describe('RelationshipType Enum', function () {
    it('has correct values', function () {
        expect(RelationshipType::SELF->value)->toBe(0);
        expect(RelationshipType::SELF_COMPANY->value)->toBe(1);
        expect(RelationshipType::CLIENT->value)->toBe(2);
        expect(RelationshipType::OTHER->value)->toBe(3);
    });

    it('returns correct labels', function () {
        expect(RelationshipType::SELF->label())->toContain('Self');
        expect(RelationshipType::SELF_COMPANY->label())->toContain('Company');
        expect(RelationshipType::CLIENT->label())->toBe('Client');
        expect(RelationshipType::OTHER->label())->toBe('Other');
    });

    it('returns correct descriptions', function () {
        foreach (RelationshipType::cases() as $type) {
            expect($type->description())->toBeString()->not->toBeEmpty();
        }
    });

    it('identifies self types correctly', function () {
        expect(RelationshipType::SELF->isSelf())->toBeTrue();
        expect(RelationshipType::SELF_COMPANY->isSelf())->toBeTrue();
        expect(RelationshipType::CLIENT->isSelf())->toBeFalse();
        expect(RelationshipType::OTHER->isSelf())->toBeFalse();
    });

    it('returns self types array', function () {
        $selfTypes = RelationshipType::selfTypes();

        expect($selfTypes)->toBeArray();
        expect($selfTypes)->toHaveCount(2);
        expect($selfTypes)->toContain(RelationshipType::SELF);
        expect($selfTypes)->toContain(RelationshipType::SELF_COMPANY);
    });

    it('has all expected cases', function () {
        expect(RelationshipType::cases())->toHaveCount(4);
    });
});
