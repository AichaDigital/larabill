<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\RelationshipType;
use AichaDigital\Larabill\Models\Customer;

it('can create a customer', function () {
    $customer = Customer::factory()->create([
        'display_name' => 'Test Customer',
        'is_active'    => true,
    ]);

    expect($customer->display_name)->toBe('Test Customer')
        ->and($customer->is_active)->toBeTrue()
        ->and($customer->exists)->toBeTrue();
});

it('can deactivate a customer', function () {
    $customer = Customer::factory()->create(['is_active' => true]);

    $customer->deactivate('Test reason');

    expect($customer->is_active)->toBeFalse()
        ->and($customer->inactive_reason)->toBe('Test reason')
        ->and($customer->inactive_since)->not->toBeNull();
});

it('can activate a customer', function () {
    $customer = Customer::factory()->create([
        'is_active'       => false,
        'inactive_reason' => 'Test',
    ]);

    $customer->activate();

    expect($customer->is_active)->toBeTrue()
        ->and($customer->inactive_reason)->toBeNull()
        ->and($customer->inactive_since)->toBeNull();
});

it('can scope active customers', function () {
    Customer::factory()->create(['is_active' => true]);
    Customer::factory()->create(['is_active' => false]);

    $activeCustomers = Customer::active()->get();

    expect($activeCustomers)->toHaveCount(1)
        ->and($activeCustomers->first()->is_active)->toBeTrue();
});

it('can scope by relationship type', function () {
    Customer::factory()->create(['relationship_type' => RelationshipType::SELF]);
    Customer::factory()->create(['relationship_type' => RelationshipType::CLIENT]);

    $selfCustomers = Customer::relationshipType(RelationshipType::SELF)->get();

    expect($selfCustomers)->toHaveCount(1)
        ->and($selfCustomers->first()->relationship_type)->toBe(RelationshipType::SELF);
});

it('uses soft deletes', function () {
    $customer   = Customer::factory()->create();
    $customerId = $customer->id;

    $customer->delete();

    expect(Customer::find($customerId))->toBeNull()
        ->and(Customer::withTrashed()->find($customerId))->not->toBeNull();
});
