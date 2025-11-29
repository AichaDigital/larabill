# 🔍 ANÁLISIS LARA-VERIFACTU vs LARABILL Integration

**Fecha**: 2025-01-25
**Contexto**: Análisis de integración entre larabill y lara-verifactu

---

## 📊 Estado Actual de LARA-VERIFACTU

### ✅ **Estructura del Paquete**

```
lara-verifactu/
├── Services/
│   ├── InvoiceRegistrar.php      ← Servicio principal (orquestador)
│   ├── RegistryManager.php       ← Gestión de registros
│   ├── AeatClient.php            ← Comunicación AEAT
│   ├── XmlBuilder.php            ← Generación XML
│   ├── QrGenerator.php           ← Generación QR
│   ├── HashGenerator.php         ← Hash SHA-256
│   └── CertificateManager.php    ← Firma digital
├── Jobs/
│   ├── ProcessInvoiceRegistrationJob.php  ← Job principal ⚠️
│   ├── SubmitRegistryToAeatJob.php
│   ├── RetryFailedRegistriesJob.php
│   └── VerifyBlockchainIntegrityJob.php
├── Contracts/
│   ├── InvoiceContract.php       ← Interfaz para facturas
│   ├── RegistryContract.php      ← Interfaz para registros
│   └── ... otros contratos
└── Models/
    ├── Invoice.php               ← Modelo nativo
    ├── Registry.php              ← Registro Verifactu
    └── InvoiceBreakdown.php      ← Desglose fiscal
```

---

## 🚨 Hallazgos Críticos

### ❌ **PROBLEMA 1: Job SIN Secuencialidad**

```php
// ProcessInvoiceRegistrationJob.php (líneas 24-40)
class ProcessInvoiceRegistrationJob implements ShouldQueue
{
    public int $tries;  // ← Configurable (default: 3)
    public int $timeout;  // ← Configurable (default: 60)

    public function __construct(
        public readonly int $invoiceId,
        public readonly bool $submitToAeat = true
    ) {
        $this->tries = config('verifactu.retry.max_attempts', 3);
        $this->timeout = config('verifactu.retry.timeout', 60);
        $this->onQueue(config('verifactu.queue.name', 'default'));  // ← Cola 'default'
    }
}
```

**❌ Problemas Detectados**:
1. **No hay lock único** → Múltiples jobs pueden ejecutarse en paralelo
2. **No verifica secuencialidad** → Factura N+1 puede procesarse antes que N
3. **Cola 'default'** → Comparte con otros jobs del sistema
4. **Retry automático** (tries=3) → Puede causar inconsistencias

---

### ❌ **PROBLEMA 2: Falta Verificación de Orden**

```php
// InvoiceRegistrar.php - register() method
public function register(InvoiceContract $invoice, bool $submitToAeat = true): RegistryContract
{
    return DB::transaction(function () use ($invoice, $submitToAeat) {
        // ❌ NO verifica si hay facturas anteriores sin procesar
        $registry = $this->registryManager->createRegistry($invoice);
        
        // Firma XML
        $signedXml = $this->signXml($xml);
        
        // Envía a AEAT
        if ($submitToAeat) {
            $this->submitToAeat($registry);
        }
        
        return $registry;
    });
}
```

**❌ No hay validación de**:
- Facturas anteriores sin verificar
- Número secuencial fiscal
- Estado de la "blockchain" de registros

---

### ✅ **ACIERTO: Blockchain de Hashes**

```php
// Registry Model
protected $fillable = [
    'hash',              // ← SHA-256 de esta factura
    'previous_hash',     // ← Hash de la factura anterior (blockchain!)
    // ...
];
```

**✅ Esto es EXCELENTE** porque:
- Crea cadena de integridad
- Detecta manipulaciones
- Permite verificación posterior

**⚠️ PERO**: La blockchain NO garantiza orden de procesamiento, solo integridad

---

## 🎯 Incompatibilidades con Arquitectura LARABILL

| Aspecto | LARA-VERIFACTU | LARABILL (propuesto) | Compatible? |
|---------|----------------|----------------------|-------------|
| **Secuencialidad** | ❌ No implementada | ✅ Lock único + verificación | ❌ NO |
| **Cola dedicada** | ❌ 'default' | ✅ 'fiscal_verification' | ❌ NO |
| **Lock único** | ❌ No existe | ✅ Cache::lock() | ❌ NO |
| **Retry en error** | ✅ Automático (3x) | ❌ Manual después de fix | ⚠️ CONFLICTO |
| **Blockchain** | ✅ Implementado | ✅ Compatible | ✅ SÍ |
| **Contratos** | ✅ InvoiceContract | ✅ FiscalVerificationContract | ⚠️ DIFERENTE |

---

## 🔧 Refactors Necesarios en LARA-VERIFACTU

### 1. **ProcessInvoiceRegistrationJob → Añadir Secuencialidad**

```php
// Propuesta: Actualizar ProcessInvoiceRegistrationJob

class ProcessInvoiceRegistrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CAMBIO 1: Solo 1 intento (LARABILL maneja reintentos)
    public int $tries = 1;
    
    public int $timeout;

    public function __construct(
        public readonly int $invoiceId,
        public readonly bool $submitToAeat = true
    ) {
        $this->timeout = config('verifactu.retry.timeout', 60);
        
        // ✅ CAMBIO 2: Cola dedicada
        $this->onQueue(config('verifactu.queue.name', 'fiscal_verification'));
    }

    public function handle(InvoiceRegistrar $registrar): void
    {
        // ✅ CAMBIO 3: Lock único
        $lock = Cache::lock('fiscal_verification_queue', 300);
        
        if (!$lock->get()) {
            $this->release(10); // Reintentar en 10 segundos
            return;
        }

        try {
            $invoice = Invoice::find($this->invoiceId);
            
            if (!$invoice) {
                Log::warning('Invoice not found', ['invoice_id' => $this->invoiceId]);
                return;
            }

            // ✅ CAMBIO 4: Verificar secuencialidad (NUEVO)
            $this->ensureSequentialOrder($invoice);

            // Registro existente
            $registry = $registrar->register($invoice, $this->submitToAeat);

            Log::info('Invoice registered successfully', [
                'invoice_id' => $this->invoiceId,
                'registry_number' => $registry->getRegistryNumber(),
            ]);
            
        } catch (\Throwable $e) {
            Log::error('Failed to register invoice', [
                'invoice_id' => $this->invoiceId,
                'error' => $e->getMessage(),
            ]);
            
            $lock->release();
            throw $e; // Job falla, no retry automático
            
        } finally {
            $lock->release();
        }
    }

    /**
     * ✅ NUEVO: Verificar secuencialidad fiscal.
     */
    protected function ensureSequentialOrder(Invoice $invoice): void
    {
        // Obtener número de serie fiscal
        $fiscalNumber = $invoice->fiscal_number; // Debe venir de LARABILL
        
        // Buscar facturas anteriores sin registrar
        $previousUnregistered = Invoice::where('fiscal_year', $invoice->fiscal_year)
            ->where('serie', $invoice->serie)
            ->where('series_number', '<', $invoice->series_number)
            ->whereDoesntHave('registry')
            ->exists();

        if ($previousUnregistered) {
            throw new \RuntimeException(
                "Cannot register invoice {$fiscalNumber}. " .
                "Previous invoices in serie {$invoice->serie} are not registered yet."
            );
        }
    }
}
```

---

### 2. **InvoiceRegistrar → Añadir Verificación Previa**

```php
// Actualizar InvoiceRegistrar::register()

public function register(InvoiceContract $invoice, bool $submitToAeat = true): RegistryContract
{
    return DB::transaction(function () use ($invoice, $submitToAeat) {
        // ✅ AÑADIR: Verificar que no existe ya un registry
        if ($invoice->registry()->exists()) {
            Log::warning('Invoice already has a registry', [
                'invoice_number' => $invoice->getNumber(),
            ]);
            
            return $invoice->registry;  // Devolver existente
        }

        // ✅ AÑADIR: Verificar orden secuencial (delegado al job, pero doble check)
        $this->verifySequentialOrder($invoice);

        // Crear registry (código existente)
        $registry = $this->registryManager->createRegistry($invoice);

        // ... resto del código
        
        return $registry;
    });
}

/**
 * ✅ NUEVO: Verificar orden secuencial.
 */
private function verifySequentialOrder(InvoiceContract $invoice): void
{
    // Obtener último registro de la misma serie
    $lastRegistry = Registry::whereHas('invoice', function ($q) use ($invoice) {
        $q->where('fiscal_year', $invoice->getFiscalYear())
          ->where('serie', $invoice->getSerie());
    })
    ->orderBy('id', 'desc')
    ->first();

    if ($lastRegistry) {
        $lastInvoice = $lastRegistry->getInvoice();
        
        // Verificar que el número secuencial sea consecutivo
        if ($invoice->getSeriesNumber() !== $lastInvoice->getSeriesNumber() + 1) {
            throw new \LogicException(
                "Invalid sequence: expected {$lastInvoice->getSeriesNumber() + 1}, got {$invoice->getSeriesNumber()}"
            );
        }
    }
}
```

---

## 🔗 Integración con LARABILL

### **Opción A: LARABILL Dispatch → LARA-VERIFACTU Job (RECOMENDADO)**

```php
// En LARABILL - BillingService

use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;

public function createInvoice(array $invoiceData, array $options = []): Invoice
{
    // 1. Crear factura con snapshots
    $invoice = $this->createInvoiceWithSnapshots($invoiceData);
    
    // 2. Verificar si necesita verificación fiscal
    if ($this->shouldVerifyFiscally($invoice)) {
        // 3. ✅ Dispatch job de LARA-VERIFACTU directamente
        ProcessInvoiceRegistrationJob::dispatch($invoice->id)
            ->onQueue('fiscal_verification')
            ->afterCommit();
    }
    
    return $invoice;
}
```

**✅ Ventajas**:
- Un solo job (menos complejidad)
- LARA-VERIFACTU sigue siendo responsable del proceso completo
- LARABILL solo dispara el proceso

**⚠️ Desventajas**:
- LARABILL depende del job de LARA-VERIFACTU
- Cambios en LARA-VERIFACTU pueden romper LARABILL

---

### **Opción B: LARABILL Job → LARA-VERIFACTU Service (MÁS FLEXIBLE)**

```php
// En LARABILL - FiscalVerificationJob

namespace AichaDigital\Larabill\Jobs;

use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;

class FiscalVerificationJob implements ShouldQueue
{
    // ... (lock, secuencialidad, etc.)

    public function handle(): void
    {
        $lock = Cache::lock('fiscal_verification_queue', 300);
        
        if (!$lock->get()) {
            $this->release(10);
            return;
        }

        try {
            // Verificar secuencialidad
            $this->ensureSequentialOrder();
            
            // ✅ Delegar a LARA-VERIFACTU Service (no Job)
            $registrar = app(InvoiceRegistrar::class);
            $registry = $registrar->register($this->invoice, true);
            
            // Actualizar Invoice con datos de verificación
            $this->invoice->update([
                'fiscal_verification_id' => $registry->getAeatCsv(),
                'fiscal_verification_qr' => $registry->getQrSvg(),
                'fiscal_verification_hash' => $registry->getHash(),
                'fiscal_verified_at' => now(),
            ]);
            
            $this->invoice->makeImmutable();
            
        } catch (\Throwable $e) {
            $this->handleFiscalVerificationFailure($e);
            $lock->release();
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
```

**✅ Ventajas**:
- LARABILL controla el flujo completo (lock, secuencialidad, reintentos)
- LARA-VERIFACTU solo proporciona servicios
- Más flexible para adaptar a otros países

**⚠️ Desventajas**:
- Duplicación de lógica de jobs
- LARA-VERIFACTU pierde control del proceso

---

## 📋 Decisiones Requeridas

### 1. **¿Qué opción elegir?**

| Aspecto | Opción A (Job Verifactu) | Opción B (Job Larabill) |
|---------|--------------------------|-------------------------|
| **Control** | LARA-VERIFACTU | LARABILL ✅ |
| **Flexibilidad** | Menor | Mayor ✅ |
| **Complejidad** | Menor ✅ | Mayor |
| **Reusabilidad** | LARA-VERIFACTU público | Solo interno |
| **Testing** | En ambos paquetes | Principalmente LARABILL ✅ |

**💡 Mi Recomendación**: **Opción B** (Job en LARABILL)
- **Razón**: "Larabill manda" como dijiste
- LARABILL es el orquestador
- LARA-VERIFACTU es un proveedor de servicios
- Más fácil adaptar a TicketBAI, etc.

### 2. **¿Cómo mapear Invoice de LARABILL → LARA-VERIFACTU?**

```php
// LARABILL Invoice → LARA-VERIFACTU InvoiceContract

use AichaDigital\LaraVerifactu\Contracts\InvoiceContract;

class LarabillInvoiceAdapter implements InvoiceContract
{
    public function __construct(
        private \AichaDigital\Larabill\Models\Invoice $invoice
    ) {}

    public function getNumber(): string
    {
        return $this->invoice->fiscal_number;
    }

    public function getSerie(): string
    {
        return (string) $this->invoice->serie->value;
    }

    public function getSeriesNumber(): int
    {
        return $this->invoice->series_number;
    }

    public function getFiscalYear(): int
    {
        return $this->invoice->fiscal_year;
    }

    // ... otros métodos del contrato
}
```

### 3. **¿Actualizar LARA-VERIFACTU o Wrapper en LARABILL?**

**Opción A**: Actualizar LARA-VERIFACTU
- ✅ Beneficia a todos los usuarios del paquete
- ✅ Mantiene paquete público actualizado
- ⚠️ Requiere versión major (breaking changes)

**Opción B**: Wrapper/Adapter en LARABILL
- ✅ No toca LARA-VERIFACTU
- ✅ Más rápido de implementar
- ⚠️ Duplica algo de lógica

**💡 Mi Recomendación**: **Opción A** (actualizar LARA-VERIFACTU)
- Crear v2.0 con secuencialidad
- Mantener v1.x para BC
- Documentar migración

---

## 🎯 Plan de Acción Propuesto

### **FASE 1: Actualizaciones en LARA-VERIFACTU (v2.0)**

1. ✅ Añadir secuencialidad en `ProcessInvoiceRegistrationJob`
2. ✅ Añadir lock único
3. ✅ Cola dedicada 'fiscal_verification'
4. ✅ Eliminar retry automático (tries=1)
5. ✅ Añadir `ensureSequentialOrder()` en job
6. ✅ Actualizar config por defecto

### **FASE 2: Integración en LARABILL**

1. ✅ Requerir `lara-verifactu: ^2.0`
2. ✅ Crear `LarabillInvoiceAdapter` (implementa `InvoiceContract`)
3. ✅ `BillingService` dispatch `ProcessInvoiceRegistrationJob`
4. ✅ Actualizar `Invoice` model para vincular con `Registry`
5. ✅ Añadir campos en migración si es necesario
6. ✅ Tests con sandbox de AEAT

### **FASE 3: Testing**

1. ✅ Test unitario de secuencialidad
2. ✅ Test de lock único
3. ✅ Test de blockchain de hashes
4. ✅ Test con AEAT sandbox
5. ✅ Test de recuperación de errores

---

## ✅ Conclusiones

### **Estado LARA-VERIFACTU**:
- ✅ Estructura sólida
- ✅ Blockchain de hashes implementado
- ✅ Comunicación con AEAT funcional
- ❌ Falta secuencialidad estricta
- ❌ Falta lock único
- ❌ Retry automático problemático

### **Necesidades LARABILL**:
- ✅ Secuencialidad garantizada
- ✅ Control completo del flujo
- ✅ Integración con snapshots encriptados
- ✅ Soporte multi-país (TicketBAI, etc.)

### **Recomendación Final**:
1. **Actualizar LARA-VERIFACTU a v2.0** con secuencialidad
2. **LARABILL dispara job de LARA-VERIFACTU** (Opción A)
3. **Crear adapter** para mapear Invoice → InvoiceContract
4. **Tests exhaustivos** antes de producción

---

**¿Procedemos con este plan? ¿Empezamos actualizando LARA-VERIFACTU o prefieres implementar primero en LARABILL con wrapper?** 🚀

