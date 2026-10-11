<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * ADR-015 slice 1 (AID-953) — upgrade-path contract for the `modality`
 * backfill on `article_service_status` (spec §2.1, §4.1).
 *
 * The mapping is decided by PERSISTED DATA and is TOTAL over the current
 * domain (which is why this migration needs NO loud-abort preflight, unlike
 * its §4.3 siblings):
 *
 *   - rows with NULL cadence — the §4.1 ONE_TIME carry-over slice 2b left
 *     in `billing_unit`/`billing_quantity` NULL — become `one_time`, and
 *     their `next_billing_date` artifact is CLEARED (AID-1595): the 6.x
 *     engine wrote a next date on ONE_TIME rows too, but a one-time
 *     agreement never bills again, and a persisted date would abort the
 *     whole 7.0 recurrent run at the emission gate;
 *   - every cadence-bearing row becomes `recurring`;
 *   - `non_billable` is NOT derivable from legacy data: it exists for the
 *     consumer/importer (§2.5 Free Account) to set — NO backfill row may
 *     land it.
 *
 * The migration runs on the post-T2 schema (status already mapped to the
 * ADR-015 strings), so the base here is "every current migration except
 * this one"; the seeding is raw SQL to prove the mapping reads the stored
 * columns, not the model.
 */

const LARABILL_MODALITY_MIGRATION = '2026_10_09_000006_add_modality_to_article_service_status_table.php';

const LARABILL_MODALITY_USER_A = '01936df0-0000-7000-8000-0000000000c1';

const LARABILL_MODALITY_USER_B = '01936df0-0000-7000-8000-0000000000d2';

function modalityMigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_MODALITY_MIGRATION;
}

beforeEach(function () {
    // ── Pre-state schema: every current migration EXCEPT the one under test ─
    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $baseDir       = sys_get_temp_dir().'/larabill-modality-base-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);

    foreach (glob("{$migrationsDir}/*.php") ?: [] as $file) {
        if (basename($file) !== LARABILL_MODALITY_MIGRATION) {
            copy($file, "{$baseDir}/".basename($file));
        }
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // Pre-state sanity: the T2 program is in, modality is not.
    expect(Schema::hasColumn('article_service_status', 'modality'))->toBeFalse()
        ->and(Schema::hasColumn('article_service_status', 'billing_unit'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'renewal'))->toBeTrue();

    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_MODALITY_USER_A, 'name' => 'Owner A', 'email' => 'owner-a@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['id' => LARABILL_MODALITY_USER_B, 'name' => 'Owner B', 'email' => 'owner-b@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('articles')->insert([
        [
            'code'      => 'HOST-PRO', 'name' => json_encode(['en' => 'Pro hosting']),
            'item_type' => ItemType::SERVICE->value, 'cost_price' => 500,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ],
        [
            'code'      => 'DOM-COM', 'name' => json_encode(['en' => '.com domain']),
            'item_type' => ItemType::GOOD->value, 'cost_price' => null,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ],
    ]);

    $this->modalityBaseDir = $baseDir;
    $this->hostingId       = (int) DB::table('articles')->where('code', 'HOST-PRO')->value('id');
    $this->domainId        = (int) DB::table('articles')->where('code', 'DOM-COM')->value('id');
});

afterEach(function () {
    if (isset($this->modalityBaseDir) && is_dir($this->modalityBaseDir)) {
        File::deleteDirectory($this->modalityBaseDir);
    }
});

/**
 * Insert one raw row in the post-T2 schema — exactly what the slice-2b/2
 * migrations leave behind — one_time carry-over and cadence-bearing alike.
 */
function modalitySeedAgreement(int $articleId, string $customerId, string $instance, ?string $unit, ?int $quantity, string $status, ?string $nextBillingDate = null): void
{
    DB::table('article_service_status')->insert([
        'customer_id'         => $customerId,
        'article_id'          => $articleId,
        'instance_identifier' => $instance,
        'instance_name'       => 'Service '.$instance,
        'started_at'          => '2026-01-01',
        'next_billing_date'   => $nextBillingDate ?? ($unit === null ? null : '2026-07-01'),
        'expires_at'          => null,
        'status'              => $status,
        'billing_unit'        => $unit,
        'billing_quantity'    => $quantity,
        'effective_price'     => 2900,
        'metadata'            => json_encode(['marker' => 'keep-me']),
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

it('backfills modality from the cadence domain: NULL cadence → one_time, cadence-bearing → recurring, never non_billable', function () {
    modalitySeedAgreement($this->hostingId, LARABILL_MODALITY_USER_A, 'one-time.carryover', null, null, 'active');
    modalitySeedAgreement($this->hostingId, LARABILL_MODALITY_USER_A, 'monthly.active', 'month', 1, 'active');
    modalitySeedAgreement($this->hostingId, LARABILL_MODALITY_USER_A, 'quarterly.suspended', 'month', 3, 'suspended');
    modalitySeedAgreement($this->domainId, LARABILL_MODALITY_USER_B, 'biennial.closed', 'year', 2, 'closed');

    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => modalityMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    // ── §2.1 mapping, row by row: decided by the cadence, NOT by the status ──
    $expected = [
        ['one-time.carryover', 'one_time'],
        ['monthly.active', 'recurring'],
        ['quarterly.suspended', 'recurring'],
        ['biennial.closed', 'recurring'],
    ];

    $mapped = DB::table('article_service_status')->orderBy('id')->get()->map(
        fn (object $row): array => [$row->instance_identifier, $row->modality],
    )->all();

    expect($mapped)->toBe($expected)
        // non_billable is importer-only (§2.5): NO backfill row may land it.
        ->and(DB::table('article_service_status')->where('modality', 'non_billable')->count())->toBe(0)
        ->and(DB::table('article_service_status')->count())->toBe(4);
});

it('clears the next_billing_date artifact on one_time rows — a legacy date would abort the whole recurrent run (AID-1595)', function () {
    // The 6.x engine wrote next_billing_date on ONE_TIME rows too — a
    // legacy-engine artifact: a one-time agreement never bills again. Real
    // 6.x data carries it (AID-1595), and the 7.0 emission gate would abort
    // the whole run on the first such row, so the migration clears it.
    modalitySeedAgreement($this->hostingId, LARABILL_MODALITY_USER_A, 'one-time.with-artifact', null, null, 'active', '2026-07-01');
    modalitySeedAgreement($this->hostingId, LARABILL_MODALITY_USER_A, 'monthly.active', 'month', 1, 'active');

    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => modalityMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    $oneTime = DB::table('article_service_status')->where('instance_identifier', 'one-time.with-artifact')->first();
    $monthly = DB::table('article_service_status')->where('instance_identifier', 'monthly.active')->first();

    expect($oneTime->modality)->toBe('one_time')
        // The artifact is gone...
        ->and($oneTime->next_billing_date)->toBeNull()
        // ...but the row stays a full, queryable agreement: lifecycle state,
        // price and metadata untouched.
        ->and($oneTime->status)->toBe('active')
        ->and((int) $oneTime->effective_price)->toBe(2900)
        ->and(json_decode((string) $oneTime->metadata, true))->toBe(['marker' => 'keep-me'])
        // Cadence-bearing rows keep their date — only one_time rows are cleared.
        ->and($monthly->modality)->toBe('recurring')
        ->and($monthly->next_billing_date)->toBe('2026-07-01');
});

it('adds modality as a NOT NULL varchar(12) column and leaves every other column untouched', function () {
    modalitySeedAgreement($this->hostingId, LARABILL_MODALITY_USER_A, 'monthly.active', 'month', 1, 'active');

    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => modalityMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    expect(Schema::hasColumn('article_service_status', 'modality'))->toBeTrue();

    // NOT NULL varchar(12), straight from the engine's own catalogue — the
    // column contract §2.1 declares, not something the model layer fakes.
    $column = collect(DB::select(
        'select COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE from information_schema.columns '
        ."where table_schema = database() and table_name = 'article_service_status'",
    ))->firstWhere('COLUMN_NAME', 'modality');

    expect($column)->not->toBeNull()
        ->and($column->COLUMN_TYPE)->toBe('varchar(12)')
        ->and($column->IS_NULLABLE)->toBe('NO');

    // The mapping owns ONLY modality: the rest of the row is byte-identical
    // (JSON compared decoded — MySQL normalises stored JSON spacing).
    $row = DB::table('article_service_status')->first();

    expect($row->status)->toBe('active')
        ->and($row->billing_unit)->toBe('month')
        ->and((int) $row->billing_quantity)->toBe(1)
        ->and($row->next_billing_date)->toBe('2026-07-01')
        ->and($row->renewal)->toBe('auto')
        ->and(json_decode((string) $row->metadata, true))->toBe(['marker' => 'keep-me']);
});
