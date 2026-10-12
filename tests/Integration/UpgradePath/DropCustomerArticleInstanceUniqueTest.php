<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * AID-1611 — upgrade-path contract: the retired instance-identity UNIQUE.
 *
 * The v7.0.0 schema carries `customer_article_instance_unique` on
 * `(customer_id, article_id, instance_identifier)`, covering CLOSED
 * agreements too. This test seeds the previous release's state (v7.0.0:
 * every manifest migration EXCEPT the one under test), runs migration 048
 * and asserts the two invariants of the relaxation:
 *
 *   1. the UNIQUE constraint is gone and the PLAIN composite replacement
 *      index (`customer_article_instance_index`) carries the same columns;
 *   2. a new contract on the same triple as a CLOSED agreement lands — the
 *      re-hire/rescue case that blocked AID-895.
 *
 * The migration is provably data-safe DDL (AID-398): no row is rejected, no
 * data is transformed, so no backfill or loud-abort preflight applies.
 */

const LARABILL_INSTANCE_UNIQUE_MIGRATION = '2026_10_12_000001_drop_customer_article_instance_unique.php';

const LARABILL_INSTANCE_UNIQUE_USER = '01936df0-0000-7000-8000-0000000000d1';

/**
 * The absolute path of the migration under test.
 */
function instanceUniqueMigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_INSTANCE_UNIQUE_MIGRATION;
}

beforeEach(function () {
    // ── Base schema: the FULL v7.0.0 install (everything but 048) ──────────
    /** @var array{migrations: array<int, array{name: string, in_base: bool}>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/Contract/release-migration-manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $migrationsDir = dirname(__DIR__, 3).'/database/migrations';
    $baseDir       = sys_get_temp_dir().'/larabill-instance-unique-base-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);

    foreach ($manifest['migrations'] as $entry) {
        if ($entry['name'] === LARABILL_INSTANCE_UNIQUE_MIGRATION) {
            continue;
        }

        copy("{$migrationsDir}/{$entry['name']}", "{$baseDir}/{$entry['name']}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // v7.0.0 sanity: the retired constraint is still in place.
    expect($this->getUniqueIndexColumns('article_service_status', 'customer_article_instance_unique'))
        ->toBe(['customer_id', 'article_id', 'instance_identifier']);

    $now = now();

    DB::table('users')->insert([
        ['id' => LARABILL_INSTANCE_UNIQUE_USER, 'name' => 'Owner D', 'email' => 'owner-d@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('articles')->insert([
        [
            'code'      => 'SVC-INST', 'name' => json_encode(['en' => 'Instance service']),
            'item_type' => 'S', 'cost_price' => 500,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ],
    ]);
});

afterEach(function () {
    if (isset($this->instanceUniqueBaseDir) && is_dir($this->instanceUniqueBaseDir)) {
        File::deleteDirectory($this->instanceUniqueBaseDir);
    }
});

it('drops the instance-identity UNIQUE and installs the plain composite replacement', function () {
    $articleId = (int) DB::table('articles')->where('code', 'SVC-INST')->value('id');
    $now       = now();

    // A CLOSED agreement on the triple — the row the old constraint kept
    // colliding against, terminal by design (ADR-015 §3.1).
    DB::table('article_service_status')->insert([
        'customer_id'         => LARABILL_INSTANCE_UNIQUE_USER,
        'article_id'          => $articleId,
        'instance_identifier' => 'room-365.example.test',
        'started_at'          => '2025-01-01',
        'status'              => 'closed',
        'modality'            => 'recurring',
        'account_status'      => 'up_to_date',
        'renewal'             => 'closed_term',
        'expires_at'          => '2025-12-31',
        'refund_unused'       => false,
        'effective_price'     => 2900,
        'next_billing_date'   => '2025-12-01',
        'created_at'          => $now,
        'updated_at'          => $now,
    ]);

    test()->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => instanceUniqueMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    expect($this->getUniqueIndexColumns('article_service_status', 'customer_article_instance_unique'))
        ->toBe([])
        ->and($this->getIndexColumns('article_service_status', 'customer_article_instance_index'))
        ->toBe(['customer_id', 'article_id', 'instance_identifier'])
        // The closed agreement survives the DDL untouched.
        ->and(DB::table('article_service_status')->where('status', 'closed')->count())->toBe(1);
});

it('allows re-contracting the same instance after the upgrade (the AID-895 blocker)', function () {
    $articleId = (int) DB::table('articles')->where('code', 'SVC-INST')->value('id');
    $now       = now();

    DB::table('article_service_status')->insert([
        'customer_id'         => LARABILL_INSTANCE_UNIQUE_USER,
        'article_id'          => $articleId,
        'instance_identifier' => 'room-365.example.test',
        'started_at'          => '2025-01-01',
        'status'              => 'closed',
        'modality'            => 'recurring',
        'account_status'      => 'up_to_date',
        'renewal'             => 'closed_term',
        'expires_at'          => '2025-12-31',
        'refund_unused'       => false,
        'effective_price'     => 2900,
        'next_billing_date'   => '2025-12-01',
        'created_at'          => $now,
        'updated_at'          => $now,
    ]);

    test()->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => instanceUniqueMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    // The re-contract after the upgrade: same triple, no integrity violation.
    DB::table('article_service_status')->insert([
        'customer_id'         => LARABILL_INSTANCE_UNIQUE_USER,
        'article_id'          => $articleId,
        'instance_identifier' => 'room-365.example.test',
        'started_at'          => '2026-10-12',
        'status'              => 'active',
        'modality'            => 'recurring',
        'account_status'      => 'up_to_date',
        'renewal'             => 'auto',
        'refund_unused'       => false,
        'effective_price'     => 2900,
        'next_billing_date'   => now()->addMonth()->toDateString(),
        'created_at'          => $now,
        'updated_at'          => $now,
    ]);

    expect(DB::table('article_service_status')
        ->where('instance_identifier', 'room-365.example.test')
        ->count())->toBe(2);
});
