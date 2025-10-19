<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\BillingDetails;
use Carbon\Carbon;

it('can create a billing details DTO', function () {
    $start = Carbon::parse('2024-01-01');
    $end   = Carbon::parse('2024-01-31');
    $next  = Carbon::parse('2024-02-01');

    $dto = new BillingDetails(
        billingCycle: 'monthly',
        periodStart: $start,
        periodEnd: $end,
        nextBillingDate: $next,
        billingInterval: 1,
        additional: ['notes' => 'First billing cycle']
    );

    expect($dto->billingCycle)->toBe('monthly')
        ->and($dto->periodStart->toDateString())->toBe('2024-01-01')
        ->and($dto->periodEnd->toDateString())->toBe('2024-01-31')
        ->and($dto->nextBillingDate->toDateString())->toBe('2024-02-01')
        ->and($dto->billingInterval)->toBe(1)
        ->and($dto->additional)->toBe(['notes' => 'First billing cycle']);
});

it('can create billing details from array', function () {
    $data = [
        'billing_cycle'     => 'monthly',
        'period_start'      => '2024-01-01',
        'period_end'        => '2024-01-31',
        'next_billing_date' => '2024-02-01',
        'billing_interval'  => 1,
        'additional'        => ['notes' => 'First billing cycle'],
    ];

    $dto = BillingDetails::fromArray($data);

    expect($dto->billingCycle)->toBe('monthly')
        ->and($dto->periodStart)->toBeInstanceOf(Carbon::class)
        ->and($dto->periodStart->toDateString())->toBe('2024-01-01')
        ->and($dto->periodEnd->toDateString())->toBe('2024-01-31')
        ->and($dto->nextBillingDate->toDateString())->toBe('2024-02-01')
        ->and($dto->billingInterval)->toBe(1);
});

it('can convert billing details to array', function () {
    $start = Carbon::parse('2024-01-01');
    $end   = Carbon::parse('2024-01-31');
    $next  = Carbon::parse('2024-02-01');

    $dto = new BillingDetails(
        billingCycle: 'monthly',
        periodStart: $start,
        periodEnd: $end,
        nextBillingDate: $next,
        billingInterval: 1
    );

    $array = $dto->toArray();

    expect($array['billing_cycle'])->toBe('monthly')
        ->and($array['period_start'])->toContain('2024-01-01')
        ->and($array['period_end'])->toContain('2024-01-31')
        ->and($array['next_billing_date'])->toContain('2024-02-01')
        ->and($array['billing_interval'])->toBe(1);
});

it('handles null values in billing details', function () {
    $dto = new BillingDetails;

    expect($dto->billingCycle)->toBeNull()
        ->and($dto->periodStart)->toBeNull()
        ->and($dto->periodEnd)->toBeNull()
        ->and($dto->nextBillingDate)->toBeNull()
        ->and($dto->billingInterval)->toBeNull()
        ->and($dto->additional)->toBeNull();
});
