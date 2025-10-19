<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\AuditEntry;
use Carbon\Carbon;

it('can create an audit entry DTO', function () {
    $timestamp = Carbon::parse('2024-01-15 10:30:00');

    $dto = new AuditEntry(
        timestamp: $timestamp,
        action: 'price_updated',
        userId: 42,
        userName: 'John Doe',
        changes: ['price' => ['from' => 29.00, 'to' => 24.00]],
        reason: 'Customer discount',
        metadata: ['approved_by' => 'manager']
    );

    expect($dto->timestamp->toDateTimeString())->toBe('2024-01-15 10:30:00')
        ->and($dto->action)->toBe('price_updated')
        ->and($dto->userId)->toBe(42)
        ->and($dto->userName)->toBe('John Doe')
        ->and($dto->changes)->toBe(['price' => ['from' => 29.00, 'to' => 24.00]])
        ->and($dto->reason)->toBe('Customer discount')
        ->and($dto->metadata)->toBe(['approved_by' => 'manager']);
});

it('can create audit entry from array', function () {
    $data = [
        'timestamp' => '2024-01-15 10:30:00',
        'action'    => 'price_updated',
        'user_id'   => 42,
        'user_name' => 'John Doe',
        'changes'   => ['price' => ['from' => 29.00, 'to' => 24.00]],
        'reason'    => 'Customer discount',
        'metadata'  => ['approved_by' => 'manager'],
    ];

    $dto = AuditEntry::fromArray($data);

    expect($dto->timestamp)->toBeInstanceOf(Carbon::class)
        ->and($dto->action)->toBe('price_updated')
        ->and($dto->userId)->toBe(42)
        ->and($dto->userName)->toBe('John Doe')
        ->and($dto->changes)->toBe(['price' => ['from' => 29.00, 'to' => 24.00]])
        ->and($dto->reason)->toBe('Customer discount');
});

it('can convert audit entry to array', function () {
    $timestamp = Carbon::parse('2024-01-15 10:30:00');

    $dto = new AuditEntry(
        timestamp: $timestamp,
        action: 'price_updated',
        userId: 42,
        userName: 'John Doe',
        changes: ['price' => ['from' => 29.00, 'to' => 24.00]],
        reason: 'Customer discount'
    );

    $array = $dto->toArray();

    expect($array['action'])->toBe('price_updated')
        ->and($array['user_id'])->toBe(42)
        ->and($array['user_name'])->toBe('John Doe')
        ->and($array['changes'])->toBe(['price' => ['from' => 29.00, 'to' => 24.00]])
        ->and($array['reason'])->toBe('Customer discount')
        ->and($array['timestamp'])->toContain('2024-01-15');
});

it('uses current timestamp if not provided', function () {
    $data = ['action' => 'created'];

    $dto = AuditEntry::fromArray($data);

    expect($dto->timestamp)->toBeInstanceOf(Carbon::class)
        ->and($dto->timestamp->isToday())->toBeTrue();
});

it('handles optional audit entry fields', function () {
    $dto = new AuditEntry(
        timestamp: now(),
        action: 'created'
    );

    expect($dto->action)->toBe('created')
        ->and($dto->userId)->toBeNull()
        ->and($dto->userName)->toBeNull()
        ->and($dto->changes)->toBeNull()
        ->and($dto->reason)->toBeNull()
        ->and($dto->metadata)->toBeNull();
});
