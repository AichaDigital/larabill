<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\UserRelationshipType;

describe('UserRelationshipType Enum', function () {
    it('has correct values', function () {
        expect(UserRelationshipType::DIRECT->value)->toBe(0);
        expect(UserRelationshipType::DELEGATED->value)->toBe(1);
    });

    it('returns correct labels', function () {
        expect(UserRelationshipType::DIRECT->getLabel())->toBeString()->not->toBeEmpty();
        expect(UserRelationshipType::DELEGATED->getLabel())->toBeString()->not->toBeEmpty();
    });

    it('returns correct colors', function () {
        expect(UserRelationshipType::DIRECT->getColor())->toBe('success');
        expect(UserRelationshipType::DELEGATED->getColor())->toBe('info');
    });

    it('returns correct icons', function () {
        expect(UserRelationshipType::DIRECT->getIcon())->toContain('heroicon');
        expect(UserRelationshipType::DELEGATED->getIcon())->toContain('heroicon');
    });

    it('returns correct descriptions', function () {
        expect(UserRelationshipType::DIRECT->description())->toContain('Direct');
        expect(UserRelationshipType::DELEGATED->description())->toContain('Delegated');
    });

    it('identifies direct correctly', function () {
        expect(UserRelationshipType::DIRECT->isDirect())->toBeTrue();
        expect(UserRelationshipType::DELEGATED->isDirect())->toBeFalse();
    });

    it('identifies delegated correctly', function () {
        expect(UserRelationshipType::DIRECT->isDelegated())->toBeFalse();
        expect(UserRelationshipType::DELEGATED->isDelegated())->toBeTrue();
    });

    it('converts to array', function () {
        $array = UserRelationshipType::toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(2);
        expect($array)->toHaveKeys([0, 1]);
    });

    it('has all expected cases', function () {
        expect(UserRelationshipType::cases())->toHaveCount(2);
    });
});
