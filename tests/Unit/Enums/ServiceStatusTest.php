<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ServiceStatus;

it('has correct values for each case', function () {
    expect(ServiceStatus::ACTIVE->value)->toBe(0)
        ->and(ServiceStatus::PENDING->value)->toBe(1)
        ->and(ServiceStatus::SUSPENDED->value)->toBe(2)
        ->and(ServiceStatus::CANCELLED->value)->toBe(3)
        ->and(ServiceStatus::EXPIRED->value)->toBe(4);
});

it('returns correct labels', function () {
    expect(ServiceStatus::ACTIVE->label())->toBe('Active')
        ->and(ServiceStatus::PENDING->label())->toBe('Pending')
        ->and(ServiceStatus::SUSPENDED->label())->toBe('Suspended')
        ->and(ServiceStatus::CANCELLED->label())->toBe('Cancelled')
        ->and(ServiceStatus::EXPIRED->label())->toBe('Expired');
});

it('identifies billable statuses correctly', function () {
    expect(ServiceStatus::ACTIVE->canBill())->toBeTrue()
        ->and(ServiceStatus::PENDING->canBill())->toBeFalse()
        ->and(ServiceStatus::SUSPENDED->canBill())->toBeFalse()
        ->and(ServiceStatus::CANCELLED->canBill())->toBeFalse()
        ->and(ServiceStatus::EXPIRED->canBill())->toBeFalse();
});

it('identifies active status correctly', function () {
    expect(ServiceStatus::ACTIVE->isActive())->toBeTrue()
        ->and(ServiceStatus::PENDING->isActive())->toBeFalse()
        ->and(ServiceStatus::SUSPENDED->isActive())->toBeFalse()
        ->and(ServiceStatus::CANCELLED->isActive())->toBeFalse()
        ->and(ServiceStatus::EXPIRED->isActive())->toBeFalse();
});

it('identifies final statuses correctly', function () {
    expect(ServiceStatus::ACTIVE->isFinal())->toBeFalse()
        ->and(ServiceStatus::PENDING->isFinal())->toBeFalse()
        ->and(ServiceStatus::SUSPENDED->isFinal())->toBeFalse()
        ->and(ServiceStatus::CANCELLED->isFinal())->toBeTrue()
        ->and(ServiceStatus::EXPIRED->isFinal())->toBeTrue();
});

it('returns correct colors for each status', function () {
    expect(ServiceStatus::ACTIVE->color())->toBe('green')
        ->and(ServiceStatus::PENDING->color())->toBe('yellow')
        ->and(ServiceStatus::SUSPENDED->color())->toBe('orange')
        ->and(ServiceStatus::CANCELLED->color())->toBe('red')
        ->and(ServiceStatus::EXPIRED->color())->toBe('gray');
});

it('returns correct icons for each status', function () {
    expect(ServiceStatus::ACTIVE->icon())->toBe('check-circle')
        ->and(ServiceStatus::PENDING->icon())->toBe('clock')
        ->and(ServiceStatus::SUSPENDED->icon())->toBe('pause-circle')
        ->and(ServiceStatus::CANCELLED->icon())->toBe('x-circle')
        ->and(ServiceStatus::EXPIRED->icon())->toBe('archive');
});

it('returns all billable statuses', function () {
    $billable = ServiceStatus::billable();

    expect($billable)->toHaveCount(1)
        ->and($billable)->toContain(ServiceStatus::ACTIVE)
        ->and($billable)->not->toContain(ServiceStatus::PENDING)
        ->and($billable)->not->toContain(ServiceStatus::SUSPENDED);
});

it('returns all final statuses', function () {
    $final = ServiceStatus::final();

    expect($final)->toHaveCount(2)
        ->and($final)->toContain(ServiceStatus::CANCELLED)
        ->and($final)->toContain(ServiceStatus::EXPIRED)
        ->and($final)->not->toContain(ServiceStatus::ACTIVE)
        ->and($final)->not->toContain(ServiceStatus::PENDING);
});

it('can be used in match expressions', function () {
    $getAction = fn (ServiceStatus $status) => match ($status) {
        ServiceStatus::ACTIVE    => 'bill',
        ServiceStatus::PENDING   => 'activate',
        ServiceStatus::SUSPENDED => 'resume',
        ServiceStatus::CANCELLED, ServiceStatus::EXPIRED => 'archive',
    };

    expect($getAction(ServiceStatus::ACTIVE))->toBe('bill')
        ->and($getAction(ServiceStatus::PENDING))->toBe('activate')
        ->and($getAction(ServiceStatus::SUSPENDED))->toBe('resume')
        ->and($getAction(ServiceStatus::CANCELLED))->toBe('archive')
        ->and($getAction(ServiceStatus::EXPIRED))->toBe('archive');
});
