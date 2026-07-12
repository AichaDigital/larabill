<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * AID-450 — cleanup of the phantom «Plantilla Simple» proforma row.
 *
 * InvoiceTemplatesSeeder used to seed an ACTIVE proforma template whose
 * template_path (larabill::pdf.invoice.proforma-simple) never resolved to a
 * blade anywhere in the package history, so selecting it crashed the render
 * with «View not found». The seeder entry is gone; this migration cleans the
 * row already seeded in consumer databases — UNLESS the consumer published an
 * override blade that makes the row functional, in which case it is kept.
 *
 * Legacy rows are seeded with raw inserts on purpose: the seeder no longer
 * creates them, and this test must reproduce the PRE-cleanup database state.
 */
const AID450_PHANTOM_VIEW = 'larabill::pdf.invoice.proforma-simple';

/**
 * @return array<string, mixed>
 */
function aid450PhantomRow(): array
{
    return [
        'name'          => 'simple',
        'display_name'  => 'Plantilla Simple',
        'type'          => 'proforma',
        'template_path' => AID450_PHANTOM_VIEW,
        'description'   => 'Plantilla simple para proformas rápidas',
        'is_default'    => false,
        'is_active'     => true,
        'settings'      => json_encode([
            'show_qr'            => false,
            'show_legal_notes'   => false,
            'show_payment_terms' => false,
        ]),
        'created_at'    => now(),
        'updated_at'    => now(),
    ];
}

function aid450RunCleanup(): void
{
    $migration = include __DIR__.'/../../../database/migrations/2026_07_12_000001_remove_phantom_proforma_simple_invoice_template.php';
    $migration->up();
}

it('deletes the phantom proforma-simple row and leaves real templates untouched', function () {
    DB::table('invoice_templates')->insert([
        aid450PhantomRow(),
        [
            'name'          => 'default',
            'display_name'  => 'Plantilla Estándar',
            'type'          => 'proforma',
            'template_path' => 'larabill::pdf.invoice.proforma',
            'description'   => 'Plantilla estándar para facturas proforma',
            'is_default'    => true,
            'is_active'     => true,
            'settings'      => json_encode(['show_qr' => false]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ],
    ]);

    aid450RunCleanup();

    expect(DB::table('invoice_templates')->where('template_path', AID450_PHANTOM_VIEW)->count())->toBe(0)
        ->and(DB::table('invoice_templates')->where('template_path', 'larabill::pdf.invoice.proforma')->count())->toBe(1);
});

it('keeps the row when a consumer override blade makes the view resolve', function () {
    $overrideDir = sys_get_temp_dir().'/larabill-aid450-'.Str::random(8);
    File::makeDirectory($overrideDir.'/pdf/invoice', 0755, true);
    File::put($overrideDir.'/pdf/invoice/proforma-simple.blade.php', '<html>consumer override</html>');

    try {
        View::prependNamespace('larabill', $overrideDir);

        expect(View::exists(AID450_PHANTOM_VIEW))->toBeTrue();

        DB::table('invoice_templates')->insert(aid450PhantomRow());

        aid450RunCleanup();

        expect(DB::table('invoice_templates')->where('template_path', AID450_PHANTOM_VIEW)->count())->toBe(1);
    } finally {
        File::deleteDirectory($overrideDir);
    }
});

it('is a no-op on a database without the phantom row', function () {
    aid450RunCleanup();

    expect(DB::table('invoice_templates')->count())->toBe(0);
});
