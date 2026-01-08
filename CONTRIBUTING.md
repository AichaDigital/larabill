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

    if ($this->app->runningInConsole()) {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```



### Migration Files

- MUST be `.php` files (NOT `.stub`)
- MUST have timestamps in filename
- MUST use `MigrationHelper` for user ID columns


```php
use AichaDigital\Larabill\Support\MigrationHelper;

MigrationHelper::userIdColumn($table, 'user_id');
```



### DO NOT USE

- `hasMigration()` in `configurePackage()` - requires manual publishing
- `.stub` migration files - not auto-loaded
- Direct `$table->foreignId('user_id')` - breaks UUID compatibility


## Why This Pattern Matters

Larafactu uses a web-based installer that:

1. Downloads packages via Composer
2. Runs `php artisan migrate` automatically
3. Requires no manual intervention

Using `.stub` files with `hasMigration()` breaks this flow because it requires `php artisan vendor:publish` before migrations can run.


## Full Documentation

See: `larafactu/docs/internal/PACKAGE_DEVELOPMENT_STANDARDS.md`


---

*Last updated: 2026-01-08*
