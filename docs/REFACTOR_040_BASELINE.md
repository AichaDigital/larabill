# Refactor v0.4.0 - Baseline de Tests

**Fecha**: 2025-11-16
**Estado**: ✅ TODOS LOS TESTS PASAN

## 📊 Estado Inicial

```
Tests:    856 passed (2648 assertions)
Duration: 349.61s
```

## 🎯 Objetivo

Documentar el estado **100% funcional** del paquete antes de iniciar el refactor arquitectónico v0.4.0.

## 📦 Componentes Críticos Actuales

### Modelos (21)

- ✅ Invoice (UUID binario, immutable, snapshots)
- ✅ InvoiceItem
- ✅ UserTaxProfile (actualmente acoplado a User)
- ✅ Article / ArticleOverride / ArticleServiceStatus
- ✅ TaxRate / TaxGroup / TaxCategory
- ✅ VatVerification / UserRoiVerification
- ✅ CompanyConfig (singleton fiscal)
- ✅ EuSalesThreshold / CountryVatRate

### Servicios (17)

- ✅ InvoiceNumberingService (correlativo fiscal)
- ✅ TaxCalculationService (estrategia VAT)
- ✅ VatVerificationService (fallback APIs)
- ✅ RoiVerificationService (LaraRoi integration)
- ✅ RecurringBillingService
- ✅ PricingService
- ✅ PDF/PDFService

### Migraciones (17)

- ✅ `create_invoices_table.php` (UUID binario, relaciones complejas)
- ✅ `create_user_tax_infos_table.php` (**será reemplazada por CustomerTaxProfile**)
- ✅ `create_tax_rates_table.php`
- ✅ `create_company_fiscal_configs_table.php`
- ✅ Otras 13 migraciones funcionando

## 🚨 Áreas de Alto Riesgo para Refactor

### 1. Invoice Model

**Relaciones existentes**:

```php
public function user(): BelongsTo
public function taxProfile(): BelongsTo // → UserTaxProfile (CAMBIAR)
public function items(): HasMany
```

**Cambios necesarios**:

- ✅ Añadir `customer_id` → Customer
- ✅ Añadir `fiscal_verification_id`, `fiscal_verification_qr`, `fiscal_verification_hash`
- ✅ Encriptar snapshots: `issuer_snapshot`, `customer_snapshot`, `fiscal_snapshot`
- ⚠️ Eliminar `tax_profile_id` → UserTaxProfile (deprecated)

### 2. UserTaxProfile → CustomerTaxProfile

**Estado actual**: 146 líneas, usado en 3+ servicios

**Cambios necesarios**:

- 🔄 Renombrar tabla: `user_tax_profiles` → `customer_tax_profiles`
- 🔄 Cambiar relación: `user_id` → `customer_id`
- 🔄 Actualizar factories, seeders, tests

### 3. CompanyConfig → IssuerConfig

**Estado actual**: Singleton con fiscal settings

**Cambios necesarios**:

- 🔄 Renombrar a `IssuerConfig`
- ✅ Añadir `current_tax_profile_id` → `IssuerTaxProfile`
- ✅ Mantener singleton pattern

## ✅ Tests Críticos a Preservar

### Invoice Tests (50+)

- ✓ Immutability enforcement
- ✓ UUID binary storage
- ✓ Correlative numbering
- ✓ Proforma conversion
- ✓ Rectificative invoices

### Tax Calculation Tests (40+)

- ✓ VAT calculation strategy
- ✓ ROI reverse charge
- ✓ EU threshold monitoring
- ✓ Destination VAT

### Integration Tests (7)

- ✓ Complete ROI verification workflow
- ✓ Complete destination VAT workflow
- ✓ EU sales threshold monitoring workflow

## 📝 Estrategia de Refactor

1. **Fase 1**: Crear nuevas entidades (IssuerConfig, Customer, TaxProfiles nuevos) **SIN TOCAR** las existentes
2. **Fase 2**: Ejecutar tests → **DEBEN SEGUIR PASANDO**
3. **Fase 3**: Migrar servicios uno a uno, ejecutando tests después de cada uno
4. **Fase 4**: Deprecar código antiguo cuando todos los tests pasen con el nuevo
5. **Fase 5**: Eliminar código obsoleto

## 🎯 Criterio de Éxito

**Al final del refactor**:

```bash
php artisan test --no-coverage
# Tests: 900+ passed (debe aumentar, no disminuir)
# Duration: ~350s (similar o mejor)
# Exit code: 0
```

## 📌 Notas Importantes

- ✅ Paquete en **alfa** (no en producción)
- ✅ Libertad total para refactorizar
- ⚠️ Pero tests actuales son **referencia de comportamiento esperado**
- ⚠️ Cualquier test que falle debe ser **intencionado y documentado**

---

**Checkpoint guardado**: Este documento representa el estado **golden** del paquete.

