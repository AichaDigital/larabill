<?php

declare(strict_types=1);

use AichaDigital\Larabill\Console\LarabillInstallCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// Clean up published migration files after each test.
// When --force tests run publishMigrationsInOrder(), migration files are copied
// to database_path('migrations'). Laravel's default migrator includes this path,
// so subsequent tests with RefreshDatabase would load migrations from BOTH
// the package path (ServiceProvider) AND database_path, causing "table already exists".
afterEach(function () {
    $targetPath = database_path('migrations');
    if (File::isDirectory($targetPath)) {
        foreach (File::glob("{$targetPath}/*.php") as $file) {
            File::delete($file);
        }
    }
});

it('uses configured user_id_type from env/config instead of schema detection', function () {
    config()->set('larabill.user_id_type', 'uuid');

    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->expectsOutputToContain('Using configured user ID type from env/config: uuid')
        ->expectsOutputToContain('User ID type: uuid')
        ->assertSuccessful();
});

it('respects --user-id-type option over config', function () {
    config()->set('larabill.user_id_type', 'uuid');

    $this->artisan(LarabillInstallCommand::class, [
        '--user-id-type' => 'int',
        '--no-migrate'   => true,
    ])
        ->expectsOutputToContain('User ID type: int')
        ->assertSuccessful();
});

it('falls back to schema detection when config is auto', function () {
    config()->set('larabill.user_id_type', 'auto');

    // Test users table uses $table->id() (integer) → detects 'int'
    // PendingCommand requires expectsConfirmation for interactive prompts
    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->expectsConfirmation("Use detected type 'int'?", 'yes')
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
