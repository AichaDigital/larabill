<?php

declare(strict_types=1);

it('can run basic test', function () {
    expect(true)->toBeTrue();
});

it('can access Laravel app', function () {
    $app = app();
    expect($app)->not->toBeNull();
});

it('can access service provider', function () {
    $providers = app()->getLoadedProviders();
    expect($providers)->toBeArray();
});
