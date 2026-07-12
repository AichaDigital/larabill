<?php

declare(strict_types=1);

use AichaDigital\Larabill\Database\Seeders\InvoiceTemplatesSeeder;
use AichaDigital\Larabill\Models\InvoiceTemplate;
use Illuminate\Support\Facades\View;

/**
 * AID-450 — the seeder is the REGISTRY of the PDF template family: every
 * template_path it seeds must resolve to a real view. A registry entry
 * pointing at a blade that does not exist is a latent «View not found»
 * crash for any consumer selecting that template — and the family render
 * smokes (AID-439/AID-442) iterate the package blades, not the seeded
 * rows, so a phantom row is invisible to them. Same spirit as
 * MigrationOrderConsistencyTest for $migrationOrder ↔ stubs.
 */
it('seeds only templates whose template_path resolves to an existing view', function () {
    (new InvoiceTemplatesSeeder)->run();

    $paths = InvoiceTemplate::query()->pluck('template_path');

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect(View::exists($path))->toBeTrue("Seeded template_path [{$path}] does not resolve to a view.");
    }
});
