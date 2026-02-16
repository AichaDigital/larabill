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


## Full Documentation

See: `larafactu/docs/internal/PACKAGE_DEVELOPMENT_STANDARDS.md`


---

*Last updated: 2026-02-16*
