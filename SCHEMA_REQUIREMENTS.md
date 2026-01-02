# Schema Requirements for Larabill

This document defines the database schema requirements that the host application
must fulfill for larabill to function correctly.

**IMPORTANT**: This file is designed to be read by both humans and AI agents.
When working with larabill, always check this file first to understand what
database columns and tables are expected in the host application.

---

## Version Compatibility

| Larabill Version | Schema Version | Breaking Changes |
|------------------|----------------|------------------|
| 2.x (current)    | 2.0            | ADR-004: owner_user_id pattern |
| 1.x              | 1.0            | Initial release |

---

## Required Columns in `users` Table

The host application's `users` table MUST have these columns for larabill to work:

### Core Identity (Required)

| Column | Type | Nullable | Description | Since |
|--------|------|----------|-------------|-------|
| `id` | uuid/bigint | NO | Primary key. Type configurable via `LARABILL_USER_ID_TYPE` | 1.0 |

### ADR-003: User Relationships (Optional but Recommended)

| Column | Type | Nullable | Default | Description | Since |
|--------|------|----------|---------|-------------|-------|
| `parent_user_id` | same as id | YES | NULL | Self-reference for delegation hierarchy | 1.0 |
| `relationship_type` | unsignedTinyInteger | NO | 0 | 0=DIRECT, 1=DELEGATED (UserRelationshipType enum) | 1.0 |
| `display_name` | string | YES | NULL | Commercial name for billing | 1.0 |
| `legal_entity_type_code` | string(50) | YES | NULL | FK to legal_entity_types.code | 1.0 |

### ADR-004: Shared Tax Profiles (Required for v2.x)

| Column | Type | Nullable | Description | Since |
|--------|------|----------|-------------|-------|
| `current_tax_profile_id` | foreignId | YES | FK to user_tax_profiles.id - Multiple users can share same profile | 2.0 |

---

## Package Tables

These tables are created by larabill migrations (stubs). The host application
publishes and runs these migrations.

### `user_tax_profiles` (ADR-004)

Stores fiscal configuration for users with temporal validity.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `owner_user_id` | uuid/bigint | The user who OWNS this profile (FK to users.id) |
| `tax_id` | string(20) | NIF/CIF/VAT number |
| `tax_id_type` | unsignedTinyInteger | TaxIdType enum |
| `fiscal_name` | string | Legal name for invoices |
| `fiscal_address` | string | Fiscal address |
| `postal_code` | string(10) | Postal code |
| `city` | string | City |
| `province` | string | Province/State |
| `country_code` | char(2) | ISO 3166-1 alpha-2 |
| `is_active` | boolean | Whether profile is active |
| `valid_from` | date | Start of validity period |
| `valid_until` | date/null | End of validity (null = current) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Key Concepts**:

- `owner_user_id`: The user who created/owns this fiscal profile
- `current_tax_profile_id` (in users): Which profile a user is currently using
- Multiple users can point to the same profile via `current_tax_profile_id`
- Only one active profile per owner (enforced by model events)

---

## Migration Order

When setting up a new installation, migrations must run in this order:

1. Laravel's `create_users_table` (with uuid if using UUID mode)
2. Larabill's `create_legal_entity_types_table`
3. Application's billing fields migration (adds columns to users)
4. Larabill's `create_user_tax_profiles_table`
5. Other larabill tables (invoices, articles, etc.)

---

## Configuration

### Environment Variables

```env
# User ID type: 'uuid' (default) or 'bigint'
LARABILL_USER_ID_TYPE=uuid
```

### Model Mapping

In `config/larabill.php`:

```php
'models' => [
    'user' => \App\Models\User::class,
],
```

---

## Verification Command

Run this command to verify your schema is compatible:

```bash
php artisan larabill:verify-schema
```

(Command to be implemented)

---

## Changelog

### v2.0 (2026-01-01) - ADR-004

- BREAKING: `user_tax_profiles.user_id` renamed to `owner_user_id`
- NEW: `users.current_tax_profile_id` for shared profiles pattern
- Multiple users can now share the same tax profile

### v1.0 (2025-10-01) - Initial

- Initial schema with `user_id` foreign key pattern

---

## For AI Agents

When working with this codebase:

1. **Always read this file first** when dealing with migrations or user-related features
2. **Check the host application's migrations** against requirements listed here
3. **The `current_tax_profile_id` column is REQUIRED** in the users table for v2.x
4. **Never assume column names** - verify against this document
5. **ADR-004 changed the FK pattern** from direct `user_id` to `owner_user_id` + `current_tax_profile_id`

Key relationships:

```
users.id <-- user_tax_profiles.owner_user_id (who OWNS the profile)
users.current_tax_profile_id --> user_tax_profiles.id (which profile user USES)
```
