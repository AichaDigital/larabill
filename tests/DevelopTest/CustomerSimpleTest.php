<?php

use AichaDigital\Larabill\Models\Customer;
use AichaDigital\Larabill\Tests\TestCase;

uses(TestCase::class);

it('can create a customer with v0.4.0 tables', function () {
    $customer = Customer::factory()->create([
        'display_name' => 'Test Customer v0.4.0',
    ]);
    
    expect($customer->display_name)->toBe('Test Customer v0.4.0');
    expect($customer->exists)->toBeTrue();
});
