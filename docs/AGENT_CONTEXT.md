# Larabill - Package Context for AI Agents

> **Read this file first** to understand the package's purpose, architecture, and conventions.

## Package Identity

**Larabill** is the **core billing package** for the Larafactu ecosystem. It provides:

- Invoice management with immutability
- Tax calculation (Spain, EU, worldwide)
- VAT verification services
- Fiscal data management (issuer and recipients)
- PDF invoice generation
- Filament 4 admin resources

### Critical Information

| Item | Value |
|------|-------|
| **Version** | dev-main (targeting v1.0 for Dec 15, 2025) |
| **PHP** | ^8.3 |
| **Laravel** | ^11.0 \| ^12.0 |
| **Filament** | ^4.0 |
| **License** | AGPL-3.0-or-later |

### Ecosystem Context

Larabill is part of the **AichaDigital billing ecosystem**:

```
aichadigital/
├── larabill/        # Core billing (THIS PACKAGE)
├── lara100/         # Base-100 monetary calculations (v1.0 stable)
├── lararoi/         # EU VAT/ROI verification
├── lara-verifactu/  # Spain AEAT VeriFACTU integration
└── laratickets/     # Support tickets
```

**Primary staging environment**: [Larafactu](https://github.com/AichaDigital/larafactu)

## Architecture

### UUID Strategy - UUID v7 String

**IMPORTANT**: This package uses UUID v7 **STRING** storage (char 36).

> **Note**: Binary UUID (`uuid_binary`) was removed in ADR-002 due to incompatibility with Filament 4.

| Type | Config Value | Storage | Size | Use Case |
|------|-------------|---------|------|----------|
| Integer | `int` | `unsignedBigInteger` | 8 bytes | Standard Laravel |
| UUID String | `uuid` | `char(36)` | 36 bytes | **Recommended** - Human readable, Filament compatible |
| ULID String | `ulid` | `char(26)` | 26 bytes | Sortable, readable |

**Configuration** (`config/larabill.php`):

```php
'user_id_type' => env('LARABILL_USER_ID_TYPE', 'uuid'),
```

**The `HasUuid` trait** automatically configures the model based on `user_id_type` config.

### Monetary Values - Base 100

**NEVER use float/decimal for money**. Always integers in base 100:

- 12.34 EUR → `1234`
- 21.5% IVA → `2150`
- 0.99 EUR → `99`

Package: `aichadigital/lara100` (v1.0 stable)

### Fiscal Architecture (ADR-001 + ADR-003)

```
ISSUER (single):
  CompanyFiscalConfig    → Issuer fiscal settings (temporal validity)

RECIPIENTS (unified under Users):
  Users                  → All billable entities (direct + delegated)
    ├── parent_user_id   → Self-reference for delegation
    └── relationship_type → UserRelationshipType enum

  UserTaxProfile         → Recipient fiscal data (historical per User)

INVOICES:
  Invoice                → Immutable once issued
    ├── user_id          → Owner/requester (NOT issuer)
    ├── company_fiscal_config_id → Issuer snapshot
    └── user_tax_profile_id → Recipient fiscal snapshot
```

**Key principles**:

- **Issuer**: `CompanyFiscalConfig` with temporal validity (`valid_from`, `valid_until`)
- **Recipients**: Unified under `Users` with self-referencing (`parent_user_id`)
- **Fiscal history**: `UserTaxProfile` tracks changes over time per User
- **Invoices**: Capture fiscal snapshots at creation, absolutely immutable once issued

### User Relationship Model (ADR-003)

```
┌─────────────────────────────────────────────────────────────────┐
│  users                                                          │
│  ══════                                                         │
│  - id (UUID v7 string)                                          │
│  - parent_user_id (nullable) → FK self-reference                │
│  - relationship_type (UserRelationshipType enum)                │
│                                                                 │
│  parent_user_id = NULL   → DIRECT (client of the Company)       │
│  parent_user_id = X      → DELEGATED (client of User X)         │
└─────────────────────────────────────────────────────────────────┘
```

**UserRelationshipType Enum**:

```php
enum UserRelationshipType: int implements HasLabel, HasColor, HasIcon
{
    case DIRECT = 0;      // Direct client of the Company
    case DELEGATED = 1;   // Client of a User (delegated billing)
}
```

### Article Pricing by Frequency (ADR-004)

**IMPORTANT**: Pricing is separated from Article into `ArticlePrice` model.

```
┌─────────────────────────────────────────────────────────────────┐
│  Article (Catálogo)                                             │
│  ═══════════════════                                            │
│  - id, code, name, item_type                                    │
│  - cost_price (Base100Int)                                      │
│                                                                 │
│  └─→ ArticlePrice[] (1:N)                                       │
│      ├── billing_frequency: BillingFrequency enum               │
│      ├── price: Base100Int                                      │
│      ├── billing_days_in_advance: ?int                          │
│      └── valid_from / valid_to: temporal validity               │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  ArticleServiceStatus (Contracted Instance)                     │
│  ══════════════════════════════════════════                     │
│  - customer_id, article_id                                      │
│  - billing_frequency: BillingFrequency (contract immutability)  │
│  - effective_price: Base100Int (cached at contract)             │
│  - next_billing_date, instance_identifier                       │
└─────────────────────────────────────────────────────────────────┘
```

**BillingFrequency Enum**:

```php
enum BillingFrequency: int
{
    case ONE_TIME = 0;      // Single purchase
    case WEEKLY = 1;        // Every week
    case BIWEEKLY = 2;      // Every 2 weeks
    case MONTHLY = 3;       // Every month
    case BIMONTHLY = 4;     // Every 2 months
    case QUARTERLY = 5;     // Every 3 months
    case SEMIANNUALLY = 6;  // Every 6 months
    case YEARLY = 7;        // Every year
}
```

**Key Methods on Article**:

```php
$article->getPriceFor(BillingFrequency::MONTHLY);      // Get price for frequency
$article->getAvailableFrequencies();                    // Get all available frequencies
$article->isRecurring();                                // Has non-ONE_TIME prices?
$article->getBillingDaysInAdvanceFor($frequency);       // Days in advance for billing
$article->getEffectivePriceFor($customerId, $freq);     // Price with override applied
```

## Package Structure

```
larabill/
├── config/larabill.php         # Package configuration
├── database/
│   ├── migrations/             # Database migrations + stubs
│   └── seeders/                # Tax categories, rates, etc.
├── docs/
│   ├── AGENT_CONTEXT.md        # This file
│   ├── ARCHITECTURE.md         # Core architecture (articles, invoicing)
│   ├── ADR-001-*.md            # Fiscal architecture decision
│   ├── ADR-002-*.md            # UUID v7 string decision
│   ├── ADR-003-*.md            # User/Customer unification decision
│   └── ADR-004-*.md            # Article pricing by frequency
├── resources/
│   ├── lang/                   # Translations (es, en)
│   └── views/pdf/              # Invoice PDF templates
├── src/
│   ├── Concerns/
│   │   ├── HasUuid.php         # UUID trait
│   │   └── HasUserRelation.php # User relationship trait
│   ├── Console/                # Artisan commands
│   ├── Contracts/              # Interfaces
│   ├── Database/Factories/     # Model factories
│   ├── DataTransferObjects/    # DTOs
│   ├── Enums/                  # Status enums + UserRelationshipType
│   ├── Events/                 # Domain events
│   ├── Filament/               # Filament 4 resources
│   ├── Listeners/              # Event listeners
│   ├── Models/                 # Eloquent models
│   ├── Services/               # Business logic services
│   └── Support/
│       └── MigrationHelper.php # Agnostic migration support
└── tests/                      # Pest tests
```

## Key Components

### HasUuid Trait

UUID trait that adapts to configuration:

```php
use AichaDigital\Larabill\Concerns\HasUuid;

class Invoice extends Model
{
    use HasUuid;
    // Automatically configures based on larabill.user_id_type
}
```

### MigrationHelper

Creates columns with correct type based on configuration:

```php
use AichaDigital\Larabill\Support\MigrationHelper;

Schema::create('invoices', function (Blueprint $table) {
    $table->uuid('id')->primary();
    MigrationHelper::userIdColumn($table); // Adapts to user ID type
});
```

### Key Models

| Model | Purpose |
|-------|---------|
| **Invoice** | UUID primary key, immutable once issued |
| **CompanyFiscalConfig** | Issuer fiscal settings with temporal validity |
| **UserTaxProfile** | Recipient fiscal data (historical per User) |
| **InvoiceItem** | Line items with tax breakdown |
| **Article** | Catalog item (product or service) |
| **ArticlePrice** | Frequency-based pricing for Article (ADR-004) |
| **ArticleServiceStatus** | Contracted service instance with billing schedule |
| **ArticleOverride** | Customer-specific price override |

### Deprecated Models (ADR-003)

| Model | Status | Replacement |
|-------|--------|-------------|
| `Customer` | **DEPRECATED** | Use `User` with `parent_user_id` |
| `CustomerFiscalData` | **DEPRECATED** | Use `UserTaxProfile` |

## Configuration

### Environment Variables

```env
# ID Type
LARABILL_USER_ID_TYPE=uuid  # Options: int, uuid, ulid

# Invoice Numbering
LARABILL_INVOICE_PREFIX=FAC
LARABILL_PROFORMA_PREFIX=PRO

# VAT APIs
LARABILL_ABSTRACTAPI_KEY=your_key
LARABILL_APILAYER_KEY=your_key
```

## Testing

```bash
# Run all tests
composer test

# Run specific tests
composer test -- --filter=Invoice

# Static analysis
vendor/bin/phpstan analyse
```

### Testing Patterns - User Model Agnosticism

**CRITICAL**: This package is agnostic to the host application's User model.

The `user_model` is configurable via `config('larabill.user_model')`. In tests:

- `TestCase.php` sets `larabill.user_model` to `TestUser::class`
- The `HasUserRelation` trait reads this config dynamically
- Models use `tests/Models/User.php` for creating test users (with factory)
- But relationships return whatever class is configured in `larabill.user_model`

**Pattern for testing user relationships**:

```php
// CORRECT - Agnostic pattern (uses configured model dynamically)
it('belongs to user', function () {
    $user = User::factory()->create();
    $data = Model::factory()->forUser($user->id)->create();

    $userModel = config('larabill.user_model');
    expect($data->user)->toBeInstanceOf($userModel);
});

// WRONG - Hardcoded class breaks agnosticism
expect($data->user)->toBeInstanceOf(User::class);
```

## Important Conventions

### Filament 4 Compatibility

```php
protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
```

#### Filament 4 API Changes (vs Filament 3)

```php
// Table Actions - CHANGED
->actions([...])        // Filament 3
->recordActions([...])  // Filament 4

// Bulk Actions - CHANGED
->bulkActions([...])    // Filament 3
->toolbarActions([      // Filament 4
    BulkActionGroup::make([...])
])

// Date columns with nullable values
->default('Text')       // Tries to parse as date
->placeholder('Text')   // Shows text when null
```

#### Action Imports

```php
// Filament 4 - Use Filament\Actions namespace
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
```

### PHP Enums with Filament

All enums implement Filament interfaces for seamless integration:

```php
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum UserRelationshipType: int implements HasLabel, HasColor, HasIcon
{
    case DIRECT = 0;
    case DELEGATED = 1;

    public function getLabel(): string { /* ... */ }
    public function getColor(): string { /* ... */ }
    public function getIcon(): string { /* ... */ }
}
```

See: [Filament Enums Documentation](https://filamentphp.com/docs/4.x/advanced/enums)

## Anti-Patterns

**DON'T**:

- Use float/decimal for monetary values (use base100)
- Modify issued invoices
- Hardcode UUID type (use config)
- Skip MigrationHelper for user_id columns
- Use binary UUID (incompatible with Filament 4)
- Create separate Customer entities (use User with parent_user_id)

**DO**:

- Use `HasUuid` trait for UUID models
- Use `MigrationHelper::userIdColumn()` in migrations
- Use Base100Int for all monetary/percentage values
- Follow temporal validity pattern for fiscal data
- Use PHP Enums with Filament interfaces
- Implement self-referencing Users for delegation

## Key Documentation

| File | Purpose |
|------|---------|
| `docs/ARCHITECTURE.md` | Core architecture (articles, invoicing) |
| `docs/ADR-001-*.md` | Fiscal architecture decision |
| `docs/ADR-002-*.md` | UUID v7 string decision |
| `docs/ADR-003-*.md` | User/Customer unification |
| `docs/ADR-004-*.md` | Article pricing by frequency |
| `CHANGELOG.md` | Version history and breaking changes |
| `README.md` | Installation and usage guide |

## Target Use Case

**Primary**: Spanish hosting companies operating as EU intra-community operators

**Features prioritized for**:

- Monthly/annual service billing
- Spanish tax system (IVA, IGIC, IPSI)
- EU reverse charge (B2B)
- VeriFACTU compliance (Spain)
- WHMCS migration path

---

**Remember**: This package uses UUID v7 **string** (not binary). Always use the configuration, never hardcode ID types.
