<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class LarabillInstallCommand extends Command
{
    protected $signature = 'larabill:install
                            {--user-id-type= : User ID type (uuid_binary, uuid_string, int, ulid)}
                            {--force : Force overwrite of existing migrations}
                            {--no-migrate : Skip running migrations}';

    protected $description = 'Install Larabill package with proper configuration';

    /**
     * Orden correcto de migraciones para evitar errores de FK
     */
    protected array $migrationOrder = [
        '001' => 'create_users_table',
        '002' => 'create_test_users_table',
        '003' => 'create_legal_entity_types_table',
        '004' => 'create_issuer_config_table',
        '005' => 'create_issuer_tax_profiles_table',
        '006' => 'create_customers_table',
        '007' => 'create_customer_tax_profiles_table',
        '008' => 'create_user_tax_infos_table', // ANTES de invoices
        '009' => 'create_unit_measures_table',   // ANTES de invoice_items
        '010' => 'create_tax_categories_table',  // ANTES de invoice_items
        '011' => 'create_articles_table',        // ANTES de commissions
        '012' => 'create_article_overrides_table',
        '013' => 'create_article_service_status_table',
        '014' => 'create_tax_rates_table',
        '015' => 'create_tax_groups_table',
        '016' => 'create_tax_group_tax_rate_table',
        '017' => 'create_invoices_table',
        '018' => 'create_invoice_items_table',
        '019' => 'create_commissions_table',     // DESPUÉS de articles
        '020' => 'create_vat_verifications_table',
        '021' => 'create_company_fiscal_configs_table',
        '022' => 'create_invoice_templates_table',
        '023' => 'create_company_template_settings_table',
        '024' => 'add_v040_fields_to_invoices_table', // DESPUÉS de create_invoices
    ];

    public function handle(): int
    {
        $this->info('🚀 Installing Larabill...');
        $this->newLine();

        // 1. Detectar o solicitar tipo de user_id
        $userIdType = $this->detectOrAskUserIdType();
        $this->info("✓ User ID type: {$userIdType}");

        // 2. Validar pre-requisitos
        if (! $this->validatePrerequisites()) {
            return self::FAILURE;
        }

        // 3. Publicar configuraciones
        $this->info('📝 Publishing configurations...');
        $this->call('vendor:publish', [
            '--provider' => 'AichaDigital\\Larabill\\LarabillServiceProvider',
            '--tag' => 'larabill-config',
            '--force' => $this->option('force'),
        ]);

        // 4. Publicar migraciones EN ORDEN
        $this->info('📄 Publishing migrations in correct order...');
        $published = $this->publishMigrationsInOrder();

        if ($published === 0) {
            $this->comment('⚠ No new migrations to publish (use --force to overwrite)');
        }

        // 5. Ejecutar migraciones si no se especificó --no-migrate
        if (! $this->option('no-migrate')) {
            $this->newLine();
            if ($this->confirm('Run migrations now?', true)) {
                $this->info('🔄 Running migrations...');
                $exitCode = $this->call('migrate');

                if ($exitCode !== 0) {
                    $this->error('❌ Migration failed. Please check the errors above.');

                    return self::FAILURE;
                }
            }
        }

        $this->newLine();
        $this->info('✅ Larabill installed successfully!');
        $this->newLine();
        $this->comment('Next steps:');
        $this->line('  - Configure your .env file with LARABILL_* variables');
        $this->line('  - Run seeders if needed: php artisan db:seed');
        $this->line('  - Check documentation: https://github.com/aichadigital/larabill');

        return self::SUCCESS;
    }

    protected function detectOrAskUserIdType(): string
    {
        // Si se pasó por opción, usar ese
        if ($type = $this->option('user-id-type')) {
            return $type;
        }

        // Intentar detectar automáticamente
        if (Schema::hasTable('users')) {
            $detected = $this->detectUserIdTypeFromTable();

            if ($detected) {
                $this->comment("Detected user ID type: {$detected}");

                if ($this->confirm("Use detected type '{$detected}'?", true)) {
                    return $detected;
                }
            }
        }

        // Preguntar al usuario
        return $this->choice(
            'What type of user ID does your application use?',
            ['uuid_binary', 'uuid_string', 'int', 'ulid'],
            0
        );
    }

    protected function detectUserIdTypeFromTable(): ?string
    {
        try {
            $connection = Schema::getConnection();
            $column = $connection->getDoctrineColumn('users', 'id');

            $type = $column->getType()->getName();
            $length = $column->getLength();

            // UUID binary (16 bytes)
            if ($type === 'binary' && $length === 16) {
                return 'uuid_binary';
            }

            // UUID string (36 chars) o ULID (26 chars)
            if (in_array($type, ['string', 'guid'])) {
                if ($length === 36) {
                    return 'uuid_string';
                }
                if ($length === 26) {
                    return 'ulid';
                }
            }

            // Integer
            if (str_contains($type, 'int')) {
                return 'int';
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function validatePrerequisites(): bool
    {
        // Verificar que existe tabla users
        if (! Schema::hasTable('users')) {
            $this->error('❌ Table "users" does not exist. Please run Laravel migrations first.');
            $this->comment('Run: php artisan migrate');

            return false;
        }

        return true;
    }

    protected function publishMigrationsInOrder(): int
    {
        $packagePath = dirname(__DIR__, 3).'/database/migrations';
        $targetPath = database_path('migrations');
        $timestamp = now();

        $published = 0;

        foreach ($this->migrationOrder as $order => $migrationName) {
            // Buscar el archivo stub (con o sin .stub)
            $stubFiles = [
                "{$packagePath}/{$migrationName}.php.stub",
                "{$packagePath}/{$migrationName}.php",
            ];

            // Buscar también con prefijos de fecha antiguos (2024_*)
            foreach (File::glob("{$packagePath}/????_??_??_??????_{$migrationName}.php*") as $file) {
                $stubFiles[] = $file;
            }

            $stubFile = null;
            foreach ($stubFiles as $file) {
                if (File::exists($file)) {
                    $stubFile = $file;
                    break;
                }
            }

            if (! $stubFile) {
                $this->warn("⚠ Migration stub not found: {$migrationName}");

                continue;
            }

            // Generar timestamp incremental para mantener orden
            $migrationTimestamp = $timestamp->copy()->addSeconds((int) $order);
            $targetFile = $targetPath.'/'.$migrationTimestamp->format('Y_m_d_His').'_'.$migrationName.'.php';

            // No sobrescribir si existe y no se pasó --force
            if (File::exists($targetFile) && ! $this->option('force')) {
                continue;
            }

            // Copiar archivo
            File::copy($stubFile, $targetFile);
            $published++;
        }

        $this->info("✓ Published {$published} migrations");

        return $published;
    }
}

