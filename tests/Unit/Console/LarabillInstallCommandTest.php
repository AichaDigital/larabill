<?php

declare(strict_types=1);

use AichaDigital\Larabill\Console\LarabillInstallCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// AID-419: `larabill:install --force` publishes the `.php.stub` files as real
// migrations into database_path('migrations'). Under testbench that resolves to
// the SHARED skeleton dir vendor/orchestra/testbench-core/laravel/database/
// migrations/, physically shared across all `pest --parallel` worker processes.
// Any other worker running RefreshDatabase globs that dir and require()s a file
// this test is mid-writing or mid-deleting -> intermittent FileNotFoundException
// on a rotating victim test.
//
// Redirect database_path() to a per-test temp dir so this test never writes into
// the shared skeleton. The skeleton then stays immutable for the whole run and
// every concurrent read of it is safe. Same isolation the MySQL install path
// uses (InstallCommandMysqlTestCase, AID-287). Also subsumes the old "table
// already exists" cleanup: a fresh empty temp dir per test can never leak
// published migrations into a later test's RefreshDatabase.
beforeEach(function () {
    $tmp = sys_get_temp_dir().'/larabill_install_'.uniqid('', true);
    File::ensureDirectoryExists($tmp.'/migrations');
    app()->useDatabasePath($tmp);
});

afterEach(function () {
    $base = database_path();
    if (str_contains($base, 'larabill_install_') && File::isDirectory($base)) {
        File::deleteDirectory($base);
    }
});

it('passes UUID preflight when users.id is UUID-compatible', function () {
    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->expectsOutputToContain('users.id verified UUID compatible')
        ->assertSuccessful();
});

it('does not publish migrations in non-production environment', function () {
    expect(app()->environment('production'))->toBeFalse();

    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->expectsOutputToContain('Migrations auto-loaded from package')
        ->assertSuccessful();

    $publishedMigrations = File::glob(database_path('migrations/*_create_legal_entity_types_table.php'));
    expect($publishedMigrations)->toBeEmpty();
});

it('publishes migrations when --force is passed in non-production', function () {
    $this->artisan(LarabillInstallCommand::class, [
        '--no-migrate' => true,
        '--force'      => true,
    ])
        ->expectsOutputToContain('Publishing migrations in correct order')
        ->assertSuccessful();
});

it('fails when users table does not exist', function () {
    Schema::dropIfExists('users');

    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->expectsOutputToContain('Table "users" does not exist')
        ->assertFailed();
});

it('displays the command description', function () {
    $command = new LarabillInstallCommand;

    expect($command->getDescription())->toContain('Install');
    expect($command->getName())->toBe('larabill:install');
});

it('does not duplicate migrations when they already exist in target', function () {
    $targetPath = database_path('migrations');

    $existingFile = $targetPath.'/2025_01_01_000000_create_legal_entity_types_table.php';
    File::ensureDirectoryExists($targetPath);
    File::put($existingFile, '<?php // existing migration');

    $this->artisan(LarabillInstallCommand::class, [
        '--no-migrate' => true,
        '--force'      => true,
    ])->assertSuccessful();

    $allLegalEntityMigrations = File::glob("{$targetPath}/*_create_legal_entity_types_table.php");
    expect(count($allLegalEntityMigrations))->toBe(1);
    // afterEach handles cleanup
});
