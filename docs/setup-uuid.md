# Setting up a Laravel app for Larabill (UUID v7)

> **Audience**: developers integrating `aichadigital/larabill` into a Laravel 12+ application.
> **Prerequisite**: your `users.id` column must be **UUID v7 string char(36)**. Larabill is UUID-first by design (see [ADR-006](ADR-006-uuid-first-no-agnostic.md)). bigint and ULID are out of scope.

This guide takes a fresh Laravel 12+ application from `laravel new` to `larabill:install` in ~15 minutes. If your application already has a `users` table with bigint IDs, you must migrate it to UUID before installing Larabill — that conversion is your responsibility (Larabill does not provide it) and is non-trivial in apps with existing data.

## TL;DR — 4 changes before installing Larabill

1. Change `users.id` to UUID in the migration.
2. Add `HasUuids` trait to the `User` model.
3. (Optional) Override `LARABILL_USER_MODEL` in `.env` if your User model is not `App\Models\User`.
4. Run `php artisan larabill:install`.

The install command runs a preflight check on `users.id`. If the column is not UUID-compatible, it aborts with a pointer back to this document.

## Step 1: Migration — `users.id` as UUID

Edit your `users` table migration (in a fresh Laravel 12 install, this is `database/migrations/0001_01_01_000000_create_users_table.php`):

```php
Schema::create('users', function (Blueprint $table) {
    $table->uuid('id')->primary();          // was: $table->id()
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();
});
```

Apply the same change to any related table that references `users.id`. In a fresh Laravel install, that includes:

- `password_reset_tokens` (no FK by default — only `email`)
- `sessions.user_id`:

  ```php
  $table->foreignUuid('user_id')->nullable()->index();   // was: $table->foreignId('user_id')...
  ```

If your application already has other tables referencing `users.id` (custom features, other packages), you must update those FK column types as well. A `php artisan migrate:fresh` against a UUID `users` table will fail loudly on any bigint FK pointing at it — that is the surface where you discover what you missed.

## Step 2: User model — `HasUuids` trait

Edit `app/Models/User.php`:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasUuids;        // ← add this
    use Notifiable;

    // ... your existing $fillable, $hidden, casts, etc.
}
```

`HasUuids` (Laravel 11+) auto-generates a UUID v7 on model creation, so you do not need to write a `creating` event. It uses `Str::orderedUuid()` under the hood.

If you already had data and need to keep it, this trait does not retroactively change existing rows. Migration of existing data from bigint to UUID is out of scope.

## Step 3: (Optional) Custom User model

If your project uses a User model other than `App\Models\User`, set it in `.env`:

```dotenv
LARABILL_USER_MODEL=App\\Models\\Account
```

Note the double backslash — `.env` files unescape one level. The custom model must use `HasUuids` (or generate UUIDs equivalently) and have a UUID `id` column.

You do not need to set `LARABILL_USER_ID_TYPE` — that variable was removed in v0.8.0. The package assumes UUID unconditionally.

## Step 4: Install Larabill

Once `users.id` is UUID and the `User` model has `HasUuids`:

```bash
php artisan migrate                # apply your users migration first
composer require aichadigital/larabill
php artisan larabill:install
```

The `larabill:install` command:

1. **Preflight check** on `users.id`. Aborts immediately if the column type is not UUID-compatible (bigint, ULID, anything else).
2. Publishes config (`config/larabill.php`).
3. Publishes migrations in the correct order (FK dependencies respected).
4. Offers to run migrations (interactive, prompt skipped in `--no-interaction` mode).

Expected output on success:

```
🚀 Installing Larabill...
✓ User ID type: uuid (preflight passed)
📝 Publishing configurations...
📄 Publishing migrations in correct order...
✓ Published 31 migrations
🔄 Running migrations...
✅ Larabill installed successfully!
```

## Verification checklist

After install, verify:

- [ ] `php artisan migrate:status` shows all `*_larabill_*` migrations applied.
- [ ] `php artisan tinker` → `\App\Models\User::factory()->create()` returns a user with a 36-char UUID `id`.
- [ ] `php artisan tinker` → `DB::select("SHOW COLUMNS FROM invoices WHERE Field = 'customer_id'")` returns `Type: char(36)`.
- [ ] `php artisan tinker` → `DB::select("SHOW COLUMNS FROM user_tax_profiles WHERE Field = 'user_id'")` returns `Type: char(36)`.

If any of those return `bigint` or `varchar(26)`, the install is broken and you should not proceed to seed data — investigate why the preflight passed (likely a stale `users` table from a previous install).

## Troubleshooting

### `Cannot add foreign key constraint` during `larabill:install`

Cause: `users.id` is bigint but Larabill tried to create a `char(36)` FK against it. Means the preflight check was bypassed (unlikely) or `users` table is bigint despite your migration showing UUID (cached migration state).

Fix: drop and re-create the database, run your `users` migration first, then `larabill:install` again.

```bash
php artisan migrate:fresh
php artisan larabill:install
```

### `Class "Illuminate\Database\Eloquent\Concerns\HasUuids" not found`

Cause: Laravel < 11. Larabill requires Laravel 12+; that includes `HasUuids`.

Fix: upgrade your application to Laravel 12 or newer.

### `Larabill preflight failed: users.id type is bigint`

Cause: install command working as designed. Your migration created `users` with `$table->id()` (bigint).

Fix: edit the `users` migration as shown in [Step 1](#step-1-migration--usersid-as-uuid), `migrate:fresh`, and re-run install.

### My existing app already has data — can I migrate from bigint to UUID?

Larabill does not provide this. Migrating an existing app's primary key from bigint to UUID is a non-trivial data migration that touches every FK column in the application, every cached query, every external integration that stored IDs. If you genuinely need this:

1. Plan it as an application-wide migration, not a Larabill problem.
2. Generate UUIDs for existing rows, write them to a new column, switch FKs, drop old column.
3. Do this BEFORE installing Larabill. Larabill assumes a stable UUID-only state.

If the cost is prohibitive and you cannot move to UUID, Larabill is not the right package for your application. See [ADR-006](ADR-006-uuid-first-no-agnostic.md) for the rationale.

### Can I use ULID instead of UUID?

No. ULID was an option in earlier versions but was removed in v0.8.0 (see ADR-006). Use UUID v7 (`HasUuids` trait, which uses `Str::orderedUuid()`).

### How do I integrate with packages that don't support UUID?

That is not Larabill's concern. Larabill is a UUID-first product. If a third-party package in your stack requires bigint `users.id`, you have a stack-level conflict that must be resolved at the application architecture level, not at Larabill's level.

## Why UUID-first?

Short version: Larabill is fiscal-grade billing software for the AichaDigital ecosystem. UUID v7 (with embedded timestamp ordering) gives us:

- Distributed-safe IDs (no auto-increment lock contention)
- Sortable IDs without leaking insertion-order business intelligence
- Compatible with VeriFACTU / AEAT requirements where IDs may need to be exposed in fiscal documents
- Aligns with the rest of the AichaDigital package umbrella (see `~/development/packages/aichadigital/STANDARDS.md`)

Long version: see [ADR-006](ADR-006-uuid-first-no-agnostic.md) and [ADR-002](ADR-002-uuid-v7-string.md) (referenced from CHANGELOG).

## Related docs

- [ADR-006: UUID-first, no agnostic](ADR-006-uuid-first-no-agnostic.md) — the decision rationale.
- [ADR-003: Unification of Users and Customers](ADR-003-user-customer-unification.md) — explains why all billing receivers are unified under `User`.
- [SCHEMA_REQUIREMENTS.md](../SCHEMA_REQUIREMENTS.md) — full schema contract Larabill expects.
- [CONTRIBUTING.md](../CONTRIBUTING.md) — for package developers (migration patterns, tests).
