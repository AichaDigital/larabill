# Migration Consistency Design

- **Date:** 2026-02-16
- **Author:** Abdelkarim Mateos
- **Status:** Approved
- **Scope:** larabill package migration architecture cleanup

---

## Problem Statement

The larabill package has accumulated inconsistencies in its migration system that cause:

1. **8 stub-only files** that don't auto-load via ServiceProvider in development
2. **6 ghost entries** in `LarabillInstallCommand::$migrationOrder` referencing non-existent files
3. **No test coverage** for the `int` and `ulid` user ID types (only `uuid` is tested)
4. **Insufficient AI agent memory** causing repeated confusion about migration patterns after context compaction

### Verified Facts

**Stub-only files (no .php timestamped counterpart):**

- `create_country_vat_rates_table.php.stub`
- `create_vat_categories_table.php.stub`
- `create_eu_sales_thresholds_table.php.stub`
- `create_roi_queries_table.php.stub`
- `create_user_roi_verifications_table.php.stub`
- `create_user_tax_infos_table.php.stub`
- `add_user_relationships_to_users_table.php.stub` (modifies consumer's users table)
- `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub` (ADR-004 migration)

**Ghost entries in $migrationOrder:**

- `create_users_table` — consumer migration, not package
- `create_test_users_table` — test-only migration
- `create_issuer_config_table` — never existed
- `create_issuer_tax_profiles_table` — never existed
- `create_customers_table` — removed by ADR-003
- `create_customer_tax_profiles_table` — removed by ADR-003

---

## Design

### S1. Convert 6 stubs to timestamped .php

**Convert** these 6 stubs to timestamped `.php` files (package tables that must auto-load):

| Stub | New .php file |
|------|--------------|
| `create_country_vat_rates_table.php.stub` | `2026_02_16_000001_create_country_vat_rates_table.php` |
| `create_vat_categories_table.php.stub` | `2026_02_16_000002_create_vat_categories_table.php` |
| `create_eu_sales_thresholds_table.php.stub` | `2026_02_16_000003_create_eu_sales_thresholds_table.php` |
| `create_roi_queries_table.php.stub` | `2026_02_16_000004_create_roi_queries_table.php` |
| `create_user_roi_verifications_table.php.stub` | `2026_02_16_000005_create_user_roi_verifications_table.php` |
| `create_user_tax_infos_table.php.stub` | `2026_02_16_000006_create_user_tax_infos_table.php` |

**Keep as .stub-only** (modify consumer's users table, NOT package tables):

- `add_user_relationships_to_users_table.php.stub`
- `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`

**Keep existing .stub files** for the 6 converted tables (needed by `larabill:install` in production).

**Remove** `loadStubMigrations()` from `TestCase.php` (no longer needed).

### S2. Clean up $migrationOrder

Remove 6 ghost entries. Rewrite with entries matching real files 1:1:

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

### S3. MigrationHelper Tests

New file: `tests/Unit/Support/MigrationHelperTest.php`

Tests:

- Creates correct column type for `uuid` config (char 36)
- Creates correct column type for `int` config (unsignedBigInteger)
- Creates correct column type for `ulid` config (char 26)
- Respects config priority chain: CLI > ENV/config > auto-detect > default
- Rejects unsupported ID types
- `agnosticIdColumn()` works with nullable and index options

### S4. Documentation Updates

**CONTRIBUTING.md:**

- Explicit distinction: .php = package tables (auto-load), .stub = consumer table modifications (publish)
- Complete list of the 2 special stubs
- Rule: every new package table -> .php timestamped + .stub

**SCHEMA_REQUIREMENTS.md:**

- Add "Migration File Inventory" section
- Update version table

**.claude/project.md:**

- Update migration rules section

**ArabeAicha memory file:**

- `/Users/abkrim/.claude/projects/-Users-abkrim-SitesComplex-ArabeAicha/memory/larabill-peculiarities.md`
- Update stub section (no more stub-only package tables)

### S4b. Forced Reading Mechanism

**MEMORY.md** (always in system prompt):

- Compact critical rules about migrations
- References to detailed files

**Hook** `user-prompt-submit`:

- Script that injects extended migration context
- Triggers on sessions working in larabill or consumer projects
- Reads `.claude/CRITICAL_RULES.md` and injects as context

---

## Out of Scope

- Removing ID type agnosticism (Approach C — rejected)
- Duplicating full test suite for `int` type
- Modifying consumer projects (Larafactu, ArabeAicha) — separate task

---

## Risk Assessment

- **Low risk**: Converting stubs to .php is additive (existing .php behavior unchanged)
- **Medium risk**: Changing $migrationOrder could affect `larabill:install` in production — mitigated by testing the command
- **Low risk**: MigrationHelper tests are purely additive
