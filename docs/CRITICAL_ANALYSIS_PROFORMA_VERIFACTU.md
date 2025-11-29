# 🚨 ANÁLISIS CRÍTICO: Proforma vs Factura + Verifactu

**Fecha**: 2025-01-25
**Contexto**: Refactor v0.4.0 - Integración Verifactu España

---

## 🎯 Problema Identificado

Hay **DOS FLUJOS** de facturación con **IMPLICACIONES FISCALES DISTINTAS**:

### 📊 Flujo 1: Facturación Directa
```
Cliente solicita → Factura (serie=1) → Número secuencial fiscal → Verifactu INMEDIATO
```
- **Características**:
  - Factura directa con `serie=1` (INVOICE)
  - Número **SECUENCIAL FISCAL** desde el primer momento
  - **DEBE** enviarse a Verifactu **INMEDIATAMENTE**
  - Una vez enviada → **INMUTABLE** (no se puede cambiar)
  - Proforma NO existe en este flujo

### 📋 Flujo 2: Proforma → Factura (TU CASO)
```
Cliente solicita → Proforma (serie=0) → [Servicio entregado/pagado] → Factura (serie=1) → Verifactu
```
- **Características**:
  - Proforma con `serie=0` (PROFORMA)
  - Número **NO FISCAL** (PRO-2025-001, etc.)
  - **NO** se envía a Verifactu (no es factura fiscal)
  - Cuando se entrega/paga → Conversión a factura
  - Factura obtiene **NUEVO número secuencial fiscal**
  - **ENTONCES** se envía a Verifactu

---

## ⚠️ Problema Actual en el Código

### 🔴 Conversión Proforma → Factura (Línea 115-134)

```php
public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
{
    // ✅ Verifica que sea proforma
    if ($proforma->serie !== InvoiceSerieType::PROFORMA) {
        throw new \InvalidArgumentException('Only proforma invoices can be converted');
    }

    // ⚠️ PROBLEMA: Crea una NUEVA factura
    $invoiceData = [
        'user_id'          => $proforma->user_id,
        'type'             => 'invoice',  // Serie 1
        'status'           => 'draft',
        // ... copia datos de proforma
        'items'            => $this->getInvoiceItemsData($proforma),
    ];

    // 🚨 CRÍTICO: Llama a createInvoice() que genera NUEVO número secuencial
    return $this->createInvoice($invoiceData, $options);
}
```

**¿Qué ocurre?**:
1. ✅ La proforma (`PRO-2025-001`) existe con `serie=0`
2. ✅ Se convierte a factura → `createInvoice()`
3. ✅ La factura obtiene **NUEVO número fiscal** (`FAC-2025-047`, `serie=1`)
4. ❓ **¿Qué pasa con la proforma?**
   - ❌ **NO se marca como convertida**
   - ❌ **NO se enlaza con la factura**
   - ❌ **Queda "huérfana"**

---

## 🔍 Análisis del Modelo Invoice

### Campo `proforma_id` (existe pero NO se usa)

```php
// database/migrations/2024_12_01_000003_create_invoices_table.php:41
$table->foreignUuid('proforma_id')
    ->nullable()
    ->constrained('invoices')
    ->nullOnDelete()
    ->comment('UUID binary(16) if this invoice was converted from a proforma');
```

**Estado**:
- ✅ Campo existe en migración
- ✅ Relación en modelo: `$invoice->proforma()`
- ❌ **NUNCA se rellena** en `BillingService::convertToInvoice()`
- ❌ **Trazabilidad perdida**

---

## 🚨 Riesgos del Código Actual

### 1. **Pérdida de Trazabilidad**
```
Proforma PRO-2025-001 → Factura FAC-2025-047
                        ↑
                        Sin vínculo!
```
- No puedes saber qué factura vino de qué proforma
- Auditoría fiscal comprometida

### 2. **Doble Facturación Accidental**
```
Usuario convierte proforma → FAC-2025-047 creada
Usuario (error) convierte de nuevo → FAC-2025-048 creada
```
- Proforma no se marca como "convertida"
- Permite conversiones múltiples

### 3. **Verifactu NO se Aplica Correctamente**
```php
// Si hacemos:
$invoice = $service->convertToInvoice($proforma);
// ¿Cuándo se llama a Verifactu?
```

**Problema**:
- Factura se crea en estado `draft`
- **Verifactu NO se llama automáticamente**
- Requiere paso manual posterior
- Riesgo de olvidar enviar a Hacienda

---

## ✅ Solución Propuesta v0.4.0

### 1. **Enlazar Proforma → Factura**

```php
public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
{
    // Verificar que no esté ya convertida
    if ($proforma->converted_invoice_id) {
        throw new \LogicException('This proforma has already been converted');
    }

    // ... crear factura ...
    $invoice = $this->createInvoice($invoiceData, $options);
    
    // ✅ ENLAZAR
    $invoice->update(['proforma_id' => $proforma->id]);
    
    // ✅ MARCAR PROFORMA COMO CONVERTIDA
    $proforma->update([
        'status' => InvoiceStatus::CONVERTED,  // Nuevo estado
        'converted_invoice_id' => $invoice->id,
        'converted_at' => now(),
    ]);
    
    return $invoice;
}
```

### 2. **Verificación Fiscal Automática**

```php
public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
{
    // ... crear factura ...
    
    // ✅ VERIFACTU AUTOMÁTICO (configurable)
    if (config('larabill.fiscal_verification.auto_verify', true)) {
        $this->applyFiscalVerification($invoice);
    }
    
    // ✅ INMUTABLE AUTOMÁTICO después de Verifactu
    if ($invoice->isFiscallyVerified()) {
        $invoice->makeImmutable();
    }
    
    return $invoice;
}
```

### 3. **Nuevos Campos en Migración**

```php
// Añadir a invoices table:
$table->foreignUuid('converted_invoice_id')
    ->nullable()
    ->constrained('invoices')
    ->nullOnDelete()
    ->comment('If this is a proforma, references the invoice it was converted to');

$table->timestamp('converted_at')
    ->nullable()
    ->comment('When this proforma was converted to invoice');
```

### 4. **Nuevo Estado: CONVERTED**

```php
// src/Enums/InvoiceStatus.php
enum InvoiceStatus: int
{
    case DRAFT = 0;
    case SENT = 1;
    case PAID = 2;
    case OVERDUE = 3;
    case CANCELLED = 4;
    case CONVERTED = 5;  // ✅ Nuevo: Para proformas convertidas
}
```

---

## 🔄 Flujo Propuesto v0.4.0

### Flujo Completo Proforma → Factura

```
1. Usuario crea proforma:
   $proforma = $service->createProforma([...]);
   
   Estado:
   - serie = 0 (PROFORMA)
   - fiscal_number = PRO-2025-001
   - status = DRAFT
   - is_immutable = false
   - fiscal_verification_id = null

2. Servicio entregado/pagado:
   $invoice = $service->convertToInvoice($proforma);
   
   Estado Factura:
   - serie = 1 (INVOICE)
   - fiscal_number = FAC-2025-047
   - status = DRAFT → SENT (después Verifactu)
   - proforma_id = $proforma->id  ✅
   - issuer_snapshot = [encrypted]  ✅
   - customer_snapshot = [encrypted]  ✅
   - fiscal_snapshot = [encrypted]  ✅
   
   Estado Proforma (actualizada):
   - status = CONVERTED  ✅
   - converted_invoice_id = $invoice->id  ✅
   - converted_at = now()  ✅

3. Verificación Fiscal (automática):
   - Llama a FiscalVerificationContract
   - Envía a Verifactu (España) o equivalente
   - Obtiene: ID, QR, Hash
   - Actualiza factura con datos de verificación
   - Marca is_immutable = true
```

---

## 📋 Checklist de Implementación

### Migraciones
- [ ] Añadir `converted_invoice_id` a `invoices`
- [ ] Añadir `converted_at` a `invoices`
- [ ] Actualizar índices

### Enums
- [ ] Añadir `InvoiceStatus::CONVERTED`

### Modelo Invoice
- [ ] Relación `convertedInvoice()`
- [ ] Scope `scopeConverted()`
- [ ] Helper `isConverted()`

### BillingService
- [ ] Actualizar `convertToInvoice()`:
  - [ ] Verificar no convertida
  - [ ] Enlazar proforma_id
  - [ ] Marcar proforma como CONVERTED
  - [ ] Crear snapshots encriptados
  - [ ] Llamar Verifactu automático
  - [ ] Hacer inmutable si verificada
  
### Tests
- [ ] Test: No se puede convertir dos veces
- [ ] Test: Proforma enlazada correctamente
- [ ] Test: Verifactu se llama automáticamente
- [ ] Test: Factura queda inmutable después de Verifactu
- [ ] Test: Snapshots encriptados correctamente

---

## 🎯 Decisión Arquitectónica

### ADR-008: Verificación Fiscal en Conversión Proforma

**Contexto**: 
- Verifactu requiere envío inmediato a AEAT
- Proformas no son fiscales, facturas sí
- Conversión es el momento crítico

**Decisión**:
1. **Verificación fiscal AUTOMÁTICA** en `convertToInvoice()` (configurable)
2. **Inmutabilidad AUTOMÁTICA** después de verificación exitosa
3. **Trazabilidad COMPLETA** proforma ↔ factura
4. **Prevención de doble conversión**

**Consecuencias**:
- ✅ Compliance fiscal garantizado
- ✅ Menor error humano
- ✅ Auditoría completa
- ⚠️ Requiere Verifactu configurado en producción
- ⚠️ Rollback más complejo (factura inmutable)

---

## 🚀 Orden de Implementación

1. **Primero**: Migraciones (`converted_invoice_id`, `converted_at`)
2. **Segundo**: Enum `InvoiceStatus::CONVERTED`
3. **Tercero**: Actualizar `BillingService::convertToInvoice()`
4. **Cuarto**: Tests exhaustivos
5. **Quinto**: Documentación

---

**¿Procedemos con esta implementación?** 🎯

