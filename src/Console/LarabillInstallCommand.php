<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class LarabillInstallCommand extends Command
{
    protected $signature = 'larabill:install
                            {--force : Force overwrite of existing migrations and publish in non-production}
                            {--no-migrate : Skip running migrations}';

    protected $description = 'Install Larabill package with proper configuration';

    /**
     * Orden correcto de migraciones para evitar errores de FK
     *
     * @var array<string, string>
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
        '021' => 'create_invoice_templates_table',
        '022' => 'create_company_template_settings_table',
        // === ROI/VAT ===
        '023' => 'create_eu_sales_thresholds_table',
        // === ALTERATIONS (after creating tables) ===
        '026' => 'add_fiscal_relations_to_invoices',
        '027' => 'drop_fiscal_settings_table',
        '028' => 'add_v040_fields_to_invoices_table',
        '029' => 'add_converted_fields_to_invoices_table',
        '030' => 'add_invoices_foreign_keys',
        '031' => 'make_articles_translatable',
        '032' => 'add_article_id_to_invoice_items_table',
        // === GROUPED PAYMENTS (AID-30) ===
        '033' => 'create_grouped_payments_table',
        '034' => 'create_grouped_payment_invoice_table',
        // === NUMBERING HARDENING (AID-390) ===
        '035' => 'backfill_invoice_series_control_global_scope',
    ];

    public function handle(): int
    {
        $this->info('🚀 Installing Larabill...');
        $this->newLine();

        // 1. Validar pre-requisitos básicos (existencia de tabla users)
        if (! $this->validatePrerequisites()) {
            return self::FAILURE;
        }

        // 2. Preflight UUID-first: users.id debe ser UUID compatible
        if (! $this->verifyUsersTableUuid()) {
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

    /**
     * Preflight check: users.id must be UUID v7 char(36) compatible.
     *
     * Larabill is UUID-first (ADR-006). bigint and ULID are out of scope.
     * Aborts with an actionable message pointing to docs/setup-uuid.md if
     * the column type is not UUID-compatible.
     */
    protected function verifyUsersTableUuid(): bool
    {
        try {
            $type = Schema::getColumnType('users', 'id');
        } catch (\Throwable $e) {
            $this->error('❌ Could not inspect users.id column: '.$e->getMessage());

            return false;
        }

        // Laravel reports varchar/char as 'string'; native uuid columns as 'guid' or 'uuid'.
        if (in_array($type, ['guid', 'uuid'], true)) {
            $this->info('✓ users.id verified UUID compatible');

            return true;
        }

        if (in_array($type, ['string', 'varchar', 'char'], true)) {
            $driver = DB::connection()->getDriverName();

            // SQLite has no fixed-length columns; trust the schema definition.
            if ($driver === 'sqlite') {
                $this->info('✓ users.id verified UUID compatible (sqlite, string column)');

                return true;
            }

            $length = $this->detectStringColumnLength();

            if ($length === 36) {
                $this->info('✓ users.id verified UUID compatible (char(36))');

                return true;
            }

            $this->failPreflight(
                "users.id is string of length {$length} — Larabill requires UUID v7 char(36)."
            );

            return false;
        }

        $this->failPreflight("users.id type is '{$type}' — Larabill requires UUID v7 char(36).");

        return false;
    }

    protected function detectStringColumnLength(): ?int
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql') {
                $row = DB::selectOne("SHOW COLUMNS FROM users WHERE Field = 'id'");
                if ($row && preg_match('/\((\d+)\)/', $row->Type, $m)) {
                    return (int) $m[1];
                }
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne(
                    "SELECT character_maximum_length AS len
                     FROM information_schema.columns
                     WHERE table_name = 'users' AND column_name = 'id'"
                );
                if ($row && $row->len !== null) {
                    return (int) $row->len;
                }
            }

            if ($driver === 'sqlite') {
                // SQLite has no strict types; sample data length as best-effort.
                $row = DB::selectOne('SELECT id FROM users LIMIT 1');
                if ($row && is_string($row->id)) {
                    return strlen($row->id);
                }
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return null;
    }

    protected function failPreflight(string $reason): void
    {
        $this->error('❌ Larabill preflight failed: '.$reason);
        $this->newLine();
        $this->comment('Larabill is UUID-first (ADR-006). bigint and ULID are out of scope.');
        $this->line('See: docs/setup-uuid.md for the consumer setup guide.');
        $this->line('See: docs/ADR-006-uuid-first-no-agnostic.md for the rationale.');
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
