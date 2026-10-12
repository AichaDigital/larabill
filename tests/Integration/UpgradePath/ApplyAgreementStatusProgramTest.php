<?php

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * ADR-015 slice B (AID-949) — upgrade-path contract for the §4.2 status
 * program: `article_service_status.status` (legacy raw int 0-4 of
 * `ServiceStatus`, persisted in a char(1) column) is re-mapped to the
 * ADR-015 state strings, together with the deferred-closures carry-over
 * (`close_scheduled_at`), the accounting axis (`account_status`), the
 * `renewal` backfill and the retirement of `cancellation_type`.
 *
 * Contract under test (spec §4.2, §4.3):
 *
 *   0 (ACTIVE)      → active
 *   1 (PENDING)     → pending
 *   2 (SUSPENDED)   → suspended, cause NULL (legacy data cannot establish why)
 *   2.5 deferred    → rows still ACTIVE with a cancellation_effective_at:
 *                       future date → active + close_scheduled_at = stored date
 *                       today/past  → closed, cause NULL
 *   3 (CANCELLED)   → closed, cause NULL (CancellationType records timing
 *                     mechanics, not commercial cause)
 *   4 (EXPIRED)     → closed + cause `fulfilment` (the only mapping the
 *                     source data establishes)
 *   renewal         → expires_at NULL → auto; non-null → closed_term
 *
 * Loud-abort preflight BEFORE any DDL (§4.3): (b) any CANCELLED row without
 * cancellation_type aborts; (c) any status outside 0-4 or cancellation_type
 * outside 0-2 aborts. A failed preflight leaves the table byte-identical.
 */

const LARABILL_STATUS_PROGRAM_MIGRATION = '2026_10_09_000005_apply_agreement_status_program.php';

const LARABILL_STATUS_PROGRAM_USER = '01936df0-0000-7000-8000-0000000000c1';

/**
 * The absolute path of the status-program migration under test.
 */
function statusProgramMigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_STATUS_PROGRAM_MIGRATION;
}

beforeEach(function () {
    // ── Base schema: in_base set + the slice-A pair (vocabulary + FK) ───────
    /** @var array{migrations: array<int, array{name: string, in_base: bool}>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Contract/release-migration-manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $baseDir       = sys_get_temp_dir().'/larabill-status-program-base-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);

    // AID-1619: slice base = migrations ordered BEFORE the slice (includes
    // the cadence re-key pair 040/041 and the cause vocabulary 042/043),
    // never the manifest's current `in_base` set — release stamps flip
    // slices into the base.
    foreach (ManifestBase::migrationsBefore('apply_agreement_status_program') as $name) {
        copy("{$migrationsDir}/{$name}", "{$baseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // Legacy schema sanity: the status column is still the raw char(1) domain.
    expect(Schema::hasColumn('article_service_status', 'cancellation_type'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'close_scheduled_at'))->toBeFalse();

    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_STATUS_PROGRAM_USER, 'name' => 'Owner C', 'email' => 'owner-c@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('articles')->insert([
        [
            'code'      => 'SVC-ONE', 'name' => json_encode(['en' => 'Service one']),
            'item_type' => ItemType::SERVICE->value, 'cost_price' => 500,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    $this->statusProgramBaseDir = $baseDir;
    $this->articleId            = (int) DB::table('articles')->where('code', 'SVC-ONE')->value('id');
    $this->fulfilmentCauseId    = (int) DB::table('agreement_causes')->where('key', 'fulfilment')->value('id');
});

afterEach(function () {
    if (isset($this->statusProgramBaseDir) && is_dir($this->statusProgramBaseDir)) {
        File::deleteDirectory($this->statusProgramBaseDir);
    }
});

/**
 * Insert one RAW 6.x agreement row: `status` as the raw char(1) int value
 * the legacy `ServiceStatus` enum persisted, `cancellation_type` likewise.
 */
function statusProgramSeedRow(string $instance, string $status, array $overrides = []): void
{
    DB::table('article_service_status')->insert(array_merge([
        'customer_id'               => LARABILL_STATUS_PROGRAM_USER,
        'article_id'                => test()->articleId ?? 1,
        'instance_identifier'       => $instance,
        'instance_name'             => 'Service '.$instance,
        'started_at'                => '2026-01-01',
        'next_billing_date'         => '2026-07-01',
        'expires_at'                => null,
        'status'                    => $status,
        'cancellation_type'         => null,
        'cancellation_requested_at' => null,
        'cancellation_effective_at' => null,
        'refund_unused'             => false,
        'billing_unit'              => 'month', 'billing_quantity' => 1,
        // AID-1619: the base now runs BEFORE the status program, which means
        // the cadence re-key (041) has already applied — the row carries the
        // unit+quantity shape, not the legacy billing_frequency int.
        'effective_price'           => 2900,
        'instance_data'             => json_encode(['marker' => 'keep-me']),
        'metadata'                  => json_encode(['marker' => 'keep-me']),
        'created_at'                => now(),
        'updated_at'                => now(),
    ], $overrides));
}

function statusProgramRunMigration(): void
{
    test()->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => statusProgramMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);
}

it('maps every legacy status value 0-4 to the §4.2 state strings row by row', function () {
    statusProgramSeedRow('active.example', '0');
    statusProgramSeedRow('pending.example', '1');
    statusProgramSeedRow('suspended.example', '2');
    statusProgramSeedRow('cancelled.example', '3', ['cancellation_type' => '0']);
    statusProgramSeedRow('expired.example', '4');

    statusProgramRunMigration();

    $rows = DB::table('article_service_status')->orderBy('id')->get()->map(
        fn (object $row): array => [
            $row->instance_identifier,
            $row->status,
            $row->closure_cause_id,
        ],
    )->all();

    $fulfilment = $this->fulfilmentCauseId;

    expect($rows)->toBe([
        ['active.example', 'active', null],
        ['pending.example', 'pending', null],
        ['suspended.example', 'suspended', null],       // §4.2: legacy data cannot establish why
        ['cancelled.example', 'closed', null],          // §4.2: CancellationType records timing, not cause
        ['expired.example', 'closed', $fulfilment],     // §4.2: the only cause the data establishes
    ]);
});

it('carries deferred closures over: future effective date stays active with close_scheduled_at, today or past closes with cause NULL', function () {
    $future = now()->addDays(10)->toDateString();
    $today  = now()->toDateString();
    $past   = now()->subDays(10)->toDateString();

    statusProgramSeedRow('deferred-future.example', '0', ['cancellation_effective_at' => $future, 'cancellation_type' => '1']);
    statusProgramSeedRow('deferred-today.example', '0', ['cancellation_effective_at' => $today, 'cancellation_type' => '1']);
    statusProgramSeedRow('deferred-past.example', '0', ['cancellation_effective_at' => $past, 'cancellation_type' => '1']);

    statusProgramRunMigration();

    $rows = DB::table('article_service_status')->orderBy('id')->get()->keyBy('instance_identifier');

    // Future: stays active, the new scheduler closes it on that date (cause NULL).
    expect($rows['deferred-future.example']->status)->toBe('active')
        ->and($rows['deferred-future.example']->close_scheduled_at)->toBe($future)
        ->and($rows['deferred-future.example']->closure_cause_id)->toBeNull();

    // Today: closed — no branch of the mapping may leave today's date unhandled.
    expect($rows['deferred-today.example']->status)->toBe('closed')
        ->and($rows['deferred-today.example']->close_scheduled_at)->toBeNull()
        ->and($rows['deferred-today.example']->closure_cause_id)->toBeNull();

    // Past: closed, cause NULL.
    expect($rows['deferred-past.example']->status)->toBe('closed')
        ->and($rows['deferred-past.example']->close_scheduled_at)->toBeNull()
        ->and($rows['deferred-past.example']->closure_cause_id)->toBeNull();
});

it('backfills renewal on both expires_at branches: NULL → auto, dated → closed_term', function () {
    statusProgramSeedRow('undated.example', '0', ['expires_at' => null]);
    statusProgramSeedRow('dated.example', '0', ['expires_at' => '2027-06-30']);

    statusProgramRunMigration();

    $rows = DB::table('article_service_status')->orderBy('id')->get()->keyBy('instance_identifier');

    expect($rows['undated.example']->renewal)->toBe('auto')
        ->and($rows['dated.example']->renewal)->toBe('closed_term');
});

it('defaults account_status to up_to_date for every carried-over row and drops cancellation_type', function () {
    statusProgramSeedRow('plain.example', '0');
    statusProgramSeedRow('old-cancel.example', '3', ['cancellation_type' => '2']);

    statusProgramRunMigration();

    expect(Schema::hasColumn('article_service_status', 'cancellation_type'))->toBeFalse()
        ->and(Schema::hasColumn('article_service_status', 'close_scheduled_at'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'account_status'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'renewal'))->toBeTrue();

    $rows = DB::table('article_service_status')->get();
    expect($rows)->not->toBeEmpty()
        ->and($rows->every(fn (object $row) => $row->account_status === 'up_to_date'))->toBeTrue();

    // The columns §2.1 keeps untouched survive the migration with their data.
    $old = DB::table('article_service_status')->where('instance_identifier', 'old-cancel.example')->first();
    expect($old->cancellation_requested_at)->toBeNull()
        ->and($old->refund_unused)->toBe(0);
});

it('loud-aborts when a CANCELLED row lacks cancellation_type, leaving the table byte-identical', function () {
    // The importer-shadow state AID-949 warned about: a raw 3 with no type.
    statusProgramSeedRow('shadow.example', '3', ['cancellation_type' => null]);

    $threw = null;
    try {
        statusProgramRunMigration();
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the status-program migration must loud-abort on a CANCELLED row without cancellation_type')
        ->and($threw->getMessage())->toContain('cancellation_type')
        ->and($threw->getMessage())->toContain('before re-running');

    // Preflight BEFORE any DDL: the failed migration leaves the table intact.
    expect(Schema::hasColumn('article_service_status', 'cancellation_type'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'close_scheduled_at'))->toBeFalse()
        ->and(DB::table('article_service_status')->count())->toBe(1)
        ->and(DB::table('article_service_status')->value('status'))->toBe('3');
});

it('loud-aborts on an out-of-domain raw status value, leaving the table byte-identical', function () {
    // No DB constraint ever guarded this column (spec §4.3, gate finding #5).
    statusProgramSeedRow('corrupt.example', '9');

    $threw = null;
    try {
        statusProgramRunMigration();
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the status-program migration must loud-abort on an out-of-domain status')
        ->and($threw->getMessage())->toContain('9')
        ->and($threw->getMessage())->toContain('before re-running');

    expect(Schema::hasColumn('article_service_status', 'cancellation_type'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'close_scheduled_at'))->toBeFalse()
        ->and(DB::table('article_service_status')->count())->toBe(1);
});

it('loud-aborts on an out-of-domain cancellation_type value, leaving the table byte-identical', function () {
    statusProgramSeedRow('bad-type.example', '0', ['cancellation_type' => '5']);

    $threw = null;
    try {
        statusProgramRunMigration();
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the status-program migration must loud-abort on an out-of-domain cancellation_type')
        ->and($threw->getMessage())->toContain('5');

    expect(Schema::hasColumn('article_service_status', 'cancellation_type'))->toBeTrue()
        ->and(DB::table('article_service_status')->count())->toBe(1);
});
