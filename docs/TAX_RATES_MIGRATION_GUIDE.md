# 🔄 Tax Rates System - Migration Guide

> **Version**: v0.3.4
> **Date**: 2025-01-13
> **Status**: ✅ **COMPLETED**

---

## 📋 Summary of Changes

We have **eliminated the duplicate `tax_rates` migration** and enhanced the unified structure with powerful new features while maintaining **100% backward compatibility**.

### What Changed

1. ✅ **Removed**: Duplicate migration `2024_12_01_000006_create_tax_rates_table.php`
2. ✅ **Enhanced**: Migration `2024_12_01_000000_create_tax_rates_table.php` with:
   - `softDeletes()` → Laravel-native soft deletion
   - `special_conditions` (JSON) → Metadata for complex tax rules
3. ✅ **Refactored**: `TaxRatesSeeder` to use consistent structure
4. ✅ **Updated**: Model, Factory, and all related components

---

## 🎯 For Package Users

### If You Haven't Published Migrations Yet

**Good news**: You don't need to do anything! Just install/update the package:

```bash
composer update aichadigital/larabill
php artisan vendor:publish --tag="larabill-migrations"
php artisan migrate
```

You'll get the enhanced structure automatically.

---

### If You Already Published Migrations (v0.3.3 or earlier)

#### Step 1: Check for Duplicates

```bash
ls database/migrations/ | grep tax_rates
```

**If you see TWO files**:
```
2024_12_01_000000_create_tax_rates_table.php
2024_12_01_000006_create_tax_rates_table.php  ← Delete this one
```

#### Step 2: Delete the Duplicate

```bash
rm database/migrations/2024_12_01_000006_create_tax_rates_table.php
```

#### Step 3: Update Your Migration (000000)

If you want the new features (`softDeletes` and `special_conditions`), update your existing migration:

```php
// database/migrations/2024_12_01_000000_create_tax_rates_table.php

Schema::create('tax_rates', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->integer('rate');
    $table->string('region')->nullable();
    $table->enum('type', ['vat', 'sales_tax', 'gst', 'other'])->default('vat');

    // ✨ NEW FIELDS
    $table->json('special_conditions')->nullable();
    $table->timestamps();
    $table->softDeletes();  // ← Laravel SoftDeletes

    // Indexes
    $table->index(['region', 'type']);
    $table->index(['deleted_at']);
});
```

#### Step 4: Migrate

**For Development** (no production data):
```bash
php artisan migrate:fresh --seed
```

**For Production** (has existing data):
```bash
# Create a new migration to ADD the new columns
php artisan make:migration add_soft_deletes_to_tax_rates_table

# In the new migration:
public function up(): void
{
    Schema::table('tax_rates', function (Blueprint $table) {
        $table->json('special_conditions')->nullable()->after('type');
        $table->softDeletes();
        $table->index(['deleted_at']);
    });
}

# Run it
php artisan migrate
```

---

## 💡 New Features Explained

### 1. SoftDeletes (Laravel Way)

**Before** (if you had `is_active`):
```php
// Deactivate
$taxRate->update(['is_active' => false]);

// Query active only
TaxRate::where('is_active', true)->get();
```

**Now** (with `softDeletes`):
```php
// "Delete" (soft)
$taxRate->delete();

// Query active (non-deleted) → AUTOMATIC
TaxRate::all();  // Only non-deleted

// Query all (including deleted)
TaxRate::withTrashed()->get();

// Restore
$taxRate->restore();

// Permanently delete
$taxRate->forceDelete();
```

**Benefits**:
- ✅ Laravel native
- ✅ Automatic filtering in queries
- ✅ Easy recovery
- ✅ Audit trail

---

### 2. Special Conditions (JSON Metadata)

Perfect for complex tax rules like Spanish special territories:

#### Example: Canary Islands (IGIC)

```php
TaxRate::create([
    'name' => 'IGIC General Canarias',
    'rate' => 700,  // 7%
    'region' => 'IC',
    'type' => TaxType::VAT,
    'special_conditions' => [
        'exempt_from_spanish_vat' => true,
        'territory_type' => 'special_territory',
        'note' => 'IGIC applies instead of Spanish IVA',
        'applies_to' => 'general_goods_services',
    ],
]);
```

#### Example: Ceuta/Melilla (IPSI)

```php
TaxRate::create([
    'name' => 'IPSI Ceuta',
    'rate' => 0,
    'region' => 'CE',
    'type' => TaxType::OTHER,
    'special_conditions' => [
        'exempt_from_spanish_vat' => true,
        'territory_type' => 'special_territory',
        'note' => 'Digital services exempt in Ceuta',
        'applies_to' => 'digital_services',
    ],
]);
```

#### Query by Special Conditions

```php
// Find all special territories
$specialTerritories = TaxRate::whereNotNull('special_conditions')
    ->get()
    ->filter(fn($rate) =>
        $rate->special_conditions['territory_type'] === 'special_territory'
    );

// Check if rate is for special territory
if ($taxRate->special_conditions['exempt_from_spanish_vat'] ?? false) {
    // Handle special case
}
```

---

## 🇪🇸 Spanish Special Territories Support

The enhanced system now perfectly handles:

| Territory | Code | Tax | Rate | Type |
|-----------|------|-----|------|------|
| Spain (Peninsula) | ES | IVA | 21% / 10% / 4% | VAT |
| Canary Islands | IC | IGIC | 7% / 3% | VAT |
| Ceuta | CE | IPSI | 0% (digital services) | OTHER |
| Melilla | ML | IPSI | 0% (digital services) | OTHER |

### Example Usage

```php
// Detect customer region and apply correct tax
$customerRegion = $customer->postal_code_prefix; // "35" (Las Palmas, Canarias)

if (in_array($customerRegion, ['35', '38'])) {
    // Canary Islands → Use IGIC
    $taxRate = TaxRate::where('region', 'IC')->where('rate', 700)->first();
} elseif (in_array($customerRegion, ['51', '52'])) {
    // Ceuta/Melilla → Use IPSI
    $taxRate = TaxRate::where('region', 'CE')->first();
} else {
    // Peninsular Spain → Use IVA
    $taxRate = TaxRate::where('region', 'ES')->where('rate', 2100)->first();
}
```

---

## 🔄 Backward Compatibility

### Existing Code Still Works

All existing code continues to work **without any changes**:

```php
// ✅ All these still work exactly as before
TaxRate::create([
    'name' => 'IVA General',
    'rate' => 2100,
    'region' => 'ES',
    'type' => TaxType::VAT,
]);

$taxRate = TaxRate::find(1);
$taxRate->update(['rate' => 2150]);
$taxGroups = $taxRate->taxGroups;
```

### Optional New Features

You can start using new features gradually:

```php
// Use special_conditions when needed
$taxRate->update([
    'special_conditions' => [
        'applies_to' => 'digital_services',
        'note' => 'Special rate for SaaS',
    ],
]);

// Use soft deletes when needed
$taxRate->delete();  // Soft delete
$taxRate->restore(); // Restore
```

---

## 📊 Benefits Summary

| Feature | Before | After | Benefit |
|---------|--------|-------|---------|
| **Deactivation** | Custom `is_active` field | Laravel `softDeletes()` | Native, automatic filtering |
| **Metadata** | Not available | `special_conditions` JSON | Complex rules support |
| **Territories** | Basic region codes | Enhanced with special_conditions | Spain special territories |
| **Migrations** | 2 conflicting | 1 unified | No conflicts, clearer |
| **Tests** | 842 passing | **856 passing** | More coverage |

---

## 🚀 Quick Start Examples

### Basic Tax Rate

```php
TaxRate::create([
    'name' => 'IVA General España',
    'rate' => 2100,
    'region' => 'ES',
    'type' => TaxType::VAT,
]);
```

### Tax Rate with Special Conditions

```php
TaxRate::create([
    'name' => 'IGIC Canarias',
    'rate' => 700,
    'region' => 'IC',
    'type' => TaxType::VAT,
    'special_conditions' => [
        'exempt_from_spanish_vat' => true,
        'territory_type' => 'special_territory',
    ],
]);
```

### Soft Delete Usage

```php
// Deactivate
$taxRate->delete();

// List only active
$activeTaxRates = TaxRate::all();

// List all (including inactive)
$allTaxRates = TaxRate::withTrashed()->get();

// Restore
$taxRate->restore();
```

---

## 🆘 Troubleshooting

### "Column 'special_conditions' not found"

**Solution**: Run the migration to add the new column:

```php
// Create migration:
php artisan make:migration add_special_conditions_to_tax_rates

// Add in up():
Schema::table('tax_rates', function (Blueprint $table) {
    $table->json('special_conditions')->nullable()->after('type');
    $table->softDeletes();
    $table->index(['deleted_at']);
});

// Run:
php artisan migrate
```

### "Table 'tax_rates' already exists"

**Solution**: You have the duplicate migration. Delete it:

```bash
rm database/migrations/*000006_create_tax_rates_table.php
```

### Tests Failing After Update

**Solution**: Refresh test database:

```bash
# In tests/database/migrations/, update your tax_rates migration
# Then:
php artisan test --env=testing
```

---

## 📞 Need Help?

- 📖 **Full Analysis**: See `docs/TAX_SYSTEM_ANALYSIS_AND_RECOMMENDATIONS.md`
- 🐛 **Issues**: https://github.com/aichadigital/larabill/issues
- 📧 **Contact**: support@aichadigital.es

---

**Last Updated**: 2025-01-13
**Version**: v0.3.4
**Status**: ✅ Production Ready
