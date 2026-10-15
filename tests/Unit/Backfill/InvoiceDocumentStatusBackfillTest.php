<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/*
 * ADR-016 §8.3 (PR-1 of AID-1627) — backfill classification, exercised on
 * SQLite by re-running the migration's up() directly (the AID-555 pattern).
 *
 * RefreshDatabase has already run the full chain on a fresh schema, so every
 * seeded row here is a NEW legacy-shaped row: a fiscal row with a NULL
 * document axis is, by definition, the observable legacy state the backfill
 * re-classifies from. Seeding goes through the query builder (raw inserts) —
 * exactly the shape an external SQL write produces, and outside the model
 * enforcer's guarantee by design (ADR-016 §6.5).
 */

/**
 * Run the ADR-016 backfill migration directly.
 */
function axisBackfillRun(): void
{
    $migration = include __DIR__.'/../../../database/migrations/2026_10_13_000003_backfill_invoice_document_status.php';
    $migration->up();
}

/**
 * Seed one legacy-shaped row (document axis NULL) through the query builder.
 *
 * @return string The row id
 */
function axisSeedLegacyRow(array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('invoices')->insert(array_merge([
        'id'            => $id,
        'serie'         => 1, // InvoiceSerieType::INVOICE
        'fiscal_number' => 'FAC-2026-00'.random_int(1000, 9999),
        'prefix'        => 'FAC',
        'series_number' => random_int(1, 999999),
        'fiscal_year'   => 2026,
        'invoice_date'  => '2026-06-01',
        'issued_at'     => '2026-06-01 10:00:00',
        'status'        => 0, // InvoiceStatus::DRAFT — the "numbered draft" population
        'user_id'       => TestCase::USER_UUID_1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $overrides));

    return $id;
}

it('classifies every unnumbered-axis fiscal row as ISSUED + LEGACY_UNKNOWN', function (): void {
    $draftId = axisSeedLegacyRow(['status' => 0]);
    $sentId  = axisSeedLegacyRow(['status' => 1]);
    $paidId  = axisSeedLegacyRow(['status' => 2]);

    axisBackfillRun();

    foreach ([$draftId, $sentId, $paidId] as $id) {
        $row = DB::table('invoices')->where('id', $id)->first();
        expect($row->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED->value)
            ->and($row->fiscal_submission_status)->toBe(FiscalSubmissionStatus::LEGACY_UNKNOWN->value);
    }
});

it('keeps the collection CANCELLED status while the document axis records the consumed number', function (): void {
    $id = axisSeedLegacyRow(['status' => 4]); // InvoiceStatus::CANCELLED

    axisBackfillRun();

    $row = DB::table('invoices')->where('id', $id)->first();
    expect($row->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED->value)
        ->and($row->fiscal_submission_status)->toBe(FiscalSubmissionStatus::LEGACY_UNKNOWN->value)
        ->and($row->status)->toBe(4)
        ->and($row->document_cancelled_at)->toBeNull('The legacy cancellation stays on the collection axis; the backfill writes no cancellation metadata');
});

it('remaps legacy PENDING fiscal rows to the truthful collection DRAFT (round 2, B5)', function (): void {
    $id = axisSeedLegacyRow(['status' => 5]); // InvoiceStatus::PENDING

    axisBackfillRun();

    $row = DB::table('invoices')->where('id', $id)->first();
    expect($row->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED->value)
        ->and($row->status)->toBe(0)
        ->and($row->fiscal_submission_status)->toBe(FiscalSubmissionStatus::LEGACY_UNKNOWN->value);
});

it('leaves proforma rows untouched on both axes (§8.3 precedence 1)', function (): void {
    $id = axisSeedLegacyRow([
        'serie'         => 0, // InvoiceSerieType::PROFORMA
        'fiscal_number' => 'PRO-2026-000001',
        'prefix'        => 'PRO',
        'status'        => 5, // even a PENDING proforma is untouched (fiscal-scoped remap)
    ]);

    axisBackfillRun();

    $row = DB::table('invoices')->where('id', $id)->first();
    expect($row->invoice_document_status)->toBeNull()
        ->and($row->fiscal_submission_status)->toBeNull()
        ->and($row->status)->toBe(5)
        ->and($row->fiscal_number)->toBe('PRO-2026-000001');
});

it('is idempotent by construction: a second run re-classifies nothing and heals nothing wrongly', function (): void {
    $draftId    = axisSeedLegacyRow(['status' => 0]);
    $pendingId  = axisSeedLegacyRow(['status' => 5]);
    $proformaId = axisSeedLegacyRow(['serie' => 0, 'prefix' => 'PRO', 'fiscal_number' => 'PRO-2026-000002', 'status' => 0]);

    axisBackfillRun();
    $firstPass = DB::table('invoices')->whereIn('id', [$draftId, $pendingId, $proformaId])->orderBy('id')->get()->toJson();

    axisBackfillRun();
    $secondPass = DB::table('invoices')->whereIn('id', [$draftId, $pendingId, $proformaId])->orderBy('id')->get()->toJson();

    expect($secondPass)->toBe($firstPass)
        ->and(DB::table('invoices')->where('id', $proformaId)->value('invoice_document_status'))->toBeNull();
});

it('refuses to classify an unnumbered unclassified fiscal row (§8.3 case 3, re-validated per run)', function (): void {
    // Post-schema, the five fields are nullable — a raw row CAN now exist
    // unnumbered; the backfill must fail loud, never stamp ISSUED over it.
    $id = (string) Str::uuid();
    DB::table('invoices')->insert([
        'id'          => $id,
        'serie'       => 1,
        'prefix'      => 'FAC',
        'status'      => 0,
        'user_id'     => TestCase::USER_UUID_1,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $threw = null;
    try {
        axisBackfillRun();
    } catch (RuntimeException $exception) {
        $threw = $exception;
    }

    expect($threw)->not->toBeNull('The backfill must fail loud on an unnumbered unclassified fiscal row')
        ->and($threw?->getMessage() ?? '')->toContain('unnumbered AND unclassified');

    $row = DB::table('invoices')->where('id', $id)->first();
    expect($row->invoice_document_status)->toBeNull('An unclassifiable row must never be stamped ISSUED');
});

it('does not stamp LEGACY_UNKNOWN over a row the application already classified', function (): void {
    // Transitional-stamped rows (doc=ISSUED, submission per config) are NOT
    // legacy-shaped: the backfill must not overwrite their submission value.
    $id = axisSeedLegacyRow(['status' => 0]);
    DB::table('invoices')->where('id', $id)->update([
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'fiscal_submission_status' => FiscalSubmissionStatus::NOT_REQUIRED->value,
    ]);

    axisBackfillRun();

    expect(DB::table('invoices')->where('id', $id)->value('fiscal_submission_status'))
        ->toBe(FiscalSubmissionStatus::NOT_REQUIRED->value);
});
