# Larabill - Package Context for AI Agents

> **Read this file first** to understand the package's purpose, architecture, and conventions.

## 🎯 Package Identity

**Larabill** is the **core billing package** for the Larafactu ecosystem. It provides:

- Invoice management with immutability
- Tax calculation (Spain, EU, worldwide)
- VAT verification services
- Fiscal data management (company and customer)
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

## 🏗️ Architecture

### UUID Strategy - AGNOSTIC

**CRITICAL**: This package is **agnostic** regarding ID types. It supports:

| Type | Config Value | Storage | Size | Use Case |
|------|-------------|---------|------|----------|
| Integer | `int` | `unsignedBigInteger` | 8 bytes | Standard Laravel |
| UUID String | `uuid` | `char(36)` | 36 bytes | Human readable |
| UUID Binary | `uuid_binary` | `binary(16)` + cast | 16 bytes | **Best performance** |
| ULID String | `ulid` | `char(26)` | 26 bytes | Sortable, readable |
| ULID Binary | `ulid_binary` | `binary(26)` | 26 bytes | Sortable, efficient |

**Configuration** (`config/larabill.php`):
```php
'user_id_type' => env('LARABILL_USER_ID_TYPE', 'uuid_binary'),
```

**For UUID Binary** (recommended for production):
- Requires `dyrynda/laravel-model-uuid` package (included as dependency)
- Uses `EfficientUuid` cast for automatic string↔binary conversion
- 55% storage savings vs string UUID
- Better index performance

**The `HasUuid` trait** automatically configures the model based on `user_id_type` config.

### UUID Binary + Filament 4 (CRITICAL)

When using `uuid_binary` with Filament, there are special considerations:

#### Select Fields with User Relations

```php
// ❌ WRONG - Query Builder pluck() does NOT apply model casts
Forms\Components\Select::make('user_id')
    ->options(fn () => User::pluck('name', 'id'))  // Returns binary!

// ✅ CORRECT - Load models FIRST, then pluck (casts are applied)
Forms\Components\Select::make('user_id')
    ->options(fn () => User::all()->pluck('name', 'id'))  // Returns UUID string
```

**Why?** `Model::pluck()` uses Query Builder directly, bypassing Eloquent casts.
`Model::all()->pluck()` loads models first, applying all casts including `EfficientUuid`.

#### Models with user_id Foreign Key

Use the `HasUserRelation` trait for models that have a `user_id` FK:

```php
use AichaDigital\Larabill\Concerns\HasUserRelation;

class CustomerFiscalData extends Model
{
    use HasUserRelation;  // Adds EfficientUuid cast + user() relationship
}
```

The trait automatically:
1. Adds `EfficientUuid` cast to `user_id` when `uuid_binary` is configured
2. Provides the `user()` BelongsTo relationship
3. Resolves the User model class from configuration

### Monetary Values - Base 100

**NEVER use float/decimal for money**. Always integers in base 100:

- €12.34 → `1234`
- 21.5% IVA → `2150`
- €0.99 → `99`

Package: `aichadigital/lara100` (v1.0 stable)

### Fiscal Architecture (ADR-001)

```
CompanyFiscalConfig    → Company fiscal settings (temporal validity)
CustomerFiscalData     → Customer fiscal data (historical)
Invoice                → Invoice (immutable once issued)
```

**Key principles**:
- Company fiscal config has temporal validity (`valid_from`, `valid_until`)
- Customer fiscal data is historical (changes apply forward, never backward)
- Invoices capture fiscal snapshot at creation time
- Invoices are **absolutely immutable** once issued

## 📁 Package Structure

```
larabill/
├── config/larabill.php         # Package configuration
├── database/
│   ├── migrations/             # Database migrations
│   └── seeders/                # Tax categories, rates, etc.
├── docs/
│   ├── AGENT_CONTEXT.md        # This file
│   └── ARCHITECTURE.md         # Core architecture documentation
├── resources/
│   ├── lang/                   # Translations (es, en)
│   └── views/pdf/              # Invoice PDF templates
├── src/
│   ├── Concerns/
│   │   └── HasUuid.php         # Agnostic UUID trait
│   ├── Console/                # Artisan commands
│   ├── Contracts/              # Interfaces
│   ├── Database/Factories/     # Model factories
│   ├── DataTransferObjects/    # DTOs
│   ├── Enums/                  # Status enums
│   ├── Events/                 # Domain events
│   ├── Filament/               # Filament 4 resources
│   ├── Listeners/              # Event listeners
│   ├── Models/                 # Eloquent models
│   ├── Services/               # Business logic services
│   └── Support/
│       └── MigrationHelper.php # Agnostic migration support
└── tests/                      # Pest tests
```

## 🔧 Key Components

### HasUuid Trait

Agnostic UUID trait that adapts to configuration:

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

- **Invoice**: UUID primary key, immutable once issued
- **CompanyFiscalConfig**: Company fiscal settings with temporal validity
- **CustomerFiscalData**: Customer fiscal data (historical)
- **InvoiceItem**: Line items with tax breakdown

## ⚙️ Configuration

### Environment Variables

```env
# ID Type (critical for storage strategy)
LARABILL_USER_ID_TYPE=uuid_binary  # Options: int, uuid, uuid_binary, ulid, ulid_binary

# Invoice Numbering
LARABILL_INVOICE_PREFIX=FAC
LARABILL_PROFORMA_PREFIX=PRO

# VAT APIs
LARABILL_ABSTRACTAPI_KEY=your_key
LARABILL_APILAYER_KEY=your_key
```

## 🧪 Testing

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
// ✅ CORRECT - Agnostic pattern (uses configured model dynamically)
it('belongs to user', function () {
    $user = User::factory()->create();
    $data = Model::factory()->forUser($user->id)->create();

    $userModel = config('larabill.user_model');
    expect($data->user)->toBeInstanceOf($userModel);
});

// ❌ WRONG - Hardcoded class breaks agnosticism
expect($data->user)->toBeInstanceOf(User::class);
```

**Reference tests that follow this pattern**:

- `tests/Unit/Models/InvoiceSeriesControlTest.php:52-55`
- `tests/Unit/Models/ArticleServiceStatusTest.php`
- `tests/Unit/Models/ArticleOverrideTest.php`

## ⚠️ Important Conventions

### Filament 4 Compatibility

```php
protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
```

#### Filament 4 API Changes (vs Filament 3)

```php
// Table Actions - CHANGED
->actions([...])     // ❌ Filament 3
->recordActions([...])  // ✅ Filament 4

// Bulk Actions - CHANGED
->bulkActions([...])    // ❌ Filament 3
->toolbarActions([      // ✅ Filament 4
    BulkActionGroup::make([...])
])

// Date columns with nullable values
->default('Text')       // ❌ Tries to parse as date
->placeholder('Text')   // ✅ Shows text when null
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

### User ID Agnosticism

The package supports any user ID type. Configure via `larabill.user_id_type`:
- `int` for standard Laravel
- `uuid_binary` for production with high volume (recommended)
- `uuid` for human-readable UUIDs

## 🚫 Anti-Patterns

**DON'T**:
- ❌ Use float/decimal for monetary values (use base100)
- ❌ Modify issued invoices
- ❌ Hardcode UUID type (use config)
- ❌ Skip MigrationHelper for user_id columns

**DO**:
- ✅ Use `HasUuid` trait for UUID models
- ✅ Use `MigrationHelper::userIdColumn()` in migrations
- ✅ Use Base100Int for all monetary/percentage values
- ✅ Follow temporal validity pattern for fiscal data

## 📚 Key Documentation

| File | Purpose |
|------|---------|
| `docs/ARCHITECTURE.md` | Core architecture (articles, invoicing) |
| `CHANGELOG.md` | Version history and breaking changes |
| `README.md` | Installation and usage guide |

## 🎯 Target Use Case

**Primary**: Spanish hosting companies operating as EU intra-community operators

**Larafactu Configuration** (staging environment):
- Uses `uuid_binary` for best performance
- Configured for Spain + EU compliance
- VeriFACTU integration enabled

---

**Remember**: This package is **agnostic** - it adapts to the consuming application's ID strategy. Larafactu uses UUID v7 binary, but other projects may use different types. Always use the configuration, never hardcode.
