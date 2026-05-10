# Larabill Agent Context

Read this file first when working on Larabill. It is the operational context for
AI coding agents and should stay factual, compact, and tied to this repository.

Last inspected: 2026-05-09.

## Repository Identity

Larabill is the core billing package for the AichaDigital/Larafactu ecosystem.
It is a Composer package for Laravel applications, not a standalone Laravel app.

Primary responsibilities:

- Invoice management with fiscal immutability.
- Tax calculation for Spain, EU, and worldwide scenarios.
- VAT/ROI verification through ecosystem packages.
- Fiscal data management for issuer and recipients.
- PDF invoice generation.
- Recurring billing and service lifecycle support.

Package coordinates:

- Composer package: `aichadigital/larabill`
- Namespace: `AichaDigital\Larabill`
- License: `AGPL-3.0-or-later`
- Primary branch: `main`
- Staging consumer: Larafactu

Ecosystem packages:

- `aichadigital/lara100`: base-100 monetary casts.
- `aichadigital/lararoi`: EU VAT/ROI verification.
- `aichadigital/lara-verifactu`: AEAT VeriFACTU integration.
- `aichadigital/larabill-filament`: Filament integration, separate from this core package.

## Source Of Truth

When sources conflict, prefer this order:

1. Current user instructions and repository `AGENTS.md` rules.
2. `composer.json` for actual dependency constraints and scripts.
3. `.claude/CRITICAL_RULES.md` for hard migration rules.
4. `SCHEMA_REQUIREMENTS.md` for host application schema contracts.
5. `CONTRIBUTING.md` for package migration workflow.
6. Current source code and tests.
7. README and older docs.

Before changing migrations or user-related schema, read:

- `.claude/CRITICAL_RULES.md`
- `SCHEMA_REQUIREMENTS.md`
- `CONTRIBUTING.md`
- `src/Support/MigrationHelper.php`
- `src/Console/LarabillInstallCommand.php`

## Current Stack

The current `composer.json` requires:

- PHP: `^8.3`
- Laravel components: `^12.0||^13.0`
- Testbench: `^10.6||^11.0`
- Pest: `^4.0`
- Larastan/PHPStan for static analysis.
- Laravel Pint for formatting.

Local inspection on 2026-05-09 found:

- PHP CLI: `8.4.20`
- Pest: `4.6.3`
- PHPStan: `2.1.51`
- Pint: `1.29.1`
- Testbench: `11.1.0`
- `composer validate --no-check-publish` passes.

There is a documented local PHP 8.4 + SQLite in-memory issue that may cause
`table already exists` failures. Prefer PHP 8.3 locally for full test runs when
that issue appears, or validate through CI.

## Hard Rules

Code, code comments, and docblocks are written in English.

Keep changes surgical:

- Touch only files required by the task.
- Do not clean adjacent code, formatting, or stale docs unless requested.
- Remove only imports, variables, functions, handlers, or config entries made
  unused by your own changes.
- Treat unrelated dirty files as user-owned.

Money is never stored as float or decimal:

- `12.34 EUR` is stored as `1234`.
- `21.5%` is stored as `2150`.
- Use base-100 integers and `Base100Int` where applicable.

Invoices are immutable once issued:

- Only draft invoices may be edited.
- Issued fiscal snapshots must not be rewritten.

User IDs are UUID-first (ADR-006, 2026-05-10):

- Sole supported type: UUID v7 char(36). bigint and ULID are out of scope.
- The `larabill.user_id_type` config and `LARABILL_USER_ID_TYPE` env were
  removed in v0.8.0; do not reintroduce them.
- Never assume a numeric `users.id`.
- Never use direct `$table->foreignId()` for user FKs.
- Use `MigrationHelper::userIdColumn($table, 'column_name')` — emits UUID.
- Consumer apps must provide `users.id` UUID v7 char(36); see
  `docs/setup-uuid.md`. The `larabill:install` preflight aborts otherwise.

## Migration Contract

This is the most important package-specific workflow.

Package-owned tables need both files:

- A timestamped `.php` migration for auto-loading in development and tests.
- A `.php.stub` migration for production publishing through `larabill:install`.

Consumer-only stubs modify the host application's `users` table and do not have
a timestamped `.php` counterpart:

- `add_user_relationships_to_users_table.php.stub`
- `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`

When modifying any package table migration:

1. Update the timestamped `.php` migration.
2. Update the matching `.php.stub`.
3. Keep `LarabillInstallCommand::$migrationOrder` aligned with real stubs.
4. Use `MigrationHelper` for every FK-like column that points to `users`.
5. Add focused tests for install and upgrade behavior when schema contracts move.

Do not use Spatie `hasMigration()` for this package. Production publishing is
controlled by `LarabillInstallCommand`.

## Architecture

Issuer:

- `CompanyFiscalConfig`
- Temporal issuer fiscal settings.
- Represents the company/software holder.

Recipients:

- Host app `users` table.
- `parent_user_id` models direct vs delegated billing relationships.
- `UserTaxProfile` stores recipient fiscal history.

Invoices:

- `Invoice` has UUID primary key.
- `user_id` is the owner/requester, not the issuer.
- `company_fiscal_config_id` is the issuer snapshot.
- `user_tax_profile_id` is the recipient snapshot.
- Invoice items are fiscal snapshots and must remain stable.

Articles and services:

- `Article` is the catalog item.
- `ArticlePrice` stores prices by billing frequency and validity window.
- `ArticleOverride` stores customer-specific overrides.
- `ArticleServiceStatus` stores contracted service instances and billing state.

Deprecated architecture:

- Do not reintroduce separate `Customer` entities.
- Do not reintroduce `CustomerFiscalData`.
- Use `User` relationships plus `UserTaxProfile`.

## Directory Map

Important paths:

- `config/larabill.php`: package configuration.
- `database/migrations/`: timestamped migrations and publishable stubs.
- `database/seeders/`: legacy lower-case seeders.
- `src/Database/Seeders/`: package namespace seeders.
- `resources/lang/`: English and Spanish translations.
- `resources/views/pdf/`: invoice PDF templates.
- `src/Actions/`: recurring billing and service lifecycle actions.
- `src/Concerns/`: Eloquent traits.
- `src/Console/`: Artisan commands.
- `src/Contracts/`: package interfaces.
- `src/DataTransferObjects/`: DTOs.
- `src/Enums/`: domain enums.
- `src/Events/`: domain events.
- `src/Listeners/`: event listeners.
- `src/Models/`: Eloquent models.
- `src/Services/`: business services.
- `src/Support/`: helpers such as `MigrationHelper`.
- `tests/`: Pest tests.
- `workbench/`: package workbench artifacts.

Main provider:

- `src/LarabillServiceProvider.php`

Main install command:

- `src/Console/LarabillInstallCommand.php`

## Testing And Quality Commands

Run commands from the package root.

Common checks:

```bash
composer validate --no-check-publish
composer test
composer test-parallel
composer phpstan
composer pint
composer precommit
```

Focused checks:

```bash
vendor/bin/pest tests/Unit/Models/InvoiceTest.php
vendor/bin/pest --filter='invoice'
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

CI matrix:

- PHP `8.3`, `8.4`
- Laravel `12.*`, `13.*`
- Ubuntu runner

For model relationship tests, resolve the configured user model rather than
hardcoding `Tests\Models\User`:

```php
$userModel = config('larabill.user_model');
expect($model->user)->toBeInstanceOf($userModel);
```

## Active Contract (UUID-first, 2026-05-10)

Larabill is in `dev-main` pre-v1.0. There are no production installations and
no datasets to preserve. The package therefore does NOT promise schema upgrade
across `dev-main` versions — internal consumers use `migrate:fresh` or recreate
tables.

What the package DOES promise and what is demonstrated by tests:

- **Fresh install on MySQL with UUID v7 `users.id`**. The full migration set
  runs cleanly via `artisan migrate`, every user-keyed column lands as
  `char(36)`, composite UNIQUE indexes exist with `customer_id` at position 0,
  and uniqueness is actively enforced.

Authoritative reference: `docs/ADR-006-uuid-first-no-agnostic.md`.
Implementation: `tests/Integration/Mysql/MysqlIntegrationTestCase.php` +
`tests/Integration/Mysql/FreshInstallTest.php`. CI: `mysql-integration` job in
`.github/workflows/tests.yml`.

The earlier blocker on upgrade coverage
(`docs/2026-05-09-blocker-upgrade-test-customer-id-bigint-to-uuid.md`) is
SUPERSEDED. The repair migration
`database/migrations/2026_05_08_000001_repair_article_customer_id_columns.php`
and its stub were removed as part of the same reframe — they communicated an
upgrade promise the package does not assume in `dev-main`.

## Documentation Drift To Treat Carefully

Do not fix these opportunistically. Mention them when relevant or fix them only
when the task asks for documentation/config cleanup.

- `README.md` and some docs still advertise Laravel `^11.0|^12.0`, while the
  current `composer.json` requires Laravel components `^12.0||^13.0`.
- Some `.claude` docs mention Filament 4 resources, while this package currently
  appears to be core/Filament-agnostic and uses a separate Filament package.
- `config/larabill.php` still references deprecated `Customer` and
  `CustomerFiscalData` model mappings; those classes are not part of the current
  `src/Models` tree inspected on 2026-05-09.

## Work Habits For Agents

At the start of a task:

1. Run `git status --short --branch`.
2. Read the specific source and tests related to the request.
3. State assumptions when the task has multiple plausible meanings.
4. Prefer the smallest implementation that satisfies a concrete verification.

Before editing:

- Identify the exact files to change.
- Avoid touching generated, unrelated, or user-modified files.
- For migrations, update `.php`, `.stub`, command order, and tests together.

Before claiming completion:

- Run the narrowest meaningful test first.
- Run broader checks when risk or blast radius justifies it.
- Report any checks skipped and why.

## Release And Dependency Notes

This package is managed as an independent repository inside an AichaDigital
package directory. Do not treat the parent directory as a monorepo.

Dependency bot status as of 2026-05-09:

- AichaDigital is moving from Dependabot to self-hosted Renovate.
- `renovate.json` exists in this package.
- Do not restore Dependabot setup unless specifically requested.

Tagging and release workflow live outside this context. Inspect `bin/tag-release`,
`CHANGELOG.md`, and current git history before release work.
