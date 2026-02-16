# Migration Consistency Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all migration inconsistencies so that package tables auto-load in all environments, tests cover all ID types, and AI agents have persistent context about migration rules.

**Architecture:** Convert 6 stub-only migrations to timestamped .php files, clean up LarabillInstallCommand, add MigrationHelper unit tests, and establish persistent memory via MEMORY.md + hook.

**Tech Stack:** Laravel 12, Pest, Orchestra Testbench, SQLite in-memory

---

## Task 1: Convert 6 stub-only migrations to timestamped .php files

**Files:**

- Create: `database/migrations/2026_02_16_000001_create_country_vat_rates_table.php`
- Create: `database/migrations/2026_02_16_000002_create_vat_categories_table.php`
- Create: `database/migrations/2026_02_16_000003_create_eu_sales_thresholds_table.php`
- Create: `database/migrations/2026_02_16_000004_create_roi_queries_table.php`
- Create: `database/migrations/2026_02_16_000005_create_user_roi_verifications_table.php`
- Create: `database/migrations/2026_02_16_000006_create_user_tax_infos_table.php`

**Step 1: Create .php copies from stubs**

For each of the 6 stubs, copy the content into a new timestamped .php file. The content is identical — only the filename changes. Keep the original .stub files (needed for `larabill:install` in production).

```bash
cd database/migrations
cp create_country_vat_rates_table.php.stub 2026_02_16_000001_create_country_vat_rates_table.php
cp create_vat_categories_table.php.stub 2026_02_16_000002_create_vat_categories_table.php
cp create_eu_sales_thresholds_table.php.stub 2026_02_16_000003_create_eu_sales_thresholds_table.php
cp create_roi_queries_table.php.stub 2026_02_16_000004_create_roi_queries_table.php
cp create_user_roi_verifications_table.php.stub 2026_02_16_000005_create_user_roi_verifications_table.php
cp create_user_tax_infos_table.php.stub 2026_02_16_000006_create_user_tax_infos_table.php
```

**Step 2: Run tests to verify no "table already exists" errors**

```bash
vendor/bin/pest
```

Expected: Tests pass. The `loadStubMigrations()` in TestCase will try to create tables that already exist from the .php files — this WILL cause errors. That's expected and fixed in Task 2.

---

## Task 2: Remove loadStubMigrations() from TestCase

**Files:**

- Modify: `tests/TestCase.php`

**Step 1: Remove the loadStubMigrations method and its call**

In `tests/TestCase.php`, modify `defineDatabaseMigrations()`:

```php
// BEFORE:
protected function defineDatabaseMigrations()
{
    $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    $this->loadStubMigrations();
}

// AFTER:
protected function defineDatabaseMigrations()
{
    $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
}
```

Remove the entire `loadStubMigrations()` method (lines 48-67 approximately).

**Step 2: Run tests to verify everything passes**

```bash
vendor/bin/pest
```

Expected: All 959+ tests PASS. The 6 tables now auto-load via `loadMigrationsFrom()` from the new .php files.

**Step 3: Commit**

```bash
git add database/migrations/2026_02_16_*.php tests/TestCase.php
git commit -m "refactor(migrations): convert 6 stub-only files to timestamped .php

Convert ROI/VAT and user_tax_infos tables from stub-only to proper
timestamped .php migrations for auto-loading via ServiceProvider.
Remove loadStubMigrations() hack from TestCase.

Stubs kept for larabill:install production publishing.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Clean up LarabillInstallCommand $migrationOrder

**Files:**

- Modify: `src/Console/LarabillInstallCommand.php`

**Step 1: Replace $migrationOrder array**

Replace the entire `$migrationOrder` property with:

```php
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
];
```

**Step 2: Run existing install command tests**

```bash
vendor/bin/pest --filter=LarabillInstallCommand
```

Expected: PASS

**Step 3: Commit**

```bash
git add src/Console/LarabillInstallCommand.php
git commit -m "fix(install): remove ghost entries from migrationOrder

Remove 6 non-existent entries (create_users_table, create_test_users_table,
create_issuer_config_table, create_issuer_tax_profiles_table,
create_customers_table, create_customer_tax_profiles_table).
Reorder to match actual file inventory with FK dependencies.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Add MigrationHelper unit tests

**Files:**

- Create: `tests/Unit/Support/MigrationHelperTest.php`

**Step 1: Write the tests**

```php
<?php

declare(strict_types=1);

use AichaDigital\Larabill\Support\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

describe('MigrationHelper', function () {
    afterEach(function () {
        Schema::dropIfExists('test_migration_helper');
    });

    it('creates uuid column when configured as uuid', function () {
        config()->set('larabill.user_id_type', 'uuid');

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
        $type = Schema::getColumnType('test_migration_helper', 'user_id');
        // SQLite reports 'string' for uuid/char columns
        expect($type)->toBeIn(['string', 'guid']);
    });

    it('creates integer column when configured as int', function () {
        config()->set('larabill.user_id_type', 'int');

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'user_id'))->toBeTrue();
        $type = Schema::getColumnType('test_migration_helper', 'user_id');
        expect($type)->toBeIn(['integer', 'bigint']);
    });

    it('creates ulid column when configured as ulid', function () {
        config()->set('larabill.user_id_type', 'ulid');

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'ulid_user_id');
        });

        expect(Schema::hasColumn('test_migration_helper', 'ulid_user_id'))->toBeTrue();
        $type = Schema::getColumnType('test_migration_helper', 'ulid_user_id');
        expect($type)->toBeIn(['string', 'guid']);
    });

    it('creates nullable column when requested', function () {
        config()->set('larabill.user_id_type', 'uuid');

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::userIdColumn($table, 'nullable_user_id', nullable: true);
        });

        expect(Schema::hasColumn('test_migration_helper', 'nullable_user_id'))->toBeTrue();
    });

    it('validates supported id types', function () {
        expect(MigrationHelper::isSupportedIdType('uuid'))->toBeTrue();
        expect(MigrationHelper::isSupportedIdType('int'))->toBeTrue();
        expect(MigrationHelper::isSupportedIdType('ulid'))->toBeTrue();
        expect(MigrationHelper::isSupportedIdType('binary'))->toBeFalse();
        expect(MigrationHelper::isSupportedIdType('invalid'))->toBeFalse();
    });

    it('returns human-readable descriptions', function () {
        expect(MigrationHelper::getIdTypeDescription('uuid'))->toContain('UUID');
        expect(MigrationHelper::getIdTypeDescription('int'))->toContain('Integer');
        expect(MigrationHelper::getIdTypeDescription('ulid'))->toContain('ULID');
        expect(MigrationHelper::getIdTypeDescription('invalid'))->toContain('Unknown');
    });

    it('reads config priority correctly', function () {
        // When explicitly set, use it
        config()->set('larabill.user_id_type', 'int');
        expect(MigrationHelper::getUserIdType())->toBe('int');

        // When set to auto, falls back to detection then default
        config()->set('larabill.user_id_type', 'auto');
        $type = MigrationHelper::getUserIdType();
        expect(MigrationHelper::isSupportedIdType($type))->toBeTrue();
    });

    it('creates agnostic id column with index', function () {
        config()->set('larabill.user_id_type', 'uuid');

        Schema::create('test_migration_helper', function (Blueprint $table) {
            $table->id();
            MigrationHelper::agnosticIdColumn($table, 'client_id', nullable: true, index: true);
        });

        expect(Schema::hasColumn('test_migration_helper', 'client_id'))->toBeTrue();
    });

    it('defaults to uuid when config is not set', function () {
        config()->set('larabill.user_id_type', null);
        expect(MigrationHelper::getUserIdType())->toBe('uuid');
    });
});
```

**Step 2: Run the new tests**

```bash
vendor/bin/pest tests/Unit/Support/MigrationHelperTest.php
```

Expected: All PASS

**Step 3: Run full suite to verify no regressions**

```bash
vendor/bin/pest
```

Expected: All 960+ tests PASS

**Step 4: Commit**

```bash
git add tests/Unit/Support/MigrationHelperTest.php
git commit -m "test: add MigrationHelper unit tests for uuid/int/ulid

Tests column creation for all 3 supported ID types,
config priority chain, nullable/index options, and
agnosticIdColumn helper.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Update CONTRIBUTING.md

**Files:**

- Modify: `CONTRIBUTING.md`

**Step 1: Rewrite with updated migration rules**

Replace full content with updated version that:

- Keeps the existing structure
- Adds explicit distinction between .php (package tables) and .stub (consumer modifications)
- Lists the 2 special stubs
- Adds the "every new table" rule

Key section to add after existing "DO NOT USE":

```markdown
### Two Types of Migration Files

**`.php` (timestamped)** — Package tables that auto-load via ServiceProvider:

- Every table owned by larabill MUST have a timestamped `.php` file
- Auto-loaded in development via `loadMigrationsFrom()`
- Example: `2024_12_01_000003_create_invoices_table.php`

**`.php.stub`** — For `larabill:install` production publishing:

- Every `.php` migration SHOULD have a corresponding `.stub`
- `LarabillInstallCommand::$migrationOrder` maps to these stubs
- Additionally, 2 stubs modify the CONSUMER's `users` table (no `.php` counterpart):
  - `add_user_relationships_to_users_table.php.stub`
  - `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`

### Adding a New Table

1. Create `database/migrations/YYYY_MM_DD_HHMMSS_create_table_name.php`
2. Create `database/migrations/create_table_name.php.stub` (same content)
3. Add entry to `LarabillInstallCommand::$migrationOrder`
4. Use `MigrationHelper::userIdColumn()` for any FK to users
```

**Step 2: Commit**

```bash
git add CONTRIBUTING.md
git commit -m "docs: update CONTRIBUTING.md with migration file rules

Clarify distinction between .php (package auto-load) and .stub
(consumer publishing). Document the 2 special consumer-only stubs.
Add 'Adding a New Table' checklist.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 6: Update SCHEMA_REQUIREMENTS.md

**Files:**

- Modify: `SCHEMA_REQUIREMENTS.md`

**Step 1: Add Migration File Inventory section**

Add before the "Migration Order" section:

```markdown
## Migration File Inventory

As of v0.6.x, the package contains these migration files:

### Package Tables (.php timestamped — auto-load in development)

| File | Table | FK Dependencies |
|------|-------|----------------|
| `create_tax_rates_table` | `tax_rates` | None |
| `create_tax_groups_table` | `tax_groups` | None |
| `create_tax_group_tax_rate_table` | `tax_group_tax_rate` | tax_rates, tax_groups |
| `create_tax_categories_table` | `tax_categories` | None |
| `create_unit_measures_table` | `unit_measures` | None |
| `create_legal_entity_types_table` | `legal_entity_types` | None |
| `create_country_vat_rates_table` | `country_vat_rates` | None |
| `create_vat_categories_table` | `vat_categories` | Self-referencing |
| `create_user_tax_infos_table` | `user_tax_infos` | users |
| `create_user_tax_profiles_table` | `user_tax_profiles` | users, legal_entity_types |
| `create_company_fiscal_configs_table` | `company_fiscal_configs` | users |
| `create_invoice_series_control_table` | `invoice_series_control` | None |
| `create_invoices_table` | `invoices` | users |
| `create_invoice_items_table` | `invoice_items` | invoices |
| `create_articles_table` | `articles` | None |
| `create_article_prices_table` | `article_prices` | articles |
| `create_article_overrides_table` | `article_overrides` | articles, users |
| `create_article_service_status_table` | `article_service_status` | articles, users |
| `create_commissions_table` | `commissions` | invoices, users |
| `create_vat_verifications_table` | `vat_verifications` | None |
| `create_invoice_templates_table` | `invoice_templates` | None |
| `create_company_template_settings_table` | `company_template_settings` | invoice_templates |
| `create_eu_sales_thresholds_table` | `eu_sales_thresholds` | users |
| `create_roi_queries_table` | `roi_queries` | users |
| `create_user_roi_verifications_table` | `user_roi_verifications` | users |

### Consumer-Only Stubs (.php.stub — published via larabill:install)

| Stub | Purpose |
|------|---------|
| `add_user_relationships_to_users_table` | Adds parent_user_id, relationship_type to consumers's users table |
| `rename_user_id_to_owner_user_id_in_user_tax_profiles` | ADR-004 migration for existing installations |
```

**Step 2: Update document version**

Change version to 2.2, date to 2026-02-16.

**Step 3: Commit**

```bash
git add SCHEMA_REQUIREMENTS.md
git commit -m "docs: add migration file inventory to SCHEMA_REQUIREMENTS.md

Complete table listing all .php and .stub migrations with FK
dependencies. Distinguishes package tables from consumer-only stubs.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 7: Update .claude/project.md

**Files:**

- Modify: `.claude/project.md`

**Step 1: Replace the "Package Agnosticism - Migrations vs Stubs" section**

Replace lines 180-207 with updated content reflecting:

- .php = package tables (auto-load), always timestamped
- .stub = dual purpose: publishing copies for production + 2 consumer-only stubs
- Rule: every package table has BOTH .php and .stub
- Only 2 stubs have no .php: `add_user_relationships` and `rename_user_id_to_owner_user_id`

**Step 2: Commit**

```bash
git add .claude/project.md
git commit -m "docs: update project.md migration rules for AI agents

Clarify .php vs .stub distinction. Remove outdated sync rule
referencing migrations_backup directory.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 8: Create MEMORY.md with critical rules

**Files:**

- Create: `/Users/abkrim/.claude/projects/-Users-abkrim-development-packages-aichadigital-larabill/memory/MEMORY.md`

**Step 1: Write MEMORY.md**

```markdown
# Larabill Critical Rules

## Migration Architecture (ALWAYS READ)

- **Package tables**: `.php` timestamped files in `database/migrations/` — auto-loaded via ServiceProvider
- **Consumer stubs**: `.php.stub` files — published via `larabill:install` for production
- **Every package table** has BOTH .php AND .stub
- **Only 2 stubs** have NO .php counterpart (they modify the consumer's users table):
  - `add_user_relationships_to_users_table.php.stub`
  - `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`
- **$migrationOrder** in LarabillInstallCommand MUST match real files 1:1
- **MigrationHelper::userIdColumn()** for ALL FK columns referencing users

## Before Touching Migrations

1. Read `CONTRIBUTING.md` (migration pattern)
2. Read `SCHEMA_REQUIREMENTS.md` (schema + FK order)
3. Verify .php has corresponding .stub
4. Verify $migrationOrder includes new entries

## ID Types: uuid (default), int, ulid

- Config: `larabill.user_id_type`
- Priority: CLI arg > ENV/config > auto-detect > default (uuid)
- Tests: `MigrationHelperTest` covers all 3 types

## Key Files

- `CONTRIBUTING.md` — Migration pattern rules
- `SCHEMA_REQUIREMENTS.md` — Schema requirements for consumers
- `.claude/project.md` — AI agent context
- `src/Support/MigrationHelper.php` — ID type agnosticism
- `src/Console/LarabillInstallCommand.php` — Production installer
```

This file is under 50 lines and will always be loaded in the system prompt.

**Step 2: No commit needed (memory files are not in the repo)**

---

## Task 9: Update ArabeAicha memory file

**Files:**

- Modify: `/Users/abkrim/.claude/projects/-Users-abkrim-SitesComplex-ArabeAicha/memory/larabill-peculiarities.md`

**Step 1: Update the "Ficheros .stub que existen" section**

Replace lines 45-50 with:

```markdown
### Ficheros .stub

Existen ficheros `.php.stub` en database/migrations/ para dos propósitos:

1. **Copias de publicación**: Cada tabla del paquete tiene .php (auto-load) + .stub (publish).
   `larabill:install` usa los .stub para copiar migraciones al proyecto consumidor en producción.

2. **Stubs especiales** (solo .stub, NO .php — modifican tabla users del consumidor):
   - `add_user_relationships_to_users_table.php.stub`
   - `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`

NO hay stubs-only para tablas del paquete. Todos tienen .php timestamped.
```

**Step 2: No commit needed (memory files are not in the repo)**

---

## Task 10: Create user-prompt-submit hook

**Files:**

- Create: `/Users/abkrim/development/packages/aichadigital/larabill/.claude/settings.json` (or update if exists)
- Create: `/Users/abkrim/development/packages/aichadigital/larabill/.claude/CRITICAL_RULES.md`

**Step 1: Check if .claude/settings.json exists and understand hook format**

Check Claude Code documentation for the hook format. The hook should output text that gets injected as context.

**Step 2: Create CRITICAL_RULES.md**

```markdown
# Larabill Migration Rules (Auto-injected)

STOP. Before working with migrations, read these rules:

1. Package tables = .php timestamped (auto-load). Consumer modifications = .stub only.
2. Every package table has BOTH .php and .stub. Only 2 stubs are consumer-only.
3. $migrationOrder in LarabillInstallCommand must match real files 1:1.
4. Use MigrationHelper::userIdColumn() for ALL user FK columns.
5. Full docs: CONTRIBUTING.md, SCHEMA_REQUIREMENTS.md
```

**Step 3: Configure hook in settings.json**

```json
{
  "hooks": {
    "user-prompt-submit": [
      {
        "command": "cat .claude/CRITICAL_RULES.md 2>/dev/null || true",
        "description": "Inject critical migration rules into every prompt"
      }
    ]
  }
}
```

**Step 4: Commit the CRITICAL_RULES.md (settings.json is local)**

```bash
git add .claude/CRITICAL_RULES.md
git commit -m "chore: add CRITICAL_RULES.md for AI agent hook injection

Compact migration rules file read by user-prompt-submit hook
to ensure AI agents always have migration context.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 11: Run full verification

**Step 1: Run full test suite**

```bash
vendor/bin/pest
```

Expected: All tests PASS (960+)

**Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse --no-progress
```

Expected: No errors

**Step 3: Run Pint**

```bash
vendor/bin/pint --test
```

Expected: PASS

**Step 4: Verify migration file inventory matches $migrationOrder**

```bash
# Every entry in $migrationOrder should have a .php or .stub file
# in database/migrations/
```

**Step 5: Final commit if any formatting fixes needed, then push**

```bash
git push origin main
```

---

## Summary

| Task | Type | Risk |
|------|------|------|
| 1. Convert 6 stubs to .php | File creation | Low |
| 2. Remove loadStubMigrations | Code change | Low |
| 3. Clean up $migrationOrder | Code change | Medium |
| 4. MigrationHelper tests | Test creation | Low |
| 5. Update CONTRIBUTING.md | Documentation | Low |
| 6. Update SCHEMA_REQUIREMENTS.md | Documentation | Low |
| 7. Update .claude/project.md | Documentation | Low |
| 8. Create MEMORY.md | Memory | Low |
| 9. Update ArabeAicha memory | Memory | Low |
| 10. Create hook + CRITICAL_RULES.md | Infrastructure | Low |
| 11. Full verification | Verification | None |
