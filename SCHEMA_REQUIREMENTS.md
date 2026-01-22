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

These tables are created by larabill migrations. In development environments,
migrations are loaded automatically. In production, use `php artisan larabill:install`.

### Core Tables

#### `user_tax_profiles` (ADR-004)

Stores fiscal configuration for users with temporal validity.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `owner_user_id` | uuid/bigint | The user who OWNS this profile (FK to users.id) |
| `fiscal_name` | string | Legal name for invoices |
| `tax_id` | string | NIF/CIF/VAT number |
| `legal_entity_type_code` | string | FK to legal_entity_types.code |
| `address` | string | Fiscal address |
| `city` | string | City |
| `state` | string | Province/State |
| `zip_code` | string | Postal code |
| `country_code` | char(2) | ISO 3166-1 alpha-2, default 'ES' |
| `is_company` | boolean | Company (true) vs Individual (false) |
| `is_eu_vat_registered` | boolean | EU VAT registration (intra-community) |
| `is_exempt_vat` | boolean | VAT exempt flag |
| `valid_from` | date | Start of validity period |
| `valid_until` | date/null | End of validity (null = current) |
| `is_active` | boolean | Whether profile is active |
| `notes` | text | Notes about fiscal changes |

**Key Concepts**:

- `owner_user_id`: The user who created/owns this fiscal profile
- `current_tax_profile_id` (in users): Which profile a user is currently using
- Multiple users can point to the same profile via `current_tax_profile_id`
- Only one active profile per owner with `valid_until = null`

#### `invoices`

Main invoice table with UUID primary key and fiscal numbering.

| Column | Type | Description |
|--------|------|-------------|
| `id` | uuid | UUID v7 primary key |
| `fiscal_number` | string | Complete fiscal number (FAC-2025-000047) |
| `prefix` | string(10) | Customizable prefix (FAC, PRO, RECT) |
| `serie` | unsignedTinyInteger | InvoiceSerieType enum: 0=proforma, 1=invoice, 2=rectificative |
| `series_number` | bigint | Correlative number for fiscal validation |
| `fiscal_year` | year | Fiscal year |
| `user_id` | uuid/bigint | Owner user FK |
| `billable_user_id` | uuid | Customer being billed (replaces customer_id per ADR-003) |
| `company_fiscal_config_id` | bigint | Issuer config FK |
| `user_tax_profile_id` | bigint | Customer fiscal snapshot FK |
| `invoice_date` | date | Legal invoice date |
| `issued_at` | timestamp | Immutable timestamp for chronological validation |
| `status` | unsignedTinyInteger | InvoiceStatus enum |
| `taxable_amount` | int | Base-100 storage |
| `total_tax_amount` | int | Base-100 storage |
| `total_amount` | int | Base-100 storage |
| `is_immutable` | boolean | Fiscal protection flag |

#### `company_fiscal_configs` (ADR-001)

Company/issuer fiscal configurations.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | uuid/bigint | Owner user FK |
| `fiscal_name` | string | Company fiscal name |
| `tax_id` | string | Company NIF/CIF |
| `is_global` | boolean | Global config vs user-specific |
| `is_active` | boolean | Active flag |

### Reference Tables

| Table | Description |
|-------|-------------|
| `tax_rates` | Tax rate definitions (VAT 21%, 10%, 4%, 0%) |
| `tax_groups` | Tax group definitions |
| `tax_group_tax_rate` | Pivot for tax groups and rates |
| `tax_categories` | Tax category classifications |
| `unit_measures` | Unit of measure definitions |
| `legal_entity_types` | Legal entity type codes |

### Article/Product Tables

| Table | Description |
|-------|-------------|
| `articles` | Product/service catalog |
| `article_prices` | Price tiers per article |
| `article_overrides` | User-specific article overrides |
| `article_service_status` | Service status tracking |

### Supporting Tables

| Table | Description |
|-------|-------------|
| `invoice_items` | Line items for invoices |
| `invoice_series_control` | Sequence control per serie/year |
| `invoice_templates` | PDF template definitions |
| `company_template_settings` | Company-specific template configs |
| `vat_verifications` | EU VAT verification results (lararoi integration) |
| `commissions` | Commission tracking |

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

## User ID Type Support

Larabill supports three ID types via `MigrationHelper::getUserIdType()`:

- **uuid** (default): UUID v7 string (char 36) - Recommended
- **ulid**: ULID string (char 26)
- **int**: unsignedBigInteger - Standard Laravel default

The helper automatically creates columns with the correct type.

---

## For AI Agents

When working with this codebase:

1. **Always read this file first** when dealing with migrations or user-related features
2. **Check the host application's migrations** against requirements listed here
3. **The `current_tax_profile_id` column is REQUIRED** in the users table for v2.x
4. **Never assume column names** - verify against this document
5. **ADR-004 changed the FK pattern** from direct `user_id` to `owner_user_id` + `current_tax_profile_id`
6. **Use `MigrationHelper::userIdColumn()`** for user FK columns in package migrations

Key relationships:


```text
users.id <-- user_tax_profiles.owner_user_id (who OWNS the profile)
users.current_tax_profile_id --> user_tax_profiles.id (which profile user USES)
invoices.user_id --> users.id (invoice owner)
invoices.billable_user_id --> users.id (customer being billed)
```


---

## Last Updated

- Document version: 2.1
- Larabill version: dev-main
- Date: 2026-01-22
