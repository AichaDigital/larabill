<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * ADR-016 §8.3/§8.4 (PR-1 of AID-1627) — upgrade-path contract for the
 * invoice numbering lifecycle migration chain:
 *
 *   049 preflight_invoice_numbering_lifecycle  (read-only, loud-abort)
 *   050 add_invoice_document_lifecycle_columns (introspection-guarded DDL)
 *   051 backfill_invoice_document_status       (one transaction + named CHECK)
 *
 * The legacy shape is EVERY fiscal row the 6.x engine produced: numbered at
 * birth, all five number/issuance fields NOT NULL, document axis not yet a
 * column. The backfill classifies them ISSUED + LEGACY_UNKNOWN (§8.3), remaps
 * collection PENDING → DRAFT (round 2, B5), leaves proforma rows untouched,
 * and only THEN adds the named MySQL CHECK over the healed data — where the
 * engine split is proven for real: a malformed shape must be rejected by the
 * DATABASE (the application enforcer covers SQLite, tested in
 * tests/Unit/InvoiceDocumentAxisEnforcerTest.php).
 */

const LARABILL_AXIS_CHAIN_MIGRATIONS = [
    '2026_10_13_000001_preflight_invoice_numbering_lifecycle.php',
    '2026_10_13_000002_add_invoice_document_lifecycle_columns.php',
    '2026_10_13_000003_backfill_invoice_document_status.php',
];

const LARABILL_AXIS_USER = '01936df0-0000-7000-8000-0000000000e1';

/**
 * Seed one legacy-shaped invoice row (raw SQL — the shape the 6.x engine left).
 *
 * @return string The row id
 */
function axisUpgradeSeedRow(array $overrides = []): string
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
        'status'        => 0,
        'user_id'       => LARABILL_AXIS_USER,
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $overrides));

    return $id;
}

beforeEach(function (): void {
    // ── Pre-state schema: every current migration EXCEPT the ADR-016 chain ──
    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $baseDir       = sys_get_temp_dir().'/larabill-axis-base-'.Str::random(8);
    $chainDir      = sys_get_temp_dir().'/larabill-axis-chain-'.Str::random(8);
    $ddlDir        = sys_get_temp_dir().'/larabill-axis-ddl-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);
    File::ensureDirectoryExists($chainDir);
    File::ensureDirectoryExists($ddlDir);

    foreach (glob("{$migrationsDir}/*.php") ?: [] as $file) {
        if (! in_array(basename($file), LARABILL_AXIS_CHAIN_MIGRATIONS, true)) {
            copy($file, "{$baseDir}/".basename($file));
        }
    }
    foreach (LARABILL_AXIS_CHAIN_MIGRATIONS as $name) {
        copy("{$migrationsDir}/{$name}", "{$chainDir}/{$name}");
    }
    // Preflight + schema WITHOUT the backfill: for testing the loud-abort
    // against states (an unnumbered fiscal row) that the CHECK would refuse
    // to let exist once the backfill has run.
    foreach (array_slice(LARABILL_AXIS_CHAIN_MIGRATIONS, 0, 2) as $name) {
        copy("{$migrationsDir}/{$name}", "{$ddlDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // Pre-state sanity: the document axis does not exist yet, and the legacy
    // schema still stamps issued_at with DEFAULT CURRENT_TIMESTAMP.
    expect(Schema::hasColumn('invoices', 'invoice_document_status'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'fiscal_submission_status'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'document_cancelled_at'))->toBeFalse();

    $this->axisBaseDir  = $baseDir;
    $this->axisChainDir = $chainDir;
    $this->axisDdlDir   = $ddlDir;
});

afterEach(function (): void {
    foreach (['axisBaseDir', 'axisChainDir', 'axisDdlDir'] as $property) {
        $dir = $this->{$property} ?? null;
        if (is_string($dir) && is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }
});

it('classifies the legacy populations and leaves proforma rows untouched through the full chain', function (): void {
    $draftId     = axisUpgradeSeedRow(['status' => 0]);
    $cancelledId = axisUpgradeSeedRow(['status' => 4]);
    $pendingId   = axisUpgradeSeedRow(['status' => 5]);
    $proformaId  = axisUpgradeSeedRow([
        'serie'         => 0,
        'fiscal_number' => 'PRO-2026-000001',
        'prefix'        => 'PRO',
        'status'        => 5,
    ]);

    // Run the chain in order — the preflight reads the REAL legacy state.
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->axisChainDir, '--realpath' => true])
        ->assertExitCode(0);

    // Numbered fiscal rows → ISSUED + LEGACY_UNKNOWN (§8.3 precedence 2+4).
    $draft = DB::table('invoices')->where('id', $draftId)->first();
    expect($draft->invoice_document_status)->toBe(2)
        ->and($draft->fiscal_submission_status)->toBe(5)
        ->and($draft->status)->toBe(0);

    // Legacy CANCELLED keeps the collection status; the document axis records
    // the historical truth (the number WAS consumed) as ISSUED — with NO
    // cancellation metadata: the backfill writes none (§8.3).
    $cancelled = DB::table('invoices')->where('id', $cancelledId)->first();
    expect($cancelled->invoice_document_status)->toBe(2)
        ->and($cancelled->status)->toBe(4)
        ->and($cancelled->document_cancelled_at)->toBeNull();

    // Legacy PENDING fiscal rows remap their collection status to DRAFT (B5).
    $pending = DB::table('invoices')->where('id', $pendingId)->first();
    expect($pending->invoice_document_status)->toBe(2)
        ->and($pending->fiscal_submission_status)->toBe(5)
        ->and($pending->status)->toBe(0);

    // Proforma rows: both axes NULL, everything else untouched (§3.1).
    $proforma = DB::table('invoices')->where('id', $proformaId)->first();
    expect($proforma->invoice_document_status)->toBeNull()
        ->and($proforma->fiscal_submission_status)->toBeNull()
        ->and($proforma->document_cancelled_at)->toBeNull()
        ->and($proforma->document_cancelled_by)->toBeNull()
        ->and($proforma->status)->toBe(5)
        ->and($proforma->fiscal_number)->toBe('PRO-2026-000001');

    // The five number/issuance fields are now nullable, and issued_at carries
    // no DEFAULT CURRENT_TIMESTAMP anymore (§8.1).
    $issuedAtColumn = DB::selectOne(
        'SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['invoices', 'issued_at'],
    );
    expect($issuedAtColumn?->IS_NULLABLE)->toBe('YES')
        // MariaDB reports a dropped default as the literal string 'NULL',
        // MySQL as a real NULL — accept both; what must hold is that no
        // CURRENT_TIMESTAMP default survives.
        ->and($issuedAtColumn?->COLUMN_DEFAULT)->toBeIn([null, 'NULL']);
});

it('prevents the unnumbered-impossible invariant from being violated silently (§8.4 loud abort)', function (): void {
    // On the BASE schema the five fields are NOT NULL, so the preflight pass
    // is the expected path. Then: with the schema migrated (fields nullable),
    // an unnumbered fiscal row CAN be written by hand — and BOTH the preflight
    // (§8.4) and the backfill's own re-validation (§8 house rule) must fail
    // loud on it, never stamp ISSUED over it.
    axisUpgradeSeedRow(['status' => 0]);

    // Preflight + schema only: the CHECK does not exist yet, so the
    // unnumbered row below is insertable.
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->axisDdlDir, '--realpath' => true])
        ->assertExitCode(0);

    DB::table('invoices')->insert([
        'id'         => (string) Str::uuid(),
        'serie'      => 1,
        'prefix'     => 'FAC',
        'status'     => 0,
        'user_id'    => LARABILL_AXIS_USER,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $preflight = include dirname(__DIR__, 3).'/database/migrations/2026_10_13_000001_preflight_invoice_numbering_lifecycle.php';
    $backfill  = include dirname(__DIR__, 3).'/database/migrations/2026_10_13_000003_backfill_invoice_document_status.php';

    $preflightThrew = null;
    try {
        $preflight->up();
    } catch (RuntimeException $exception) {
        $preflightThrew = $exception;
    }

    expect($preflightThrew)->not->toBeNull('The preflight must fail loud on any unnumbered fiscal row (§8.3 case 3)')
        ->and($preflightThrew?->getMessage() ?? '')->toContain('unnumbered fiscal row');

    $backfillThrew = null;
    try {
        $backfill->up();
    } catch (RuntimeException $exception) {
        $backfillThrew = $exception;
    }

    expect($backfillThrew)->not->toBeNull('The backfill re-validates its preconditions and must fail loud too')
        ->and($backfillThrew?->getMessage() ?? '')->toContain('unnumbered AND unclassified');

    // And nothing was stamped: every pre-chain fiscal row keeps its NULL axis.
    expect(DB::table('invoices')->where('serie', '!=', 0)->whereNotNull('invoice_document_status')->count())->toBe(0);
});

it('adds the named CHECK after the backfill and the DATABASE enforces the clause set', function (): void {
    axisUpgradeSeedRow(['status' => 0]);

    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->axisChainDir, '--realpath' => true])
        ->assertExitCode(0);

    // The named constraint exists (MySQL information_schema), looked up by
    // NAME: MariaDB lists other CHECK-typed rows in TABLE_CONSTRAINTS, so a
    // TYPE-only lookup returns an unrelated first row.
    $constraint = DB::selectOne(
        'SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
        ['invoices', 'larabill_invoice_document_axis_coherence'],
    );
    expect($constraint?->CONSTRAINT_NAME)->toBe('larabill_invoice_document_axis_coherence')
        ->and($constraint?->CONSTRAINT_TYPE)->toBe('CHECK');

    // The DATABASE rejects a fiscal ISSUED row with mixed field nullity (C4) —
    // the engine-split proof: on SQLite this is the app enforcer's job.
    $malformedIssued = [
        'id'                       => (string) Str::uuid(),
        'serie'                    => 1,
        'prefix'                   => 'FAC',
        'status'                   => 0,
        'user_id'                  => LARABILL_AXIS_USER,
        'invoice_document_status'  => 2,
        'fiscal_submission_status' => 0,
        'created_at'               => now(),
        'updated_at'               => now(),
    ];
    expect(fn () => DB::table('invoices')->insert($malformedIssued))
        ->toThrow(QueryException::class);

    // A fiscal row WITHOUT a document axis is rejected (C2) — the shape the
    // transitional schema would otherwise keep producing forever.
    expect(fn () => DB::table('invoices')->insert([
        'id'            => (string) Str::uuid(),
        'serie'         => 1,
        'fiscal_number' => 'FAC-2026-900001',
        'prefix'        => 'FAC',
        'series_number' => 900001,
        'fiscal_year'   => 2026,
        'invoice_date'  => '2026-06-01',
        'issued_at'     => '2026-06-01 10:00:00',
        'status'        => 0,
        'user_id'       => LARABILL_AXIS_USER,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]))->toThrow(QueryException::class);

    // A proforma carrying a document axis is rejected (C2).
    expect(fn () => DB::table('invoices')->insert([
        'id'                       => (string) Str::uuid(),
        'serie'                    => 0,
        'fiscal_number'            => 'PRO-2026-900002',
        'prefix'                   => 'PRO',
        'series_number'            => 900002,
        'fiscal_year'              => 2026,
        'invoice_date'             => '2026-06-01',
        'issued_at'                => '2026-06-01 10:00:00',
        'status'                   => 0,
        'user_id'                  => LARABILL_AXIS_USER,
        'invoice_document_status'  => 2,
        'fiscal_submission_status' => 0,
        'created_at'               => now(),
        'updated_at'               => now(),
    ]))->toThrow(QueryException::class);

    // An out-of-domain submission value on an ISSUED row is rejected (V9).
    expect(fn () => DB::table('invoices')->insert([
        'id'                       => (string) Str::uuid(),
        'serie'                    => 1,
        'fiscal_number'            => 'FAC-2026-900003',
        'prefix'                   => 'FAC',
        'series_number'            => 900003,
        'fiscal_year'              => 2026,
        'invoice_date'             => '2026-06-01',
        'issued_at'                => '2026-06-01 10:00:00',
        'status'                   => 0,
        'user_id'                  => LARABILL_AXIS_USER,
        'invoice_document_status'  => 2,
        'fiscal_submission_status' => 255,
        'created_at'               => now(),
        'updated_at'               => now(),
    ]))->toThrow(QueryException::class);

    // Coherent shapes keep flowing: a well-formed transitional fiscal row and
    // a well-formed proforma row both pass the CHECK.
    DB::table('invoices')->insert([
        'id'                       => (string) Str::uuid(),
        'serie'                    => 1,
        'fiscal_number'            => 'FAC-2026-900004',
        'prefix'                   => 'FAC',
        'series_number'            => 900004,
        'fiscal_year'              => 2026,
        'invoice_date'             => '2026-06-01',
        'issued_at'                => '2026-06-01 10:00:00',
        'status'                   => 0,
        'user_id'                  => LARABILL_AXIS_USER,
        'invoice_document_status'  => 2,
        'fiscal_submission_status' => 0,
        'created_at'               => now(),
        'updated_at'               => now(),
    ]);
    DB::table('invoices')->insert([
        'id'            => (string) Str::uuid(),
        'serie'         => 0,
        'fiscal_number' => 'PRO-2026-900005',
        'prefix'        => 'PRO',
        'series_number' => 900005,
        'fiscal_year'   => 2026,
        'invoice_date'  => '2026-06-01',
        'issued_at'     => '2026-06-01 10:00:00',
        'status'        => 0,
        'user_id'       => LARABILL_AXIS_USER,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    expect(DB::table('invoices')->where('prefix', 'FAC')->where('series_number', 900004)->exists())->toBeTrue()
        ->and(DB::table('invoices')->where('series_number', 900005)->exists())->toBeTrue();
});

it('re-runs the backfill idempotently over migrated data (§8 resumability)', function (): void {
    $draftId    = axisUpgradeSeedRow(['status' => 0]);
    $pendingId  = axisUpgradeSeedRow(['status' => 5]);
    $proformaId = axisUpgradeSeedRow(['serie' => 0, 'prefix' => 'PRO', 'fiscal_number' => 'PRO-2026-000009', 'status' => 0]);

    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->axisChainDir, '--realpath' => true])
        ->assertExitCode(0);

    $firstPass = DB::table('invoices')->whereIn('id', [$draftId, $pendingId, $proformaId])->orderBy('id')->get()->toJson();

    // Direct re-run of the backfill (the mid-chain failure recovery case).
    $migration = include dirname(__DIR__, 3).'/database/migrations/2026_10_13_000003_backfill_invoice_document_status.php';
    $migration->up();

    expect(DB::table('invoices')->whereIn('id', [$draftId, $pendingId, $proformaId])->orderBy('id')->get()->toJson())
        ->toBe($firstPass);
});
