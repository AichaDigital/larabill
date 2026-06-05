# AGENTS.md — larabill

Hard rules for any AI agent (Codex, Cursor, Claude, etc.) working in this repo.
Read this **before** editing. Claude Code also has `CLAUDE.md` and
`.claude/CRITICAL_RULES.md`; this file is the cross-agent source of truth for the
non-negotiable contracts. If you only read one thing, read the migration contract below.

## What this package is

Larabill is the core billing package of the Larafactu ecosystem: immutable
UUID v7 invoices, fiscal calculation (ES/EU/world), VAT verification. PHP 8.3+,
Laravel 12 or 13, Filament 4, Pest. AGPL-3.0-or-later.

## The migration contract (MOST violated by agents — do not be the next)

A package table migration is **three files that must change together**:

1. `database/migrations/YYYY_MM_DD_HHMMSS_<name>.php` — timestamped, auto-loaded in dev/tests.
2. `database/migrations/<name>.php.stub` — **dedicated**, same content, published to consumers by `larabill:install`.
3. An entry in `LarabillInstallCommand::$migrationOrder` mapping to that `<name>`.

Rules:

- **Never** add a `$migrationOrder` entry without its dedicated `.php.stub`. The
  install command has a timestamped-`.php` fallback that makes a missing stub
  *appear* to work and lets CI pass — this is exactly how v0.8.2 shipped a broken
  contract. A green build is **not** proof the contract holds.
- `$migrationOrder` maps **1:1** to `.php.stub` files. Only two stubs are
  consumer-only (they modify the consumer's `users` table, are not in the order,
  and have no `.php`): `add_user_relationships_to_users_table.php.stub`,
  `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`.
- This contract is enforced by `tests/Unit/Console/MigrationOrderConsistencyTest.php`.
  Run it after any migration change: `vendor/bin/pest tests/Unit/Console/MigrationOrderConsistencyTest.php`.
- FK columns referencing users: **always** `MigrationHelper::userIdColumn($table, 'col')`.
  Never `$table->foreignId('user_id')` — it breaks UUID compatibility.
- Known debt: six core tables have `.php` (dev) that diverges from their `.php.stub`
  (prod). The guardrail freezes this list (`LARABILL_KNOWN_SCHEMA_DIVERGENCES`) so it
  can only shrink. Do not add to it; reconcile the schemas instead (tracked in an ADR).

## Other hard rules

- **UUID-first (ADR-006).** Consumer `users.id` MUST be UUID v7 `char(36)`. bigint and
  ULID are out of scope. Never reintroduce `larabill.user_id_type` / `int` / `ulid`.
- **Money is Base-100 integers** (`12.34 € → 1234`), cast via `Base100Int` from `lara100`.
  Never float/decimal.
- **Issued invoices are immutable.** Do not edit anything with status ≠ `draft`.
- **Tests are agnostic about the user model:** use `config('larabill.user_model')` and the
  `TestCase::USER_UUID_1/2/3` constants — never hardcode `User::class` or `'user_id' => 1`.

## Commands (run inside this package dir)

```bash
composer test            # pest
composer pint            # format
composer phpstan         # static analysis (memory-limit=1G)
composer quality         # pint + phpstan + test-coverage
```

## Read next

- `CONTRIBUTING.md` — migration pattern in full, MySQL integration tests, factory pitfalls.
- `.claude/CRITICAL_RULES.md` — compact rule set.
- `docs/setup-uuid.md` — consumer onboarding (UUID requirement).
- `docs/ADR-006-uuid-first-no-agnostic.md` — why UUID-first.
