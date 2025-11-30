# Análisis Completo: Enums y Tipos de Datos en Larabill

> **Fecha**: 2024-11-30
> **Estado**: En progreso - Análisis completo
> **Prioridad**: Alta - Bloquea migraciones
> **Decisión**: v1.0 = facturación manual, v1.1+ = suscripciones/renovaciones

---

## 🎯 Decisiones de Alcance

### v1.0 (15 diciembre 2025)
- Facturación manual/puntual
- Sin sistema de renovaciones automáticas
- Eliminar código muerto relacionado con suscripciones

### v1.1+ (Post-lanzamiento)
- Módulo completo de suscripciones/renovaciones
- Tablas: `subscriptions`, `subscription_items`, `billing_cycles`
- Jobs automáticos de generación de facturas

---

## 📊 ENUMs PHP Existentes en Larabill

| Enum | Backed Type | Valores | Estado | Acción |
|------|-------------|---------|--------|--------|
| `InvoiceSerieType` | `int` | 0=PROFORMA, 1=INVOICE, 2=RECTIFICATIVE | ✅ Usado | **Añadir SIMPLIFIED (tickets)** |
| `InvoiceStatus` | `int` | 0-6 (DRAFT, SENT, PAID, etc.) | ✅ Usado | Mantener |
| `ItemType` | `int` | 0=GOOD, 1=SERVICE | ✅ Usado | Mantener |
| `UnitMeasureCategory` | `int` | 0-5 (COUNT, WEIGHT, etc.) | ✅ Usado | Mantener |
| `TaxType` | `string` | vat, sales_tax, gst, other | ⚠️ MySQL enum | **Migrar a int-backed** |
| `BillingFrequency` | `string` | M, Q, Y, L | ❌ Sin uso | **ELIMINAR (v1.1)** |
| `CancellationType` | `string` | I, E, N | ❌ Sin uso | **ELIMINAR (v1.1)** |
| `ServiceStatus` | `string` | A, P, S, C, E | ❌ Sin uso | **ELIMINAR (v1.1)** |

---

## 🗑️ ENUMs a ELIMINAR (código muerto para v1.0)

| Archivo | Razón |
|---------|-------|
| `src/Enums/BillingFrequency.php` | Sin migraciones, sin modelos, sin tests - v1.1 |
| `src/Enums/CancellationType.php` | Sin migraciones, sin modelos, sin tests - v1.1 |
| `src/Enums/ServiceStatus.php` | Sin migraciones, sin modelos, sin tests - v1.1 |

---

## 🔧 InvoiceSerieType - Añadir SIMPLIFIED

**Actual**:
```php
enum InvoiceSerieType: int
{
    case PROFORMA      = 0;
    case INVOICE       = 1;
    case RECTIFICATIVE = 2;
}
```

**Propuesto**:
```php
enum InvoiceSerieType: int
{
    case PROFORMA      = 0;  // Sin valor fiscal, no se comunica
    case INVOICE       = 1;  // Factura completa/ordinaria
    case SIMPLIFIED    = 2;  // Factura simplificada (ticket)
    case RECTIFICATIVE = 3;  // Factura rectificativa
}
```

**Nota**: Cambiar RECTIFICATIVE de 2 a 3 requiere migración de datos si hay facturas existentes.

---

## 🔴 Campos con `enum()` MySQL (MIGRAR a PHP enum)

| Migración | Campo | Valores | Acción |
|-----------|-------|---------|--------|
| `tax_rates` | `type` | vat, sales_tax, gst, other | Cambiar a `unsignedTinyInteger` + `TaxType` enum int |
| `customers` | `relationship_type` | self, self_company, client, other | Crear `RelationshipType` enum int |
| `commissions` | `level` | global, product_group, product | Crear `CommissionLevel` enum int |
| `commissions` | `type` | percentage, fixed | Crear `CommissionType` enum int |
| `commissions` | `applies_to` | taxable_amount, total_amount | Crear `CommissionAppliesTo` enum int |

---

## 🟡 Campos `string` que DEBERÍAN ser ENUM PHP

| Migración | Campo | Tipo Actual | Propuesta |
|-----------|-------|-------------|-----------|
| `company_template_settings` | `setting_type` | varchar(255) | `SettingType` enum → tinyint |
| `company_template_settings` | `invoice_type` | varchar(255) | `TemplateInvoiceType` enum → tinyint |
| `company_template_settings` | `scope` | varchar(255) | `SettingScope` enum → tinyint |
| `invoice_templates` | `type` | varchar(255) | Mismo `TemplateInvoiceType` |

---

## 🔴 Campos ID como STRING (CRÍTICO)

| Migración | Campo | Tipo Actual | Acción |
|-----------|-------|-------------|--------|
| `company_template_settings` | `client_id` | varchar(255) | `MigrationHelper::nullableIdColumn()` |

---

## 🟠 Campos `string` con longitud excesiva

### Resumen por categoría:

| Categoría | Campos Afectados | Acción |
|-----------|------------------|--------|
| Nombres fiscales | business_name, legal_name, commercial_name | Reducir a 150 |
| Códigos fiscales | tax_code, tax_id, vat_code, vat_number | Reducir a 30 |
| Direcciones | address, city, state | Reducir a 150, 100, 100 |
| Códigos postales | postal_code, zip_code | Reducir a 20 |
| Teléfonos | phone | Reducir a 30 |
| Emails | email | Mantener 255 (estándar) |
| Códigos internos | internal_code, code | Reducir a 50 |
| Categorías | category | Reducir a 50 |
| Tipos/Estados | setting_type, invoice_type, scope | Cambiar a tinyint (enum) |

---

## ❓ Campos a REVISAR diseño

| Migración | Campo | Problema |
|-----------|-------|----------|
| `articles` | `subscription_type` | Mezcla concepto de suscripción con identificador externo. Sin lógica clara. |

**Decisión**: Mantener para v1.0, revisar en v1.1 cuando se implemente módulo de suscripciones.

---

## 📋 ENUMs PHP a CREAR

| Nombre | Backed Type | Valores | Para Campos |
|--------|-------------|---------|-------------|
| `SettingType` | int | 0=TEMPLATE, 1=NOTES, 2=PAYMENT_TERMS | `company_template_settings.setting_type` |
| `TemplateInvoiceType` | int | 0=FISCAL, 1=PROFORMA, 2=REVERSE_CHARGE, 3=EXEMPT | `company_template_settings.invoice_type`, `invoice_templates.type` |
| `SettingScope` | int | 0=GLOBAL, 1=CLIENT, 2=INDIVIDUAL | `company_template_settings.scope` |
| `CommissionLevel` | int | 0=GLOBAL, 1=PRODUCT_GROUP, 2=PRODUCT | `commissions.level` |
| `CommissionType` | int | 0=PERCENTAGE, 1=FIXED | `commissions.type` |
| `CommissionAppliesTo` | int | 0=TAXABLE_AMOUNT, 1=TOTAL_AMOUNT | `commissions.applies_to` |
| `RelationshipType` | int | 0=SELF, 1=SELF_COMPANY, 2=CLIENT, 3=OTHER | `customers.relationship_type` |

---

## 📋 ENUMs PHP a MIGRAR (string → int)

| Enum | Cambio |
|------|--------|
| `TaxType` | string → int: 0=VAT, 1=SALES_TAX, 2=GST, 3=OTHER |

---

## 🎯 Plan de Acción Priorizado

### Fase 1: Desbloquear Migraciones (URGENTE)

1. [ ] **Eliminar ENUMs sin uso**:
   - `BillingFrequency.php`
   - `CancellationType.php`
   - `ServiceStatus.php`

2. [ ] **Corregir `company_template_settings`**:
   - Crear ENUMs: `SettingType`, `TemplateInvoiceType`, `SettingScope`
   - Cambiar campos varchar a `unsignedTinyInteger`
   - Cambiar `client_id` a agnostic ID con `MigrationHelper`

3. [ ] **Reducir longitudes varchar** en migración problemática

### Fase 2: Eliminar MySQL enum() 

4. [ ] Crear ENUMs PHP: `RelationshipType`, `CommissionLevel`, `CommissionType`, `CommissionAppliesTo`
5. [ ] Migrar `tax_rates.type` de MySQL enum a tinyint
6. [ ] Migrar `customers.relationship_type`
7. [ ] Migrar `commissions.level`, `type`, `applies_to`

### Fase 3: Mejorar InvoiceSerieType

8. [ ] Añadir `SIMPLIFIED` a `InvoiceSerieType`
9. [ ] Migrar `TaxType` de string a int-backed

### Fase 4: Optimizar longitudes (puede ser post-v1.0)

10. [ ] Reducir varchar(255) a tamaños apropiados en todas las tablas

### Fase 5: Añadir MigrationHelper

11. [ ] Crear `MigrationHelper::nullableIdColumn()` para IDs opcionales agnostic

---

## 📝 Política de ENUMs (Para Rules)

### SIEMPRE usar ENUM PHP int-backed cuando:
- Valores son **fijos y conocidos**
- Cambios requieren **deployment de código**
- Son **pocos valores** (<20)

### SIEMPRE almacenar como `unsignedTinyInteger` en BD:
- 1 byte de espacio
- Índices rápidos
- Portable entre DBs

### NUNCA usar:
- ❌ `$table->enum()` de MySQL
- ❌ `varchar(255)` para valores enumerados
- ❌ ENUM PHP string-backed para BD
- ❌ `string` para campos `*_id`

### Usar Tabla Lookup cuando:
- Valores cambian en runtime
- Usuarios pueden añadir valores
- Necesitas metadatos (descripciones, traducciones)
- Muchos valores o crecen con el tiempo

---

## 📌 Resumen Ejecutivo

| Categoría | Cantidad | Acción |
|-----------|----------|--------|
| ENUMs a eliminar | 3 | `BillingFrequency`, `CancellationType`, `ServiceStatus` |
| ENUMs a crear | 7 | Settings, Commission, Relationship |
| ENUMs a migrar | 1 | `TaxType` string→int |
| MySQL enum a eliminar | 5 | En 3 tablas |
| Campos varchar a reducir | ~40 | Optimización |
| Campos ID como string | 1 | `client_id` |
| InvoiceSerieType | +1 | Añadir SIMPLIFIED |

**Bloqueante para migraciones**: `company_template_settings` (índice >3072 bytes)
