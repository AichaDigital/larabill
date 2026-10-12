<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * ADR-015 slice 2b (AID-952) — upgrade-path contract for the cadence re-key
 * of the AGREEMENT side: `article_service_status.billing_frequency` (raw
 * BillingFrequency int) becomes `billing_unit` + `billing_quantity`.
 *
 * Contract under test (spec §4.1, §4.3):
 *
 *   1. Migrate ONLY the upgrade base (the manifest's in_base set), so the
 *      legacy schema — `billing_frequency` still in place — is what the
 *      seed writes.
 *   2. Seed RAW legacy agreement rows, one per legacy value 0, 3-9, with
 *      marker values in every column this migration does NOT own.
 *   3. Run ONLY the re-key migration and assert, row by row, the §4.1
 *      mapping — AND that the lifecycle columns (status, cancellation_*,
 *      expires_at, next_billing_date) are byte-identical: the renewal and
 *      status backfills belong to the T2 lifecycle migration, NOT here.
 *
 * Loud-abort tests (spec §4.3): WEEKLY (1) and BIWEEKLY (2) agreement rows
 * abort INDEPENDENTLY (gate finding #9), out-of-domain raw values abort
 * with remediation text, and a failed preflight leaves the table untouched
 * — the preflight runs BEFORE any DDL, so no partial schema change can
 * survive an abort.
 */

const LARABILL_SERVICE_REKEY_MIGRATION = '2026_10_09_000002_rekey_article_service_status_cadence.php';

const LARABILL_SERVICE_REKEY_USER_A = '01936df0-0000-7000-8000-0000000000a1';

const LARABILL_SERVICE_REKEY_USER_B = '01936df0-0000-7000-8000-0000000000b2';

/**
 * The absolute path of the agreement re-key migration under test.
 */
function serviceRekeyMigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_SERVICE_REKEY_MIGRATION;
}

beforeEach(function () {
    // ── Base schema: migrate ONLY the upgrade-base migration set ────────────
    /** @var array{migrations: array<int, array{name: string, in_base: bool}>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Contract/release-migration-manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $baseDir       = sys_get_temp_dir().'/larabill-service-rekey-base-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);

    // AID-1619: slice base = migrations ordered BEFORE the slice (includes
    // the article_prices re-key 040), never the manifest's current `in_base`
    // set — release stamps flip slices into the base.
    foreach (ManifestBase::migrationsBefore('rekey_article_service_status_cadence') as $name) {
        copy("{$migrationsDir}/{$name}", "{$baseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // Legacy schema sanity: the base table still carries the old column.
    expect(Schema::hasColumn('article_service_status', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'billing_unit'))->toBeFalse();

    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_SERVICE_REKEY_USER_A, 'name' => 'Owner A', 'email' => 'owner-a@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['id' => LARABILL_SERVICE_REKEY_USER_B, 'name' => 'Owner B', 'email' => 'owner-b@example.test', 'created_at' => $now, 'updated_at' => $now],
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

    $this->serviceRekeyBaseDir = $baseDir;
    $this->hostingId           = (int) DB::table('articles')->where('code', 'HOST-PRO')->value('id');
    $this->domainId            = (int) DB::table('articles')->where('code', 'DOM-COM')->value('id');
});

afterEach(function () {
    if (isset($this->serviceRekeyBaseDir) && is_dir($this->serviceRekeyBaseDir)) {
        File::deleteDirectory($this->serviceRekeyBaseDir);
    }
});

/**
 * Insert one RAW legacy agreement row (exactly what a 6.x database holds),
 * with marker values in every lifecycle column this migration must NOT own.
 */
function serviceRekeySeedLegacyRow(int $articleId, string $customerId, string $instance, int $frequency): void
{
    DB::table('article_service_status')->insert([
        'customer_id'               => $customerId,
        'article_id'                => $articleId,
        'instance_identifier'       => $instance,
        'instance_name'             => 'Service '.$instance,
        'started_at'                => '2026-01-01',
        'next_billing_date'         => '2026-07-01',
        'expires_at'                => '2027-06-30',
        'status'                    => 'A',
        'cancellation_type'         => 'E',
        'cancellation_requested_at' => '2026-05-01',
        'cancellation_effective_at' => '2026-08-01',
        'refund_unused'             => true,
        'billing_frequency'         => $frequency,
        'effective_price'           => 2900,
        'external_reference'        => 'whmcs:'.$instance,
        'instance_data'             => json_encode(['marker' => 'keep-me']),
        'metadata'                  => json_encode(['marker' => 'keep-me']),
        'created_at'                => now(),
        'updated_at'                => now(),
    ]);
}

it('maps every legacy agreement cadence to the §4.1 unit/quantity domain and leaves the lifecycle columns byte-identical', function () {
    // One row per legacy value, spread over the two articles/customers.
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'monthly.example', 3);
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'bimonthly.example', 4);
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'quarterly.example', 5);
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'semiannual.example', 6);
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'yearly.example', 7);
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_B, 'biennial.example', 8);
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_B, 'triennial.example', 9);
    serviceRekeySeedLegacyRow($this->domainId, LARABILL_SERVICE_REKEY_USER_B, 'one-time.example', 0);

    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => serviceRekeyMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    // ── Schema: the old column is gone, the new pair is in place ────────────
    expect(Schema::hasColumn('article_service_status', 'billing_frequency'))->toBeFalse()
        ->and(Schema::hasColumn('article_service_status', 'billing_unit'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'billing_quantity'))->toBeTrue();

    // ── §4.1 mapping, row by row (single source: fromLegacyFrequency) ───────
    $expected = [
        ['monthly.example', 'month', 1],
        ['bimonthly.example', 'month', 2],
        ['quarterly.example', 'month', 3],
        ['semiannual.example', 'month', 6],
        ['yearly.example', 'year', 1],
        ['biennial.example', 'year', 2],
        ['triennial.example', 'year', 3],
        ['one-time.example', null, null],
    ];

    $mapped = DB::table('article_service_status')->orderBy('id')->get()->map(
        fn (object $row): array => [
            $row->instance_identifier,
            $row->billing_unit,
            $row->billing_quantity === null ? null : (int) $row->billing_quantity,
        ],
    )->all();

    expect($mapped)->toBe($expected);

    // ── Columns this migration does NOT own stay byte-identical. The status
    // mapping, the renewal backfill and the close_scheduled_at carry-over all
    // belong to the T2 lifecycle migration — this migration ONLY moves cadence.
    // The JSON columns compare DECODED: MySQL normalises stored JSON spacing
    // (MariaDB keeps it verbatim), which is a storage artefact, not a change. ─
    $untouched = DB::table('article_service_status')->orderBy('id')->get()->map(
        fn (object $row): array => [
            $row->instance_identifier,
            $row->status,
            $row->cancellation_type,
            $row->cancellation_requested_at,
            $row->cancellation_effective_at,
            $row->started_at,
            $row->next_billing_date,
            $row->expires_at,
            (int) $row->effective_price,
            json_decode((string) $row->instance_data, true),
            json_decode((string) $row->metadata, true),
        ],
    )->all();

    expect($untouched)->toBe([
        ['monthly.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['bimonthly.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['quarterly.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['semiannual.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['yearly.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['biennial.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['triennial.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
        ['one-time.example', 'A', 'E', '2026-05-01', '2026-08-01', '2026-01-01', '2026-07-01', '2027-06-30', 2900, ['marker' => 'keep-me'], ['marker' => 'keep-me']],
    ]);

    // Every row keeps its count — the re-key moved cadence, not rows.
    expect(DB::table('article_service_status')->count())->toBe(8);
});

it('loud-aborts on a WEEKLY agreement row, independently of BIWEEKLY, leaving the table untouched', function () {
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'weekly.example', 1);

    $threw = null;
    try {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path'     => serviceRekeyMigrationPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    } catch (Throwable $e) {
        $threw = $e;
    }

    // Weekly and biweekly are reported independently (gate finding #9): the
    // abort names WEEKLY even though no biweekly row exists.
    expect($threw)->not->toBeNull('the agreement re-key migration must loud-abort on a WEEKLY row')
        ->and($threw->getMessage())->toContain('WEEKLY');

    // Preflight BEFORE any DDL: the failed migration leaves the table intact.
    expect(Schema::hasColumn('article_service_status', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'billing_unit'))->toBeFalse()
        ->and(DB::table('article_service_status')->count())->toBe(1)
        ->and((int) DB::table('article_service_status')->value('billing_frequency'))->toBe(1);
});

it('loud-aborts on a BIWEEKLY agreement row independently, leaving the table untouched', function () {
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'biweekly.example', 2);

    $threw = null;
    try {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path'     => serviceRekeyMigrationPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the agreement re-key migration must loud-abort on a BIWEEKLY row')
        ->and($threw->getMessage())->toContain('BIWEEKLY');

    expect(Schema::hasColumn('article_service_status', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'billing_unit'))->toBeFalse()
        ->and(DB::table('article_service_status')->count())->toBe(1);
});

it('loud-aborts on an out-of-domain raw agreement frequency with remediation text, leaving the table untouched', function () {
    // No DB constraint ever guarded this column: a raw 10 could exist
    // silently in a legacy database (spec §4.3, gate finding #5).
    serviceRekeySeedLegacyRow($this->hostingId, LARABILL_SERVICE_REKEY_USER_A, 'corrupt.example', 10);

    $threw = null;
    try {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path'     => serviceRekeyMigrationPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the agreement re-key migration must loud-abort on an out-of-domain value')
        ->and($threw->getMessage())->toContain('10')
        ->and($threw->getMessage())->toContain('before re-running');

    expect(Schema::hasColumn('article_service_status', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_service_status', 'billing_unit'))->toBeFalse()
        ->and(DB::table('article_service_status')->count())->toBe(1);
});
