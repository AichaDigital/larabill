<?php

declare(strict_types=1);

use AichaDigital\Larabill\Console\LarabillInstallCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('uses configured user_id_type from env/config instead of schema detection', function () {
    // Config is set to 'uuid' by default in TestCase
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

    // In SQLite test environment, the users table exists with integer IDs
    // Schema detection should pick up the type
    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->assertSuccessful();
});

it('does not publish migrations in non-production environment', function () {
    // Ensure we are in testing (non-production)
    expect(app()->environment('production'))->toBeFalse();

    $this->artisan(LarabillInstallCommand::class, ['--no-migrate' => true])
        ->expectsOutputToContain('Migrations auto-loaded from package')
        ->assertSuccessful();

    // Verify no larabill migrations were published to database/migrations
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

    // Create a fake existing migration
    $existingFile = $targetPath.'/2025_01_01_000000_create_legal_entity_types_table.php';
    File::ensureDirectoryExists($targetPath);
    File::put($existingFile, '<?php // existing migration');

    $this->artisan(LarabillInstallCommand::class, [
        '--no-migrate' => true,
        '--force'      => true,
    ])->assertSuccessful();

    // Verify the old file was replaced, not duplicated
    $allLegalEntityMigrations = File::glob("{$targetPath}/*_create_legal_entity_types_table.php");

    // Should have exactly 1 (the new one replaced the old one)
    expect(count($allLegalEntityMigrations))->toBe(1);

    // Clean up
    foreach ($allLegalEntityMigrations as $file) {
        File::delete($file);
    }
    if (File::exists($existingFile)) {
        File::delete($existingFile);
    }
});
