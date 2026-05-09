<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Integration\Mysql;

use AichaDigital\Larabill\LarabillServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for MySQL integration (fresh install agnostic contract).
 *
 * Why this extends Orchestra\Testbench\TestCase directly (not tests/TestCase.php):
 *   - tests/TestCase.php hard-codes SQLite in-memory and seeds TestUsers with
 *     numeric ids; both would fight against the per-dataset id-type setup here.
 *   - This case takes full control: configure MySQL, create users with the
 *     requested id type, then run the package's full migration set via artisan.
 *
 * Skip semantics: if any of LARABILL_TEST_MYSQL_* env vars is missing the
 * test is markTestSkipped() with a clear message — the suite stays green
 * in environments without MySQL.
 *
 * @see docs/2026-05-09-fresh-install-agnostic-mysql.md
 */
abstract class MysqlIntegrationTestCase extends Orchestra
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_ENV = [
        'LARABILL_TEST_MYSQL_HOST',
        'LARABILL_TEST_MYSQL_PORT',
        'LARABILL_TEST_MYSQL_DATABASE',
        'LARABILL_TEST_MYSQL_USERNAME',
        'LARABILL_TEST_MYSQL_PASSWORD',
    ];

    protected function setUp(): void
    {
        if (! self::mysqlEnvAvailable()) {
            $this->markTestSkipped(
                'MySQL integration env not configured. Set LARABILL_TEST_MYSQL_HOST, '
                .'LARABILL_TEST_MYSQL_PORT, LARABILL_TEST_MYSQL_DATABASE, '
                .'LARABILL_TEST_MYSQL_USERNAME, LARABILL_TEST_MYSQL_PASSWORD to run.'
            );
        }

        parent::setUp();

        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        if (self::mysqlEnvAvailable()) {
            $this->dropAllTables();
        }

        parent::tearDown();
    }

    public function getEnvironmentSetUp($app)
    {
        if (! self::mysqlEnvAvailable()) {
            return;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'    => 'mysql',
            'host'      => env('LARABILL_TEST_MYSQL_HOST'),
            'port'      => (int) env('LARABILL_TEST_MYSQL_PORT'),
            'database'  => env('LARABILL_TEST_MYSQL_DATABASE'),
            'username'  => env('LARABILL_TEST_MYSQL_USERNAME'),
            'password'  => env('LARABILL_TEST_MYSQL_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => 'InnoDB',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Default; tests override per dataset before bootstrap.
        $app['config']->set('larabill.user_id_type', 'uuid');
    }

    protected function getPackageProviders($app)
    {
        return [LarabillServiceProvider::class];
    }

    /**
     * Full bootstrap for one dataset value: set the configured user_id_type,
     * create the consumer-side `users` table with the matching id type,
     * then run the package's full migration set via `artisan migrate`.
     */
    protected function bootstrapForUserIdType(string $idType): void
    {
        config()->set('larabill.user_id_type', $idType);

        $this->createUsersTable($idType);

        // Run the entire package migration set against MySQL.
        // The provider has already registered them via loadMigrationsFrom().
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
    }

    private function createUsersTable(string $idType): void
    {
        Schema::create('users', function (Blueprint $t) use ($idType): void {
            match ($idType) {
                'uuid'  => $t->uuid('id')->primary(),
                'ulid'  => $t->ulid('id')->primary(),
                default => $t->id(),
            };

            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->timestamps();
        });
    }

    public static function mysqlEnvAvailable(): bool
    {
        foreach (self::REQUIRED_ENV as $key) {
            if (env($key) === null || env($key) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop every table in the test database. Uses FK-checks bypass so order
     * does not matter — robust against arbitrary FK graphs.
     */
    protected function dropAllTables(): void
    {
        $database = env('LARABILL_TEST_MYSQL_DATABASE');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $tables = DB::select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                [$database]
            );

            foreach ($tables as $row) {
                DB::statement("DROP TABLE IF EXISTS `{$row->name}`");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    protected function getMysqlColumnType(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [env('LARABILL_TEST_MYSQL_DATABASE'), $table, $column]
        );

        return $row !== null ? strtolower($row->DATA_TYPE) : '';
    }

    protected function getMysqlColumnLength(string $table, string $column): ?int
    {
        $row = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [env('LARABILL_TEST_MYSQL_DATABASE'), $table, $column]
        );

        return $row !== null && $row->len !== null ? (int) $row->len : null;
    }

    /**
     * @return array<int, string>
     */
    protected function getUniqueIndexColumns(string $table, string $indexName): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0
             ORDER BY SEQ_IN_INDEX ASC',
            [env('LARABILL_TEST_MYSQL_DATABASE'), $table, $indexName]
        );

        return array_map(fn (object $r): string => $r->name, $rows);
    }
}
