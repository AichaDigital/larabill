<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;

it('requiresVAT returns true when is_roi_taxed is false', function () {
    $invoice = new Invoice([
        'is_roi_taxed' => false,
    ]);

    expect($invoice->requiresVAT())->toBeTrue();
});

it('requiresVAT returns false when is_roi_taxed is true', function () {
    $invoice = new Invoice([
        'is_roi_taxed' => true,
    ]);

    expect($invoice->requiresVAT())->toBeFalse();
});

it('isReverseCharge returns true when is_roi_taxed is true', function () {
    $invoice = new Invoice([
        'is_roi_taxed' => true,
    ]);

    expect($invoice->isReverseCharge())->toBeTrue();
});

it('isReverseCharge returns false when is_roi_taxed is false', function () {
    $invoice = new Invoice([
        'is_roi_taxed' => false,
    ]);

    expect($invoice->isReverseCharge())->toBeFalse();
});

it('requiresVAT and isReverseCharge are mutually exclusive', function () {
    $invoice1 = new Invoice(['is_roi_taxed' => true]);
    expect($invoice1->requiresVAT())->toBeFalse();
    expect($invoice1->isReverseCharge())->toBeTrue();

    $invoice2 = new Invoice(['is_roi_taxed' => false]);
    expect($invoice2->requiresVAT())->toBeTrue();
    expect($invoice2->isReverseCharge())->toBeFalse();
});
