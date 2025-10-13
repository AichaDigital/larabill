<?php

declare(strict_types=1);

it('can access Laravel app', function () {
    $app = app();
    expect($app)->not->toBeNull();
});

it('can access service provider', function () {
    // Check if the LarabillServiceProvider is registered
    $app = app();
    expect($app)->not->toBeNull();

    // Test that we can resolve a simple service from our package
    $pdfService = app(\AichaDigital\Larabill\Services\PDF\PDFService::class);
    expect($pdfService)->not->toBeNull();
});
