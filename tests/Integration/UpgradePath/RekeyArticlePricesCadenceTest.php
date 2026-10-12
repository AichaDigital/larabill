<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Tests\Support\UpgradePath\ManifestBase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MysqlIntegrationTestCase is wired in tests/Pest.php for Integration/UpgradePath/.

/*
 * ADR-015 slice 2a (AID-952) — upgrade-path contract for the cadence re-key
 * of `article_prices`: the legacy `billing_frequency` int (BillingFrequency
 * enum 0-9) becomes `billing_unit` + `billing_quantity`.
 *
 * Contract under test (spec §4.1, §4.3):
 *
 *   1. Migrate ONLY the upgrade base (the manifest's in_base set), so the
 *      legacy schema — `billing_frequency` still in place — is what the
 *      seed writes.
 *   2. Seed RAW legacy rows, one per legacy value 0, 3-9 (base-shaped
 *      inserts; no unit/quantity columns exist yet).
 *   3. Run ONLY the re-key migration and assert, row by row, the §4.1
 *      mapping (0 → NULL cadence, 3 → month/1 … 9 → year/3), the unique
 *      re-key, and that a raw frequency-0 price stays queryable as a NULL
 *      cadence (gate finding #1).
 *
 * Loud-abort tests (spec §4.3): WEEKLY (1) and BIWEEKLY (2) rows abort
 * INDEPENDENTLY (gate finding #9), out-of-domain raw values abort with
 * remediation text, and a failed preflight leaves the table untouched —
 * the preflight runs BEFORE any DDL, so no partial schema change can
 * survive an abort.
 */

const LARABILL_REKEY_MIGRATION = '2026_10_09_000001_rekey_article_prices_cadence.php';

/**
 * The absolute path of the re-key migration under test.
 */
function rekeyMigrationPath(): string
{
    return dirname(__DIR__, 3).'/database/migrations/'.LARABILL_REKEY_MIGRATION;
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
    $baseDir       = sys_get_temp_dir().'/larabill-rekey-base-'.Str::random(8);
    File::ensureDirectoryExists($baseDir);

    // AID-1619: slice base = migrations ordered BEFORE the slice (the real
    // "just before" state of a sequential consumer), never the manifest's
    // current `in_base` set — release stamps flip slices into the base.
    foreach (ManifestBase::migrationsBefore('rekey_article_prices_cadence') as $name) {
        copy("{$migrationsDir}/{$name}", "{$baseDir}/{$name}");
    }

    $this->createUsersTable();
    $this->artisan('migrate', ['--database' => 'testing', '--path' => $baseDir, '--realpath' => true])
        ->assertExitCode(0);

    // Legacy schema sanity: the base table still carries the old column.
    expect(Schema::hasColumn('article_prices', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_prices', 'billing_unit'))->toBeFalse();

    $now = now();

    foreach (['HOST-PRO', 'DOM-COM'] as $i => $code) {
        DB::table('articles')->insert([
            'code'       => $code,
            'name'       => json_encode(['en' => "Article {$code}"]),
            'item_type'  => $i === 0 ? ItemType::SERVICE->value : ItemType::GOOD->value,
            'cost_price' => 500,
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $this->rekeyBaseDir = $baseDir;
    $this->hostingId    = (int) DB::table('articles')->where('code', 'HOST-PRO')->value('id');
    $this->domainId     = (int) DB::table('articles')->where('code', 'DOM-COM')->value('id');
});

afterEach(function () {
    if (isset($this->rekeyBaseDir) && is_dir($this->rekeyBaseDir)) {
        File::deleteDirectory($this->rekeyBaseDir);
    }
});

/**
 * Insert one RAW legacy price row (exactly what a 6.x database holds).
 */
function rekeySeedLegacyRow(int $articleId, int $frequency, int $price, ?string $validFrom = null): void
{
    DB::table('article_prices')->insert([
        'article_id'        => $articleId,
        'billing_frequency' => $frequency,
        'price'             => $price,
        'valid_from'        => $validFrom,
        'is_active'         => true,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
}

it('maps every legacy cadence to the §4.1 unit/quantity domain and keeps the NULL-cadence price queryable', function () {
    // One row per legacy value. The month/1 row is DATED so the unique
    // re-key has a non-null column to enforce against.
    rekeySeedLegacyRow($this->hostingId, 0, 2500);
    rekeySeedLegacyRow($this->hostingId, 3, 900, '2026-01-01');
    rekeySeedLegacyRow($this->hostingId, 4, 910);
    rekeySeedLegacyRow($this->hostingId, 5, 920);
    rekeySeedLegacyRow($this->hostingId, 6, 930);
    rekeySeedLegacyRow($this->hostingId, 7, 940);
    rekeySeedLegacyRow($this->hostingId, 8, 950);
    rekeySeedLegacyRow($this->hostingId, 9, 960);
    rekeySeedLegacyRow($this->domainId, 0, 1500);

    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path'     => rekeyMigrationPath(),
        '--realpath' => true,
    ])->assertExitCode(0);

    // ── Schema: the old column is gone, the new pair is in place ────────────
    expect(Schema::hasColumn('article_prices', 'billing_frequency'))->toBeFalse()
        ->and(Schema::hasColumn('article_prices', 'billing_unit'))->toBeTrue()
        ->and(Schema::hasColumn('article_prices', 'billing_quantity'))->toBeTrue();

    // ── §4.1 mapping, row by row (single source: fromLegacyFrequency) ───────
    $expected = [
        [$this->hostingId, null, null, 2500],
        [$this->hostingId, 'month', 1, 900],
        [$this->hostingId, 'month', 2, 910],
        [$this->hostingId, 'month', 3, 920],
        [$this->hostingId, 'month', 6, 930],
        [$this->hostingId, 'year', 1, 940],
        [$this->hostingId, 'year', 2, 950],
        [$this->hostingId, 'year', 3, 960],
        [$this->domainId, null, null, 1500],
    ];

    $mapped = DB::table('article_prices')->orderBy('id')->get()->map(
        fn (object $row): array => [
            (int) $row->article_id,
            $row->billing_unit,
            $row->billing_quantity === null ? null : (int) $row->billing_quantity,
            (int) $row->price,
        ],
    )->all();

    expect($mapped)->toBe($expected);

    // ── Gate finding #1: a raw frequency-0 price stays queryable as NULL
    // cadence — through the DB AND through the HEAD model. ──────────────────
    expect(DB::table('article_prices')->whereNull('billing_unit')->where('article_id', $this->domainId)->count())->toBe(1)
        ->and(Article::query()->findOrFail($this->domainId)->getDefaultPrice())->toBe(1500.0);

    // ── Unique re-key: the new key exists with the right columns; the old
    // unique and the old frequency index are gone. ──────────────────────────
    expect($this->getUniqueIndexColumns('article_prices', 'article_prices_cadence_unique'))
        ->toBe(['article_id', 'billing_unit', 'billing_quantity', 'valid_from'])
        ->and($this->getUniqueIndexColumns('article_prices', 'article_prices_unique'))
        ->toBe([]);

    $remainingIndexes = collect(DB::select(
        'SELECT INDEX_NAME AS name FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME IN (?, ?)',
        [env('LARABILL_TEST_MYSQL_DATABASE'), 'article_prices', 'article_prices_frequency', 'article_prices_cadence'],
    ))->pluck('name')->unique()->values()->all();

    expect($remainingIndexes)->toBe(['article_prices_cadence']);

    // The re-keyed unique still ENFORCES: a duplicate (article, cadence,
    // valid_from) row is rejected by the database.
    expect(fn () => DB::table('article_prices')->insert([
        'article_id'       => $this->hostingId,
        'billing_unit'     => 'month',
        'billing_quantity' => 1,
        'valid_from'       => '2026-01-01',
        'price'            => 999,
        'is_active'        => true,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]))->toThrow(QueryException::class);

    // ── Declared limit (§2.3): with nullable unit/quantity the DB unique
    // permits multiple NULL-cadence rows per article — the ADR-012 guarantee
    // remains application-level. The insert below must NOT be rejected by
    // the engine (the saving hook is the guard, unchanged mechanism). ───────
    DB::table('article_prices')->insert([
        'article_id'       => $this->domainId,
        'billing_unit'     => null,
        'billing_quantity' => null,
        'valid_from'       => null,
        'price'            => 990,
        'is_active'        => true,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    expect(DB::table('article_prices')->whereNull('billing_unit')->where('article_id', $this->domainId)->count())->toBe(2);
});

it('loud-aborts on a WEEKLY row, independently of BIWEEKLY, leaving the table untouched', function () {
    rekeySeedLegacyRow($this->hostingId, 1, 700);

    $threw = null;
    try {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path'     => rekeyMigrationPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    } catch (Throwable $e) {
        $threw = $e;
    }

    // Weekly and biweekly are reported independently (gate finding #9): the
    // abort names WEEKLY even though no biweekly row exists.
    expect($threw)->not->toBeNull('the re-key migration must loud-abort on a WEEKLY row')
        ->and($threw->getMessage())->toContain('WEEKLY');

    // Preflight BEFORE any DDL: the failed migration leaves the table intact.
    expect(Schema::hasColumn('article_prices', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_prices', 'billing_unit'))->toBeFalse()
        ->and(DB::table('article_prices')->count())->toBe(1)
        ->and((int) DB::table('article_prices')->value('billing_frequency'))->toBe(1);
});

it('loud-aborts on a BIWEEKLY row independently, leaving the table untouched', function () {
    rekeySeedLegacyRow($this->hostingId, 2, 700);

    $threw = null;
    try {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path'     => rekeyMigrationPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the re-key migration must loud-abort on a BIWEEKLY row')
        ->and($threw->getMessage())->toContain('BIWEEKLY');

    expect(Schema::hasColumn('article_prices', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_prices', 'billing_unit'))->toBeFalse()
        ->and(DB::table('article_prices')->count())->toBe(1);
});

it('loud-aborts on an out-of-domain raw frequency with remediation text, leaving the table untouched', function () {
    // No DB constraint ever guarded this column: a raw 10 could exist
    // silently in a legacy database (spec §4.3, gate finding #5).
    rekeySeedLegacyRow($this->hostingId, 10, 700);

    $threw = null;
    try {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path'     => rekeyMigrationPath(),
            '--realpath' => true,
        ])->assertExitCode(0);
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('the re-key migration must loud-abort on an out-of-domain value')
        ->and($threw->getMessage())->toContain('10')
        ->and($threw->getMessage())->toContain('before re-running');

    expect(Schema::hasColumn('article_prices', 'billing_frequency'))->toBeTrue()
        ->and(Schema::hasColumn('article_prices', 'billing_unit'))->toBeFalse()
        ->and(DB::table('article_prices')->count())->toBe(1);
});
