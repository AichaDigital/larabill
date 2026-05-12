# Contributing to Larabill

- **Package:** aichadigital/larabill
- **Role:** Core billing package for Larafactu ecosystem


## Migration Pattern - CRITICAL

This package serves as the **reference implementation** for all AichaDigital packages.


### Required Pattern

Migrations load automatically via `loadMigrationsFrom()` in the ServiceProvider's `boot()` method:

```php
public function boot(): void
{
    parent::boot();

    if ($this->app->runningInConsole() && ! $this->app->environment('production')) {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

In production, migrations are published via `php artisan larabill:install`.


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


### Migration Files Rules

- MUST be `.php` files for package tables (NOT `.stub` only)
- MUST have timestamps in filename
- MUST use `MigrationHelper` for user ID columns

```php
use AichaDigital\Larabill\Support\MigrationHelper;

MigrationHelper::userIdColumn($table, 'user_id');
```


### DO NOT USE

- `hasMigration()` in `configurePackage()` - requires manual publishing
- Direct `$table->foreignId('user_id')` - breaks UUID compatibility


### Adding a New Table

1. Create `database/migrations/YYYY_MM_DD_HHMMSS_create_table_name.php`
2. Create `database/migrations/create_table_name.php.stub` (same content)
3. Add entry to `LarabillInstallCommand::$migrationOrder`
4. Use `MigrationHelper::userIdColumn()` for any FK to users
5. Run tests: `vendor/bin/pest`


## Why This Pattern Matters

Larafactu uses a web-based installer that:

1. Downloads packages via Composer
2. Runs `php artisan migrate` automatically
3. Requires no manual intervention

Using `.stub` files with `hasMigration()` breaks this flow because it requires `php artisan vendor:publish` before migrations can run.


## MySQL Integration Tests (opt-in)

The default suite runs against SQLite in-memory for fast feedback. The
real schema contract — UUID-first per ADR-006 — is verified against a real
MySQL 8 server in `tests/Integration/Mysql/`. These tests are
**skipped** unless the following environment variables are set:

```bash
LARABILL_TEST_MYSQL_HOST=127.0.0.1
LARABILL_TEST_MYSQL_PORT=33106
LARABILL_TEST_MYSQL_DATABASE=larabill_test
LARABILL_TEST_MYSQL_USERNAME=root
LARABILL_TEST_MYSQL_PASSWORD=root
```

Quick local run with Docker:

```bash
docker run -d --rm --name larabill-mysql-test \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=larabill_test \
  -p 33106:3306 \
  mysql:8

until docker exec larabill-mysql-test mysqladmin ping -h 127.0.0.1 -uroot -proot --silent; do sleep 2; done

LARABILL_TEST_MYSQL_HOST=127.0.0.1 \
LARABILL_TEST_MYSQL_PORT=33106 \
LARABILL_TEST_MYSQL_DATABASE=larabill_test \
LARABILL_TEST_MYSQL_USERNAME=root \
LARABILL_TEST_MYSQL_PASSWORD=root \
vendor/bin/pest tests/Integration/Mysql/

docker stop larabill-mysql-test
```

CI runs them automatically in the `mysql-integration` job
(PHP 8.3 + Laravel 12 + MySQL 8). See
`docs/ADR-006-uuid-first-no-agnostic.md` for the contract rationale and
`tests/Integration/Mysql/FreshInstallTest.php` for what is asserted.

## Testing — Factory Uniqueness Pattern

Several factories use `$this->faker->unique()->...()` to satisfy UNIQUE
constraints (e.g. `CountryVatRateFactory.country_code`,
`UnitMeasureFactory.code`, `ArticleFactory.code`). Faker's `unique()` state
is scoped **per factory instance** — a fresh `::new()` resets it. State
overrides like `->spanish()` (which forces `country_code = 'ES'`) bypass
the `unique()` pool entirely.

The flaky pattern that combines both:

1. `Factory::new()->someState()->create()` — inserts a row with a
   hardcoded value for the unique column (state bypass).
2. `Factory::new()->create([...])` — a fresh instance whose `unique()`
   pool does not know the value from step 1 may randomly pick it.
3. UNIQUE constraint violation on commit. Frequency depends on the size
   of the Faker pool (e.g. `countryCode()` ≈ 250 → ~1/250 per pair).

The same pattern applies when a **seeder** hardcodes values that overlap
with the Faker pool. `UnitMeasuresSeeder` inserts common English words
(`unit`, `liter`, `hour`, `day`, `month`) that `Faker::word()` can
return — currently latent because no test mixes the seeder with
`UnitMeasureFactory`, but a future test that does will be flaky from
day one.

### Rules

- **In any test that fixes a unique field via a state or explicit value,
  ALSO fix it on every subsequent `Factory::new()->create(...)` in the
  same test.** Pick values outside the Faker pool when possible, or at
  least outside the set of fixed-state values.
- **When adding a new fixed-state to a factory** (like `spanish()`,
  `french()`), prefer values that cannot collide with the underlying
  Faker generator (e.g. `'PERSONA_FISICA'` vs `word()` is safe — the
  hardcode has underscores, `word()` returns single words).
- **When a seeder hardcodes values for a UNIQUE column whose factory
  uses `unique()`**, isolate the factory's pool (e.g. prefix it like
  `'ART-????'`) so seeder + factory cannot overlap.

### Reference incident

2026-05-11 — `CountryVatRateTest::can validate rate data integrity with
base 100 format` flaked ~1/250 by combining `->spanish()->create()` with
two subsequent `CountryVatRateFactory::new()->create()` calls. Fix:
pinned `country_code => 'IL'` and `'IT'` on the random ones. See the
test for the canonical pattern.

## Full Documentation

See: `larafactu/docs/internal/PACKAGE_DEVELOPMENT_STANDARDS.md`


---

*Last updated: 2026-05-11*
