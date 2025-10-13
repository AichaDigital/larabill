<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DetectUserIdTypeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larabill:detect-user-id
                            {--update-env : Update .env file with detected type}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-detect User model ID type for Larabill agnostic foreign keys';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Detecting User ID type...');
        $this->newLine();

        // Check if users table exists
        if (! Schema::hasTable('users')) {
            $this->error('❌ Users table not found!');
            $this->warn('Please ensure your users table has been migrated before running Larabill migrations.');

            return self::FAILURE;
        }

        // Detect ID type
        $detectedType = MigrationHelper::detectUserIdType();

        if (! $detectedType) {
            $this->error('❌ Could not detect User ID type automatically.');
            $this->warn('Please manually configure LARABILL_USER_ID_TYPE in your .env file.');
            $this->newLine();
            $this->info('Supported types:');
            $this->line('  - int           : unsignedBigInteger (Laravel default)');
            $this->line('  - uuid          : UUID string (char 36)');
            $this->line('  - uuid_binary   : UUID binary(16) - most efficient');
            $this->line('  - ulid          : ULID string (char 26)');
            $this->line('  - ulid_binary   : ULID binary(26)');

            return self::FAILURE;
        }

        // Get current configuration
        $currentConfig = config('larabill.user_id_type', 'int');

        // Display results
        $this->components->twoColumnDetail(
            '<fg=cyan>Detected User ID Type</>',
            "<fg=green>{$detectedType}</>"
        );

        $this->components->twoColumnDetail(
            '<fg=cyan>Description</>',
            MigrationHelper::getIdTypeDescription($detectedType)
        );

        $this->components->twoColumnDetail(
            '<fg=cyan>Current Config</>',
            $currentConfig === $detectedType
                ? '<fg=green>✓ Already configured correctly</>'
                : "<fg=yellow>{$currentConfig} (needs update)</>"
        );

        $this->newLine();

        // Check if update is needed
        if ($currentConfig === $detectedType) {
            $this->info('✓ Your configuration is already correct!');

            return self::SUCCESS;
        }

        // Prompt for update if not already configured
        if ($this->option('update-env')) {
            return $this->updateEnvFile($detectedType);
        }

        // Show manual instructions
        $this->info('📝 To apply this configuration, add to your .env file:');
        $this->newLine();
        $this->line("    <fg=yellow>LARABILL_USER_ID_TYPE={$detectedType}</>");
        $this->newLine();
        $this->info('Or run with --update-env to automatically update your .env file.');

        return self::SUCCESS;
    }

    /**
     * Update .env file with detected type.
     */
    protected function updateEnvFile(string $detectedType): int
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('❌ .env file not found!');

            return self::FAILURE;
        }

        $envContent = file_get_contents($envPath);

        // Check if LARABILL_USER_ID_TYPE already exists
        if (str_contains($envContent, 'LARABILL_USER_ID_TYPE=')) {
            // Update existing value
            $pattern     = '/LARABILL_USER_ID_TYPE=.*/';
            $replacement = "LARABILL_USER_ID_TYPE={$detectedType}";
            $newContent  = preg_replace($pattern, $replacement, $envContent);
        } else {
            // Add new entry
            $newContent = $envContent."\n# Larabill User ID Type Configuration\nLARABILL_USER_ID_TYPE={$detectedType}\n";
        }

        if (file_put_contents($envPath, $newContent)) {
            $this->info("✓ Updated .env file with LARABILL_USER_ID_TYPE={$detectedType}");
            $this->warn('⚠️  Remember to clear your config cache: php artisan config:clear');

            return self::SUCCESS;
        }

        $this->error('❌ Failed to update .env file');

        return self::FAILURE;
    }
}
