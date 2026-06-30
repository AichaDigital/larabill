<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Integration\InstallMysql;

use AichaDigital\Larabill\Tests\Integration\Mysql\MysqlIntegrationTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * MySQL integration base that exercises the REAL production install path.
 *
 * Consumers do NOT run the package's timestamped `.php` migrations. They run
 * `larabill:install`, which publishes the `.php.stub` files into
 * database_path('migrations') with incremental timestamps in $migrationOrder
 * order, then `migrate`. The default MysqlIntegrationTestCase instead runs the
 * auto-loaded `.php` files in timestamp order — a different order that never
 * validates the production install sequence against real FKs (AID-287).
 *
 * This case reproduces the production path:
 *  - environment=production → LarabillServiceProvider skips loadMigrationsFrom(),
 *    so the ONLY migrations that exist are the stubs the install command
 *    publishes (no double-migration against the dev `.php` set).
 *  - database_path() is redirected to a per-test temp dir so the publish target
 *    is isolated and torn down cleanly.
 *
 * Lives in its own directory (not tests/Integration/Mysql) because Pest binds
 * exactly one TestCase per directory tree; see tests/Pest.php.
 *
 * @see MysqlIntegrationTestCase for the env-gating / MySQL connection contract.
 */
abstract class InstallCommandMysqlTestCase extends MysqlIntegrationTestCase
{
    protected string $tempBasePath = '';

    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        if (! self::mysqlEnvAvailable()) {
            return;
        }

        // Production disables the ServiceProvider's loadMigrationsFrom(), so the
        // published stubs are the only migrations that run.
        $app->detectEnvironment(fn (): string => 'production');

        // Isolate database_path('migrations') (the publish target) per test.
        $this->tempBasePath = sys_get_temp_dir().'/larabill_install_'.uniqid('', true);
        File::ensureDirectoryExists($this->tempBasePath.'/migrations');
        $app->useDatabasePath($this->tempBasePath);
    }

    protected function tearDown(): void
    {
        if ($this->tempBasePath !== '' && is_dir($this->tempBasePath)) {
            File::deleteDirectory($this->tempBasePath);
        }

        parent::tearDown();
    }

    /**
     * True if the given table exists in the MySQL test database.
     */
    protected function mysqlTableExists(string $table): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [env('LARABILL_TEST_MYSQL_DATABASE'), $table]
        );

        return $row !== null && (int) $row->c === 1;
    }

    /**
     * Count rows in the framework `migrations` ledger (applied migrations).
     */
    protected function appliedMigrationCount(): int
    {
        $row = DB::selectOne('SELECT COUNT(*) AS c FROM migrations');

        return $row !== null ? (int) $row->c : 0;
    }

    /**
     * Count FK constraints defined in the MySQL test database.
     */
    protected function foreignKeyConstraintCount(): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ?',
            [env('LARABILL_TEST_MYSQL_DATABASE')]
        );

        return $row !== null ? (int) $row->c : 0;
    }
}
