<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class LarabillInstallCommand extends Command
{
    protected $signature = 'larabill:install
                            {--user-id-type= : User ID type (uuid, int, ulid)}
                            {--force : Force overwrite of existing migrations and publish in non-production}
                            {--no-migrate : Skip running migrations}';

    protected $description = 'Install Larabill package with proper configuration';

    /**
     * Orden correcto de migraciones para evitar errores de FK
     */
    protected array $migrationOrder = [
        // === BASE TABLES (no FK to other package tables) ===
        '001' => 'create_legal_entity_types_table',
        '002' => 'create_tax_rates_table',
        '003' => 'create_tax_groups_table',
        '004' => 'create_tax_group_tax_rate_table',
        '005' => 'create_tax_categories_table',
        '006' => 'create_unit_measures_table',
        '007' => 'create_country_vat_rates_table',
        '008' => 'create_vat_categories_table',
        // === TABLES WITH FK TO USERS ===
        '009' => 'create_user_tax_infos_table',
        '010' => 'create_user_tax_profiles_table',
        '011' => 'create_company_fiscal_configs_table',
        // === MAIN TABLES ===
        '012' => 'create_invoice_series_control_table',
        '013' => 'create_invoices_table',
        '014' => 'create_invoice_items_table',
        '015' => 'create_articles_table',
        '016' => 'create_article_prices_table',
        '017' => 'create_article_overrides_table',
        '018' => 'create_article_service_status_table',
        '019' => 'create_commissions_table',
        '020' => 'create_vat_verifications_table',
        '021' => 'create_invoice_templates_table',
        '022' => 'create_company_template_settings_table',
        // === ROI/VAT ===
        '023' => 'create_eu_sales_thresholds_table',
        '024' => 'create_roi_queries_table',
        '025' => 'create_user_roi_verifications_table',
        // === ALTERATIONS (after creating tables) ===
        '026' => 'add_fiscal_relations_to_invoices',
        '027' => 'drop_fiscal_settings_table',
        '028' => 'add_v040_fields_to_invoices_table',
        '029' => 'add_converted_fields_to_invoices_table',
        '030' => 'add_invoices_foreign_keys',
        '031' => 'make_articles_translatable',
        '032' => 'repair_article_customer_id_columns',
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
            '--tag'      => 'larabill-config',
            '--force'    => $this->option('force'),
        ]);

        // 4. Publicar migraciones EN ORDEN (solo en production o con --force)
        if ($this->shouldPublishMigrations()) {
            $this->info('📄 Publishing migrations in correct order...');
            $published = $this->publishMigrationsInOrder();

            if ($published === 0) {
                $this->comment('⚠ No new migrations to publish (use --force to overwrite)');
            }
        } else {
            $this->info('✓ Migrations auto-loaded from package (non-production environment)');
            $this->comment('  Migrations are loaded directly via ServiceProvider — no publishing needed.');
            $this->comment('  Use --force to publish copies anyway, or deploy to production where publishing is required.');
            $published = 0;
        }

        // 5. Ejecutar migraciones si no se especificó --no-migrate
        if (! $this->option('no-migrate')) {
            $this->newLine();

            // En modo no-interactivo o producción, solo informar
            if ($this->option('no-interaction') || app()->environment('production')) {
                $this->info('✓ Migrations published successfully');
                $this->newLine();
                $this->comment('📋 Next step:');
                $this->line('   Run migrations: php artisan migrate --force');
                $this->newLine();
            } else {
                // En desarrollo, preguntar si quiere migrar ahora
                if ($this->confirm('Run migrations now?', true)) {
                    $this->info('🔄 Running migrations...');
                    $exitCode = $this->call('migrate');

                    if ($exitCode !== 0) {
                        $this->error('❌ Migration failed. Please check the errors above.');

                        return self::FAILURE;
                    }

                    $this->newLine();
                }
            }
        } else {
            $this->newLine();
            $this->comment('⏭ Migrations skipped (--no-migrate flag)');
            $this->line('   Run migrations manually: php artisan migrate --force');
            $this->newLine();
        }

        $this->newLine();
        $this->info('✅ Larabill installed successfully!');
        $this->newLine();

        // Mostrar próximos pasos según el contexto
        if ($this->option('no-migrate') || $this->option('no-interaction') || app()->environment('production')) {
            $this->comment('📋 Next steps:');
            $this->line('  1. Run migrations: php artisan migrate --force');
            $this->line('  2. Configure .env with LARABILL_* variables (optional)');
            $this->line('  3. Optimize cache: php artisan config:cache');
            $this->line('  4. Check documentation: https://github.com/aichadigital/larabill');
        } else {
            $this->comment('📋 Optional steps:');
            $this->line('  - Configure .env with LARABILL_* variables');
            $this->line('  - Run seeders if needed: php artisan db:seed');
            $this->line('  - Check documentation: https://github.com/aichadigital/larabill');
        }

        return self::SUCCESS;
    }

    protected function detectOrAskUserIdType(): string
    {
        // 1. Si se pasó por opción CLI, usar ese
        if ($type = $this->option('user-id-type')) {
            return $type;
        }

        // 2. Consultar config/env (LARABILL_USER_ID_TYPE) — tiene prioridad sobre schema
        $configured = config('larabill.user_id_type', 'uuid');
        if ($configured !== 'auto' && MigrationHelper::isSupportedIdType($configured)) {
            $this->comment("Using configured user ID type from env/config: {$configured}");

            return $configured;
        }

        // 3. Auto-detect desde schema solo si config es 'auto' o no reconocido
        if (Schema::hasTable('users')) {
            $detected = $this->detectUserIdTypeFromTable();

            if ($detected) {
                $this->comment("Detected user ID type from schema: {$detected}");

                if ($this->confirm("Use detected type '{$detected}'?", true)) {
                    return $detected;
                }
            }
        }

        // 4. Preguntar al usuario como último recurso
        return $this->choice(
            'What type of user ID does your application use?',
            ['uuid', 'int', 'ulid'],
            0
        );
    }

    protected function detectUserIdTypeFromTable(): ?string
    {
        try {
            $connection = Schema::getConnection();
            $type       = Schema::getColumnType('users', 'id');

            // Get column details using native database queries
            $columnDetails = $this->getColumnDetails($connection, 'users', 'id');
            $length        = $columnDetails['length'] ?? null;

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

    protected function getColumnDetails($connection, string $table, string $column): array
    {
        $driver = $connection->getDriverName();

        return match ($driver) {
            'mysql'  => $this->getMysqlColumnDetails($connection, $table, $column),
            'pgsql'  => $this->getPostgresColumnDetails($connection, $table, $column),
            'sqlite' => $this->getSqliteColumnDetails($connection, $table, $column),
            default  => [],
        };
    }

    protected function getMysqlColumnDetails($connection, string $table, string $column): array
    {
        $result = $connection->selectOne(
            "SHOW COLUMNS FROM {$table} WHERE Field = ?",
            [$column]
        );

        if (! $result) {
            return [];
        }

        // Parse type like "varchar(36)" or "int(11)"
        if (preg_match('/\((\d+)\)/', $result->Type, $matches)) {
            return ['length' => (int) $matches[1]];
        }

        return [];
    }

    protected function getPostgresColumnDetails($connection, string $table, string $column): array
    {
        $result = $connection->selectOne(
            'SELECT character_maximum_length
             FROM information_schema.columns
             WHERE table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $result ? ['length' => $result->character_maximum_length] : [];
    }

    protected function getSqliteColumnDetails($connection, string $table, string $column): array
    {
        $result = $connection->selectOne("PRAGMA table_info({$table})");

        if (! $result) {
            return [];
        }

        // Parse SQLite type definition
        if (preg_match('/\((\d+)\)/', $result->type ?? '', $matches)) {
            return ['length' => (int) $matches[1]];
        }

        return [];
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

    protected function shouldPublishMigrations(): bool
    {
        // Always publish if --force is explicitly passed
        if ($this->option('force')) {
            return true;
        }

        // In production, migrations MUST be published (ServiceProvider skips loadMigrationsFrom)
        if (app()->environment('production')) {
            return true;
        }

        // In non-production, ServiceProvider auto-loads via loadMigrationsFrom()
        // Publishing would create duplicates causing "table already exists" errors
        return false;
    }

    protected function publishMigrationsInOrder(): int
    {
        $packagePath = dirname(__DIR__, 2).'/database/migrations';
        $targetPath  = database_path('migrations');
        $timestamp   = now();

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

            // Check if a migration with this name already exists in target (any timestamp)
            $existingFiles = File::glob("{$targetPath}/*_{$migrationName}.php");
            if (! empty($existingFiles) && ! $this->option('force')) {
                continue;
            }

            // Generar timestamp incremental para mantener orden
            $migrationTimestamp = $timestamp->copy()->addSeconds((int) $order);
            $targetFile         = $targetPath.'/'.$migrationTimestamp->format('Y_m_d_His').'_'.$migrationName.'.php';

            // If forcing, remove existing files with the same migration name first
            if ($this->option('force') && ! empty($existingFiles)) {
                foreach ($existingFiles as $existing) {
                    File::delete($existing);
                }
            }

            // Copiar archivo (strip .stub extension if present)
            File::copy($stubFile, $targetFile);
            $published++;
        }

        $this->info("✓ Published {$published} migrations");

        return $published;
    }
}
