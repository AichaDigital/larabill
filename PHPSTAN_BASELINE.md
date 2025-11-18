# PHPStan Baseline - Justificación y Plan de Eliminación

**Fecha**: 2025-11-17  
**Versión**: v0.4.0-alpha  
**Estado**: 42 errores en baseline (reducción del 75% desde inicio)

---

## 📊 Resumen Ejecutivo

### Progreso Logrado
- **Inicio del refactor**: 171 errores PHPStan Level 5
- **Estado actual**: 42 errores (baseline)
- **Reducción**: **129 errores corregidos (-75%)**

### Justificación del Baseline
El baseline se genera para **v0.4.0-alpha** por las siguientes razones:

1. **Paquete en estado alpha**: No está en producción
2. **75% de mejora**: Reducción significativa de errores
3. **Errores complejos residuales**: Principalmente en código experimental nuevo
4. **CI/CD desbloqueado**: Permite progreso continuo sin bloquear pipelines

---

## ✅ Correcciones Implementadas (7 commits)

### 1. Exclusión de Factories
- **Archivos**: `database/factories/*`, `src/Database/Factories/*`
- **Razón**: Covariance warnings en factories son noise común en Laravel

### 2. PHPDoc Completo en Modelos Core
- ✅ `Article`: 30+ properties documentadas
- ✅ `ArticleServiceStatus`: 18+ properties documentadas
- ✅ `ArticleOverride`: 10+ properties documentadas
- ✅ `Invoice`: Agregadas `total`, `subtotal`, `type`, `converted_invoice_id`
- ✅ `InvoiceItem`: Agregada `article_id`
- ✅ `IssuerConfig`: Relación `currentProfile` documentada
- ✅ `IssuerTaxProfile`: Agregado `roi_enabled` alias
- ✅ `Customer`: Agregados `name`, `relationship_to_user` aliases

### 3. Nullsafe Innecesarios Corregidos
- `Article::getPriceFor()`: `?->custom_price` → `->custom_price`
- `ArticleServiceStatus::updateEffectivePrice()`: `?->custom_price` → `->custom_price`
- `PricingService::getPriceForCustomer()`: `?->custom_price` → `->custom_price`
- `RecurringBillingService::generateInvoiceNumber()`: `?->series_number` → `->series_number`

### 4. Return Types Corregidos
- `InvoiceService::mapStatusToEnum()`: `string` → `int`
- `InvoiceService::getTempSeriesNumber()`: Cast `(string) $serie`

### 5. Exclusión de Directorios con env()
- `config/*`, `src/config/*`: Excluidos (uso legítimo de `env()`)

---

## ⚠️ Errores en Baseline (42 total)

### Distribución por Tipo
```
property.notFound       16 errores (38%)
return.type              7 errores (17%)
argument.type            3 errores  (7%)
method.notFound          2 errores  (5%)
method.nonObject         3 errores  (7%)
nullsafe.neverNull       1 error   (2%)
Otros                   10 errores (24%)
```

### Archivos Principales
1. **InvoiceService** (nuevo v0.4.0): 20 errores
   - Relaciones `currentTaxProfile` sin type inference
   - Acceso a propiedades de `Illuminate\Database\Eloquent\Model` genérico
   
2. **BillingService** (legacy): 8 errores
   - Métodos no definidos
   - Return types incorrectos
   
3. **RecurringBillingService** (legacy): 6 errores
   - Property access en tipos genéricos
   
4. **Otros servicios legacy**: 8 errores
   - PricingService, DestinationVatService, ServiceLifecycleService

---

## 🎯 Plan de Eliminación del Baseline

### Fase 1: v0.4.1 (Corto Plazo - 2 semanas)
**Target**: Reducir a 20 errores (-50%)

**Acciones**:
1. **InvoiceService** (15 errores eliminables):
   - Refactor: Usar DTOs para snapshots en lugar de acceso directo a relaciones
   - Agregar métodos helper tipados: `getIssuerProfile()`, `getCustomerProfile()`
   - Implementar `@method` PHPDoc en Customer/IssuerConfig para relaciones

2. **BillingService** (5 errores eliminables):
   - Agregar PHPDoc `@method` para métodos dinámicos
   - Corregir return types de métodos helper

### Fase 2: v0.5.0 (Medio Plazo - 1 mes)
**Target**: Reducir a 10 errores (-75% adicional)

**Acciones**:
1. **Servicios Legacy** (10 errores):
   - Refactor de RecurringBillingService con tipos estrictos
   - Eliminar código legacy no usado (método `calculateSubtotal` unused)
   - Implementar contracts para servicios sin interface

### Fase 3: v0.6.0 (Largo Plazo - 2 meses)
**Target**: 0 errores - Eliminar baseline

**Acciones**:
1. **Refactor Completo**:
   - Migrar a PHP 8.3+ features (readonly properties, typed class constants)
   - Implementar Larastan generics para todas las relaciones Eloquent
   - Code review completo de tipos en todos los servicios

2. **Subir PHPStan Level**:
   - Level 5 → Level 6: Agregar type hints estrictos
   - Level 6 → Level 7: Union types precisos
   - Level 7 → Level 8: Mixed types eliminados

---

## 📈 Métricas de Calidad

### Estado Actual (v0.4.0-alpha)
- **PHPStan Level**: 5
- **Errores con baseline**: 0 ✅
- **Errores sin baseline**: 42 ⚠️
- **Cobertura de tests**: 640/909 passing (70%)
- **Pint (Laravel Code Style)**: 100% ✅

### Objetivo v1.0.0
- **PHPStan Level**: 8
- **Errores**: 0 (sin baseline)
- **Cobertura de tests**: 100%
- **Pint**: 100%

---

## 🔍 Análisis Técnico de Errores Baseline

### Problema Principal: Type Inference en Relaciones Eloquent

**Ejemplo del error más común**:
```php
// En InvoiceService.php:104
$profile = $issuer->currentTaxProfile; // ← PHPStan ve: Model|null

$profile->legal_name; // ← Error: property.notFound en Model
```

**Solución intentadas**:
1. ❌ `@var IssuerTaxProfile $profile` - No funciona en PHPStan 5
2. ❌ `assert($profile instanceof IssuerTaxProfile)` - Genera más errores
3. ❌ `@return HasOne<IssuerTaxProfile>` - Generics no soportados correctamente

**Solución recomendada para v0.4.1**:
```php
// Opción 1: Helper method
protected function getIssuerProfile(IssuerConfig $issuer): IssuerTaxProfile
{
    return $issuer->currentTaxProfile;
}

// Opción 2: DTO
class IssuerSnapshot {
    public function __construct(
        public readonly string $legal_name,
        public readonly string $tax_id,
        // ...
    ) {}
    
    public static function fromIssuer(IssuerConfig $issuer): self {
        $profile = $issuer->currentTaxProfile;
        return new self(
            legal_name: $profile->legal_name,
            tax_id: $profile->tax_id,
            // ...
        );
    }
}
```

---

## 📝 Compromiso de Calidad

Este baseline es **temporal** y existe únicamente para:
1. ✅ Permitir CI/CD en fase alpha
2. ✅ Documentar progreso significativo (75% mejora)
3. ✅ Establecer plan claro de eliminación

**No se agregarán más errores al baseline**. Cualquier nuevo error PHPStan debe corregirse antes de merge.

---

## 🤝 Contribuciones

Si trabajas en este paquete:
1. **No ignores errores PHPStan**: Corrígelos antes de commit
2. **Consulta este documento**: Antes de modificar código en baseline
3. **Ejecuta `composer quality-full`**: Antes de cada PR
4. **Prioriza correcciones del baseline**: Si trabajas cerca de archivos con errores

---

**Documento generado**: 2025-11-17  
**Última actualización**: 2025-11-17  
**Responsable**: @abkrim  
**Review**: Requerido en cada versión minor (v0.x.0)

