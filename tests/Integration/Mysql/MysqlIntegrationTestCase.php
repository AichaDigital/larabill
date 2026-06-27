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
 * Larabill is UUID-first per ADR-006. This case takes full control: configure
 * MySQL, create users.id as UUID v7 char(36), then run the package's full
 * migration set via artisan.
 *
 * Skip semantics: if any of LARABILL_TEST_MYSQL_* env vars is missing the
 * test is markTestSkipped() with a clear message — the suite stays green
 * in environments without MySQL.
 *
 * @see docs/ADR-006-uuid-first-no-agnostic.md
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
    }

    protected function getPackageProviders($app)
    {
        return [LarabillServiceProvider::class];
    }

    /**
     * Create the consumer-side `users` table with UUID v7 char(36) id, then
     * run the package's full migration set via `artisan migrate`.
     */
    protected function bootstrap(): void
    {
        $this->createUsersTable();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $t): void {
            $t->uuid('id')->primary();
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
     * Insert a minimal user row into the locally-created `users` table and
     * return its UUID. Only for tests that need a real FK-valid user id.
     */
    protected function seedUser(): string
    {
        $id = (string) \Illuminate\Support\Str::orderedUuid();
        DB::table('users')->insert([
            'id'         => $id,
            'name'       => 'Test User',
            'email'      => $id.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
