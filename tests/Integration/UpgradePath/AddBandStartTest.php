<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * AID-1778 — upgrade-path contract of migration 054
 * (`add_band_start_to_credit_movements`, spec §5.7): the 8.3.0 backfill.
 *
 * Base = every manifest migration ordered BEFORE the slice (the real
 * "just before" state of a sequential consumer, AID-1619), seeded with RAW
 * 8.3-shaped credit movements — no `band_start` column exists yet, so the
 * seed inserts never name it.
 *
 * Contract under test (operator L8/L12 — a CONSERVATIVE RECONSTRUCTION
 * CRITERION, never a heuristic presented as history):
 *
 *   R30 — an EXACT entry (E1 no reversals + E2 strictly increasing
 *         `created_at` along `id` order + E3 artifacts matching the proposed
 *         first-fit bands) receives the expected bands (takes 3000/3000/4000
 *         → 0, 3000, 6000);
 *   R31 — every AMBIGUOUS classification keeps ALL of the entry's exits NULL
 *         while an EXACT control entry in the SAME run receives its bands:
 *         (a) a reversed exit; (b) `created_at` decreasing along `id` order
 *         (J2); (c) an artifact disagreeing with the computed band (E3);
 *         (d) a `created_at` tie, seeded in BOTH id orders (J2/K2);
 *   R32 — the backfill is idempotent: `up()` invoked a second time directly
 *         (a logged migration is never re-run by `migrate`) writes nothing;
 *   R33 — the backfill is RESUMABLE per entry (J4): interrupting after the
 *         first entry's transaction leaves the rest NULL and a re-run
 *         completes it; and the DDL boundary (K3): a stop after the column
 *         was added and before the migration was logged re-runs `up()` with
 *         the `hasColumn` guard skipping the DDL instead of failing.
 *
 * E3 in this migration is its OWN conservative comparison (the T5b pro-rata
 * helper does not exist here): the advance's frozen totals give
 * f(c) = HalfUp(c·T/G) on exact BigInteger arithmetic, and every deduction
 * line (`invoice_items.deducts_invoice_id` = advance) and rectificative line
 * (items of invoices with `rectifies_invoice_id` = advance) must be matched
 * by a DISTINCT exit's (|amount|, f(b) − f(a)) pair.
 */

const LARABILL_AID1778_MIGRATION = '2026_10_26_000001_add_band_start_to_credit_movements.php';

const LARABILL_AID1778_BILLED = '01936df0-0000-7000-8000-000000177801';

const LARABILL_AID1778_PAYMENT = '01936df0-0000-7000-8000-000000177802';

const LARABILL_AID1778_RATE = '01936df0-0000-7000-8000-000000177803';

/**
 * Suffix slots for deterministic, lexicographically ordered ids: the prefix
 * is fixed and each slot character becomes its two-digit hex ASCII code, so
 * the id stays a valid UUID (MariaDB's native `uuid` column rejects non-hex
 * digits such as 'g') and the order of the slots is preserved.
 */
const LARABILL_AID1778_PREFIX = '01936df0-0000-7000-8000-00000017';

function aid1778Id(string $slot): string
{
    $slot = str_pad($slot, 2, '0', STR_PAD_LEFT);

    return LARABILL_AID1778_PREFIX.sprintf('%02x%02x', ord($slot[0]), ord($slot[1]));
}

function aid1778MigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_AID1778_MIGRATION;
}

function aid1778RunMigration(): void
{
    $exit = Artisan::call('migrate', [
        '--database' => 'testing',
        '--path'     => aid1778MigrationPath(),
        '--realpath' => true,
    ]);

    expect($exit)->toBe(0);
}

/**
 * One RAW 8.3-shaped credit movement (no `band_start` — the column does not
 * exist in the base schema the seed runs against).
 */
function aid1778SeedMovement(array $attributes): void
{
    $now = now();

    DB::table('credit_movements')->insert(array_merge([
        'billable_user_id'     => LARABILL_AID1778_BILLED,
        'currency'             => 'EUR',
        'grouped_payment_id'   => LARABILL_AID1778_PAYMENT,
        'allocation_id'        => null,
        'consumes_movement_id' => null,
        'advance_invoice_id'   => null,
        'method'               => null,
        'recorded_by'          => null,
        'reference'            => null,
        'notes'                => null,
        'occurred_at'          => '2026-09-01 08:00:00',
        'reversed_at'          => null,
        'reversed_by'          => null,
        'reverse_reason'       => null,
        'created_at'           => $now,
        'updated_at'           => $now,
    ], $attributes));
}

/**
 * An entry of gross $gross holding $takes exits, ids ascending in the given
 * order, each with its own `created_at` second.
 *
 * @param  list<int>  $takes
 * @param  list<string>  $createdAt  One per exit, same length as $takes
 */
function aid1778SeedEntry(string $slot, int $gross, array $takes, array $createdAt, ?string $advanceId = null, bool $reverseFirst = false): string
{
    $entryId = aid1778Id($slot.'0');

    aid1778SeedMovement([
        'id'                 => $entryId,
        'kind'               => 0, // SURPLUS
        'amount'             => $gross,
        'advance_invoice_id' => $advanceId,
    ]);

    foreach ($takes as $i => $take) {
        aid1778SeedMovement([
            'id'                   => aid1778Id($slot.($i + 1)),
            'kind'                 => 2, // APPLIED
            'amount'               => -$take,
            'consumes_movement_id' => $entryId,
            'occurred_at'          => '2026-09-02 08:00:00',
            'reversed_at'          => $reverseFirst && $i === 0 ? '2026-09-05 08:00:00' : null,
            'reverse_reason'       => $reverseFirst && $i === 0 ? 'fixture' : null,
            'created_at'           => $createdAt[$i],
            'updated_at'           => $createdAt[$i],
        ]);
    }

    return $entryId;
}

/**
 * An issued invoice with a single-rate frozen breakdown: total = $taxable + $tax.
 */
function aid1778SeedInvoice(string $id, int $number, int $taxable, int $tax): void
{
    $now = now();

    DB::table('invoices')->insert([
        'id'                       => $id,
        'fiscal_number'            => sprintf('FAC-2026-%06d', $number),
        'prefix'                   => 'FAC', 'serie' => InvoiceSerieType::INVOICE->value, 'series_number' => $number, 'fiscal_year' => 2026,
        'invoice_date'             => sprintf('2026-09-%02d', $number), 'issued_at' => $now,
        'status'                   => InvoiceStatus::PAID->value,
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'fiscal_submission_status' => FiscalSubmissionStatus::NOT_REQUIRED->value,
        'user_id'                  => LARABILL_AID1778_BILLED,
        'billable_user_id'         => LARABILL_AID1778_BILLED,
        'taxable_amount'           => $taxable, 'total_tax_amount' => $tax, 'total_amount' => $taxable + $tax,
        'created_at'               => $now, 'updated_at' => $now,
    ]);
}

/**
 * One raw frozen line; $taxable/$tax carry their fiscal sign (negative on
 * deduction and rectificative lines), taxes_applied mirrors them.
 */
function aid1778SeedItem(string $invoiceId, int $taxable, int $tax, ?string $deducts = null): void
{
    DB::table('invoice_items')->insert([
        'invoice_id'       => $invoiceId,
        'item_type'        => 1,
        'description'      => 'aid1778 frozen line',
        'quantity'         => 100,
        'unit_price'       => $taxable,
        'taxable_amount'   => $taxable,
        'total_tax_amount' => $tax,
        'total_amount'     => $taxable + $tax,
        'taxes_applied'    => json_encode([[
            'source_rate_id' => LARABILL_AID1778_RATE,
            'name'           => 'IVA',
            'rate'           => 2100,
            'amount'         => $tax,
        ]]),
        'deducts_invoice_id' => $deducts,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
}

/**
 * The band_start values of the exits of $entryId, ordered by id.
 *
 * @return list<int|null>
 */
function aid1778Bands(string $entryId): array
{
    return DB::table('credit_movements')
        ->where('consumes_movement_id', $entryId)
        ->orderBy('id')
        ->pluck('band_start')
        ->map(static fn ($band): ?int => $band === null ? null : (int) $band)
        ->all();
}

beforeEach(function () {
    // ── Base schema: every manifest migration ordered BEFORE the slice ──────
    $migrationsDir        = dirname(__DIR__, 3).'/database/migrations';
    $this->aid1778BaseDir = sys_get_temp_dir().'/larabill-aid1778-base-'.Str::random(8);
    File::ensureDirectoryExists($this->aid1778BaseDir);

    foreach (ManifestBase::migrationsBefore('add_band_start_to_credit_movements') as $name) {
        copy("{$migrationsDir}/{$name}", "{$this->aid1778BaseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $this->aid1778BaseDir, '--realpath' => true])
        ->assertExitCode(0);

    // 8.3.0 sanity: the column does not exist yet.
    expect(Schema::hasColumn('credit_movements', 'band_start'))->toBeFalse();

    DB::table('users')->insert([
        ['id' => LARABILL_AID1778_BILLED, 'name' => 'Billed', 'email' => 'aid1778-billed@example.test', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('grouped_payments')->insert([
        'id'               => LARABILL_AID1778_PAYMENT,
        'billable_user_id' => LARABILL_AID1778_BILLED,
        'amount'           => 100000,
        'currency'         => 'EUR',
        'paid_at'          => '2026-09-01 08:00:00',
        'idempotency_key'  => 'aid1778-payment',
        'status'           => GroupedPaymentStatus::POSTED->value,
        'method'           => 1,
        'created_at'       => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    if (isset($this->aid1778BaseDir) && is_dir($this->aid1778BaseDir)) {
        File::deleteDirectory($this->aid1778BaseDir);
    }
});

it('backfills an EXACT entry (E1+E2+E3) with the first-fit bands and keeps every AMBIGUOUS entry NULL', function () {
    // ── The exact entry: advance G=10000/T=1736, artifacts matching the
    //    first-fit proposal (f(3000)=521, f(6000)=1042, f(10000)=1736). ────
    $advance = aid1778Id('a1');
    $later   = aid1778Id('a2');
    aid1778SeedInvoice($advance, 1, 8264, 1736);
    aid1778SeedInvoice($later, 2, 0, 0);

    // Deduction lines: one per exit, the band shape — total_amount = −(band
    // length), tax = −(f(b) − f(a)) = −521/−521/−694, taxable = total − tax.
    aid1778SeedItem($later, -2479, -521, $advance);
    aid1778SeedItem($later, -2479, -521, $advance);
    aid1778SeedItem($later, -3306, -694, $advance);

    $exact = aid1778SeedEntry('b', 10000, [3000, 3000, 4000], [
        '2026-09-02 10:00:01', '2026-09-02 10:00:02', '2026-09-02 10:00:03',
    ], $advance);

    // ── The AMBIGUOUS variants (R31) — each must keep NULL while the exact
    //    entry in the SAME run receives its bands. ──────────────────────────
    // (a) a reversed exit on the entry: the hole/refill order is not in the data.
    $reversed = aid1778SeedEntry('c', 5000, [3000, 2000], [
        '2026-09-02 11:00:01', '2026-09-02 11:00:02',
    ], null, reverseFirst: true);

    // (b) created_at DECREASES along id order (J2).
    $decrease = aid1778SeedEntry('d', 10000, [3000, 7000], [
        '2026-09-02 11:05:02', '2026-09-02 11:05:01',
    ]);

    // (c) an artifact DISAGREEING with the computed band (E3 in action).
    $mismatchAdvance = aid1778Id('e1');
    $mismatchLater   = aid1778Id('e2');
    aid1778SeedInvoice($mismatchAdvance, 3, 8264, 1736);
    aid1778SeedInvoice($mismatchLater, 4, 0, 0);
    aid1778SeedItem($mismatchLater, -2001, -999, $mismatchAdvance); // f(3000) = 521, not 999; total −3000
    $mismatch = aid1778SeedEntry('e', 10000, [3000, 7000], [
        '2026-09-02 11:10:01', '2026-09-02 11:10:02',
    ], $mismatchAdvance);

    // (d) a created_at TIE, seeded in BOTH id orders (J2/K2): no order is
    //     resolvable from the data either way.
    $tieAscending = aid1778SeedEntry('f', 10000, [3000, 7000], [
        '2026-09-02 11:20:00', '2026-09-02 11:20:00',
    ]);
    // Second tie entry: ids descending relative to the seeded order — the
    // lower id belongs to the second exit.
    $tieDescending = aid1778Id('g0');
    aid1778SeedMovement([
        'id'                   => $tieDescending,
        'kind'                 => 0,
        'amount'               => 10000,
    ]);
    aid1778SeedMovement([
        'id'                   => aid1778Id('g2'), // HIGHER id, seeded "first"
        'kind'                 => 2,
        'amount'               => -3000,
        'consumes_movement_id' => $tieDescending,
        'occurred_at'          => '2026-09-02 08:00:00',
        'created_at'           => '2026-09-02 11:21:00',
        'updated_at'           => '2026-09-02 11:21:00',
    ]);
    aid1778SeedMovement([
        'id'                   => aid1778Id('g1'), // LOWER id, seeded "second"
        'kind'                 => 2,
        'amount'               => -7000,
        'consumes_movement_id' => $tieDescending,
        'occurred_at'          => '2026-09-02 08:00:00',
        'created_at'           => '2026-09-02 11:21:00',
        'updated_at'           => '2026-09-02 11:21:00',
    ]);

    aid1778RunMigration();

    expect(aid1778Bands($exact))->toBe([0, 3000, 6000])
        ->and(aid1778Bands($reversed))->toBe([null, null])
        ->and(aid1778Bands($decrease))->toBe([null, null])
        ->and(aid1778Bands($mismatch))->toBe([null, null])
        ->and(aid1778Bands($tieAscending))->toBe([null, null])
        ->and(aid1778Bands($tieDescending))->toBe([null, null]);
});

it('is idempotent: a second direct up() writes nothing new', function () {
    aid1778SeedEntry('b', 10000, [3000, 7000], ['2026-09-02 10:00:01', '2026-09-02 10:00:02']);

    aid1778RunMigration();
    $first = DB::table('credit_movements')->whereNotNull('consumes_movement_id')->orderBy('id')->get(['id', 'band_start']);

    // A logged migration is never re-run by `migrate`; the idempotency
    // contract is on up() itself (R32).
    $migration = require aid1778MigrationPath();
    $migration->up();

    $second = DB::table('credit_movements')->whereNotNull('consumes_movement_id')->orderBy('id')->get(['id', 'band_start']);

    expect($second->all())->toEqual($first->all())
        ->and($second->pluck('band_start')->map(fn ($b): int => (int) $b)->all())->toBe([0, 3000]);
});

it('is resumable per entry (J4): an interrupted backfill completes on re-run', function () {
    $entryA = aid1778SeedEntry('b', 10000, [3000, 7000], ['2026-09-02 10:00:01', '2026-09-02 10:00:02']);
    $entryB = aid1778SeedEntry('h', 8000, [2000, 6000], ['2026-09-02 10:01:01', '2026-09-02 10:01:02']);

    // Column added (the DDL boundary passed), backfill interrupted after the
    // FIRST entry's per-entry transaction: simulate by running only that unit.
    $migration = require aid1778MigrationPath();
    $migration->up(); // full up() on a clean base bands everything…
    DB::table('credit_movements')->whereIn('consumes_movement_id', [$entryB])->update(['band_start' => null]);
    // …and the interruption state is: A banded, B NULL. Assert it, then
    // re-run to completion.
    expect(aid1778Bands($entryA))->toBe([0, 3000])
        ->and(aid1778Bands($entryB))->toBe([null, null]);

    $resumed = require aid1778MigrationPath();
    $resumed->up();

    expect(aid1778Bands($entryA))->toBe([0, 3000]) // already-banded entry: no-op
        ->and(aid1778Bands($entryB))->toBe([0, 2000]); // resumed to completion
});

it('is resumable per entry through the public backfillEntry unit (J4)', function () {
    $entryA = aid1778SeedEntry('b', 10000, [3000, 7000], ['2026-09-02 10:00:01', '2026-09-02 10:00:02']);
    $entryB = aid1778SeedEntry('h', 8000, [2000, 6000], ['2026-09-02 10:01:01', '2026-09-02 10:01:02']);

    // The DDL boundary state only: column present, nothing backfilled, the
    // migration not logged. backfillEntry() is the per-entry resumable unit.
    Schema::table('credit_movements', function ($table): void {
        $table->bigInteger('band_start')->nullable();
    });

    $migration = require aid1778MigrationPath();
    $migration->backfillEntry($entryA);

    expect(aid1778Bands($entryA))->toBe([0, 3000])
        ->and(aid1778Bands($entryB))->toBe([null, null]);

    // A re-run completes the rest and leaves the banded entry untouched.
    $resumed = require aid1778MigrationPath();
    $resumed->up();

    expect(aid1778Bands($entryA))->toBe([0, 3000])
        ->and(aid1778Bands($entryB))->toBe([0, 2000]);
});

it('survives the DDL boundary (K3): a stop after addColumn re-runs up() without a duplicate-column error', function () {
    $entry = aid1778SeedEntry('b', 10000, [3000, 7000], ['2026-09-02 10:00:01', '2026-09-02 10:00:02']);

    // The crash-after-DDL state: the column exists, the migration was never
    // logged (up() runs bare and logs only after it returns), every row NULL.
    Schema::table('credit_movements', function ($table): void {
        $table->bigInteger('band_start')->nullable();
    });

    // The re-run MUST skip the guarded addColumn and backfill everything.
    aid1778RunMigration();

    expect(aid1778Bands($entry))->toBe([0, 3000])
        ->and(Schema::hasColumn('credit_movements', 'band_start'))->toBeTrue();
});

it('keeps NULL an entry whose active exits exceed its gross: no band outside the tape (impl gate round 2)', function () {
    // E1 and E2 hold (no reversal, created_at strictly increasing), no
    // advance is linked so E3 has nothing to witness — yet 3000 + 8000 > 10000.
    // A first-fit stack would write [3000, 11000), a band outside the entry's
    // tape: the history is not exact, so it must stay unresolved.
    $entry = aid1778SeedEntry('i', 10000, [3000, 8000], ['2026-09-03 10:00:01', '2026-09-03 10:00:02']);

    aid1778RunMigration();

    expect(aid1778Bands($entry))->toBe([null, null]);
});
