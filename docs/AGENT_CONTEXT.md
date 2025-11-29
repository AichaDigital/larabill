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
| **PHP** | ^8.3 (Laravel 12 standard) |
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

### UUID Strategy - String UUID v7

**IMPORTANT**: This package uses UUID v7 **STRING** (char 36), NOT binary.

```php
// Model with UUID
use AichaDigital\Larabill\Concerns\HasUuid;

class Invoice extends Model
{
    use HasUuid;
    // Trait handles $incrementing and $keyType automatically
}

// Migration
$table->uuid('id')->primary();
$table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
```

**UUID Models**: Invoice
**Integer Models**: TaxRate, TaxCategory, CompanyFiscalConfig, CustomerFiscalData, UnitMeasure

### Monetary Values - Base 100

**NEVER use float/decimal for money**. Always integers in base 100:

- €12.34 → `1234`
- 21.5% IVA → `2150`
- €0.99 → `99`

Use the `Base100Int` cast from `lara100` package.

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
├── .claude/                    # AI agent context (this file)
├── config/larabill.php         # Package configuration
├── database/
│   ├── migrations/             # Database migrations
│   └── seeders/                # Tax categories, rates, etc.
├── docs/
│   └── ARCHITECTURE.md         # Core architecture documentation
├── resources/
│   ├── lang/                   # Translations (es, en)
│   └── views/pdf/              # Invoice PDF templates
├── src/
│   ├── Actions/                # Queue actions (billing, cancellations)
│   ├── Concerns/               # Traits (HasUuid)
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
│   └── Support/                # Helpers (MigrationHelper)
└── tests/                      # Pest tests
```

## 🔧 Key Models

### Invoice
- UUID primary key
- Immutable once status is not `draft`
- Contains fiscal snapshot at creation
- Linked to user via `user_id` (configurable type)

### CompanyFiscalConfig
- Integer primary key
- Temporal validity (`valid_from`, `valid_until`)
- Company-wide fiscal settings (tax ID, address, OSS, etc.)
- Only one active config at a time (where `valid_until` is null)

### CustomerFiscalData
- Integer primary key
- Historical records per customer
- Changes apply forward from `valid_from`
- Never modify past records

### InvoiceItem
- Snapshot of article/service at invoice time
- Contains tax breakdown
- No foreign key to articles (immutable copy)

## ⚙️ Configuration

```php
// config/larabill.php
return [
    'models' => [
        'user' => \App\Models\User::class,
        'invoice' => \AichaDigital\Larabill\Models\Invoice::class,
        // ...
    ],
    'user_id_type' => 'uuid', // 'uuid', 'int', 'ulid'
    'invoice_prefix' => 'FAC',
    'proforma_prefix' => 'PRO',
];
```

## 🧪 Testing

```bash
# Run all tests
composer test

# Run specific tests
composer test -- --filter=Invoice

# Run with coverage
composer test-coverage

# Static analysis
vendor/bin/phpstan analyse
```

**Current status**: 866 tests passing, 34 skipped (external dependencies)

## ⚠️ Important Conventions

### Filament 4 Compatibility

```php
// Correct type for navigation icon
protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

// Use Schema for form method
public function form(Schema $schema): Schema
```

### User ID Agnosticism

The package supports any user ID type. Use `MigrationHelper`:

```php
use AichaDigital\Larabill\Support\MigrationHelper;

Schema::create('invoices', function (Blueprint $table) {
    $table->uuid('id')->primary();
    MigrationHelper::addUserIdColumn($table, 'user_id');
});
```

### Service Provider

Migrations are loaded automatically via `loadMigrationsFrom()`. No need to publish unless customization is required.

## 📝 Development Workflow

### When editing this package from Larafactu:

1. Edit in `packages/aichadigital/larabill/` (symlink)
2. Changes reflect immediately
3. Test with `vendor/bin/pest`
4. Format with `vendor/bin/pint`

### Committing changes:

```bash
# In the package directory
cd /path/to/larabill
git add -A && git commit -m "feat: ..." && git push

# Then in the consuming app
composer update aichadigital/larabill
```

## 🚫 Anti-Patterns

**DON'T**:
- ❌ Use float/decimal for monetary values
- ❌ Modify issued invoices
- ❌ Create FiscalSettings (deprecated, use CompanyFiscalConfig/CustomerFiscalData)
- ❌ Use binary UUIDs (we use string UUID v7)
- ❌ Over-engineer for hypothetical scenarios

**DO**:
- ✅ Use Base100Int for all monetary/percentage values
- ✅ Follow temporal validity pattern for fiscal data
- ✅ Test with factories
- ✅ Keep it simple and Laravel-aligned

## 📚 Key Documentation

| File | Purpose |
|------|---------|
| `docs/ARCHITECTURE.md` | Core architecture (articles, invoicing) |
| `CHANGELOG.md` | Version history and breaking changes |
| `README.md` | Installation and usage guide |

## 🎯 Target Use Case

**Primary**: Spanish hosting companies operating as EU intra-community operators

**Features prioritized for**:
- Monthly/annual service billing
- Spanish tax system (IVA, IGIC, IPSI)
- EU reverse charge (B2B)
- VeriFACTU compliance (Spain)
- WHMCS migration path

---

**Remember**: Pragmatism over perfection. Laravel conventions are the guide. This package must be stable by December 15, 2025.

