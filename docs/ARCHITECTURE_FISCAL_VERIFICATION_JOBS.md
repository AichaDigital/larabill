# 🎯 ANÁLISIS CRÍTICO: Jobs Queue + Verifactu Integration

**Fecha**: 2025-01-25
**Contexto**: Arquitectura de verificación fiscal con colas y locks

---

## 🔒 Requisitos CRÍTICOS Identificados

### 1. **Secuencialidad ESTRICTA** (JAMAS violable)

```
Factura N debe ser verificada ANTES que Factura N+1
```

**Implicaciones**:
- ❌ **NO** se puede usar cola normal (workers paralelos)
- ✅ **SÍ** se necesita cola con **lock único**
- ✅ **SÍ** se necesita **orden ESTRICTO** por `series_number`

### 2. **Bloqueo Total en Caso de Error**

```
Si Factura 47 FALLA → TODO el sistema PARA hasta resolución manual
```

**Implicaciones**:
- ❌ No se puede "saltar" una factura fallida
- ✅ Sistema debe detectar el bloqueo
- ✅ Notificación URGENTE a administrador
- ✅ Mecanismo de retry manual después de solución

### 3. **Proforma → Factura Conserva Ambos Códigos**

```
Proforma: PRO-2025-001
   ↓ conversión
Factura: FAC-2025-047
   ↓ debe conservar
   proforma_id = PRO-2025-001
   fiscal_number = FAC-2025-047
```

**Implicaciones**:
- ✅ Trazabilidad completa
- ✅ Proforma bloqueada (inmutable)
- ✅ Factura bloqueada después de Verifactu

---

## 🏗️ Arquitectura Propuesta

### **Separación de Responsabilidades**

```
┌─────────────────────────────────────────────────────────────┐
│                      LARABILL (este paquete)                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. Crear Invoice con snapshots encriptados                │
│  2. Validar precondiciones (Customer, Issuer)              │
│  3. Dispatch Job a cola SECUENCIAL                         │
│  4. Actualizar estado post-verificación                    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                            ↓
                     [Queue: fiscal_verification]
                     [Connection: database/redis]
                     [Unique Lock per Invoice]
                            ↓
┌─────────────────────────────────────────────────────────────┐
│              LARA-VERIFACTU (paquete externo)               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. Recibe Job con Invoice                                 │
│  2. Verifica secuencialidad (N antes que N+1)              │
│  3. Comunica con AEAT API                                  │
│  4. Obtiene: ID, QR, Hash, CSV                             │
│  5. Actualiza Invoice con datos fiscales                   │
│  6. Marca Invoice como inmutable                           │
│                                                             │
│  EN CASO DE ERROR:                                         │
│  - Marca Invoice como FAILED                               │
│  - BLOQUEA cola (no procesa siguientes)                    │
│  - Notifica administrador                                  │
│  - Espera retry manual                                     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔧 Implementación en LARABILL

### 1. **BillingService: Dispatch Job**

```php
namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Jobs\FiscalVerificationJob;

class BillingService
{
    /**
     * Create invoice and dispatch fiscal verification.
     */
    public function createInvoice(array $invoiceData, array $options = []): Invoice
    {
        // 1. Crear factura con snapshots
        $invoice = $this->createInvoiceWithSnapshots($invoiceData);
        
        // 2. Verificar si necesita verificación fiscal
        if ($this->shouldVerifyFiscally($invoice)) {
            // 3. Dispatch a cola SECUENCIAL
            FiscalVerificationJob::dispatch($invoice)
                ->onQueue('fiscal_verification')  // Cola dedicada
                ->afterCommit();  // Después de commit DB
        }
        
        return $invoice;
    }
    
    /**
     * Convert proforma to invoice.
     */
    public function convertToInvoice(Invoice $proforma, array $options = []): Invoice
    {
        // Verificar no convertida
        if ($proforma->isConverted()) {
            throw new \LogicException('Proforma already converted');
        }
        
        // Crear factura (automáticamente dispatcha FiscalVerificationJob)
        $invoice = $this->createInvoice([
            'customer_id' => $proforma->customer_id,
            'user_id' => $proforma->user_id,
            'items' => $this->getInvoiceItemsData($proforma),
            // ... otros datos
        ], $options);
        
        // Enlazar proforma → factura
        $invoice->update(['proforma_id' => $proforma->id]);
        
        // BLOQUEAR proforma (inmutable)
        $proforma->update([
            'status' => InvoiceStatus::CONVERTED,
            'converted_invoice_id' => $invoice->id,
            'converted_at' => now(),
        ]);
        $proforma->makeImmutable();
        
        return $invoice;
    }
    
    /**
     * Determinar si necesita verificación fiscal.
     */
    protected function shouldVerifyFiscally(Invoice $invoice): bool
    {
        // Solo facturas fiscales (no proformas)
        if ($invoice->serie === InvoiceSerieType::PROFORMA) {
            return false;
        }
        
        // Solo si está habilitado
        if (!config('larabill.fiscal_verification.enabled', false)) {
            return false;
        }
        
        // Solo si hay connector configurado
        $connector = app(FiscalVerificationContract::class);
        return $connector->isAvailable();
    }
}
```

---

### 2. **Job: FiscalVerificationJob (en LARABILL)**

```php
namespace AichaDigital\Larabill\Jobs;

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class FiscalVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * CRÍTICO: NO reintentar automáticamente.
     * El sistema debe PARAR en caso de error.
     */
    public int $tries = 1;
    
    /**
     * Timeout: 60 segundos para comunicación con AEAT.
     */
    public int $timeout = 60;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function handle(FiscalVerificationContract $verifier): void
    {
        // 1. LOCK: Solo un job de verificación fiscal a la vez
        $lock = Cache::lock('fiscal_verification_queue', 300); // 5 minutos max
        
        if (!$lock->get()) {
            // Ya hay otro job procesándose
            $this->release(10); // Reintentar en 10 segundos
            return;
        }

        try {
            // 2. Verificar secuencialidad (CRÍTICO)
            $this->ensureSequentialOrder();
            
            // 3. Delegar verificación al paquete externo
            $result = $verifier->verifyInvoice($this->invoice);
            
            // 4. Actualizar factura con datos fiscales
            $this->invoice->update([
                'fiscal_verification_id' => $result['id'],
                'fiscal_verification_qr' => $result['qr'],
                'fiscal_verification_hash' => $result['hash'],
                'fiscal_verified_at' => now(),
                'fiscal_verification_metadata' => $result['metadata'] ?? null,
                'status' => InvoiceStatus::SENT, // Ya verificada
            ]);
            
            // 5. Hacer INMUTABLE
            $this->invoice->makeImmutable();
            
        } catch (\Exception $e) {
            // 🚨 ERROR CRÍTICO: Sistema DEBE PARAR
            $this->handleFiscalVerificationFailure($e);
            
            // Liberar lock y RE-LANZAR excepción (no catch)
            $lock->release();
            throw $e; // Job falla, queda en failed_jobs
            
        } finally {
            $lock->release();
        }
    }

    /**
     * CRÍTICO: Verificar que no hay facturas anteriores sin verificar.
     */
    protected function ensureSequentialOrder(): void
    {
        $previousUnverified = Invoice::where('serie', $this->invoice->serie)
            ->where('fiscal_year', $this->invoice->fiscal_year)
            ->where('series_number', '<', $this->invoice->series_number)
            ->whereNull('fiscal_verified_at')
            ->exists();

        if ($previousUnverified) {
            throw new \RuntimeException(
                "Cannot verify invoice {$this->invoice->fiscal_number}. " .
                "Previous invoices in serie {$this->invoice->serie} are not verified yet."
            );
        }
    }

    /**
     * Manejar fallo de verificación fiscal.
     */
    protected function handleFiscalVerificationFailure(\Exception $e): void
    {
        // Marcar factura como FAILED
        $this->invoice->update([
            'status' => InvoiceStatus::VERIFICATION_FAILED, // Nuevo estado
            'fiscal_verification_metadata' => [
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
                'code' => $e->getCode(),
            ],
        ]);

        // Notificar URGENTEMENTE
        \Illuminate\Support\Facades\Notification::route('mail', config('larabill.admin_email'))
            ->notify(new \AichaDigital\Larabill\Notifications\FiscalVerificationFailedNotification(
                $this->invoice,
                $e
            ));

        // Log crítico
        \Illuminate\Support\Facades\Log::critical('Fiscal verification FAILED', [
            'invoice_id' => $this->invoice->id,
            'fiscal_number' => $this->invoice->fiscal_number,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Job falló después de todos los intentos.
     */
    public function failed(\Throwable $exception): void
    {
        // Sistema BLOQUEADO - notificar
        \Illuminate\Support\Facades\Log::emergency('Fiscal verification system BLOCKED', [
            'invoice_id' => $this->invoice->id,
            'fiscal_number' => $this->invoice->fiscal_number,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

### 3. **Nuevo Estado: VERIFICATION_FAILED**

```php
// src/Enums/InvoiceStatus.php

enum InvoiceStatus: int
{
    case DRAFT = 0;
    case SENT = 1;
    case PAID = 2;
    case OVERDUE = 3;
    case CANCELLED = 4;
    case CONVERTED = 5;                 // Proforma convertida
    case VERIFICATION_FAILED = 6;       // ✅ Nuevo: Fallo Verifactu
    case VERIFICATION_PENDING = 7;      // ✅ Nuevo: En cola
}
```

---

## 🔧 Implementación en LARA-VERIFACTU

### **Responsabilidades del Paquete Externo**

```php
// En el paquete aichadigital/lara-verifactu

namespace AichaDigital\LaraVerifactu\Services;

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\Models\Invoice;

class VerifactuService implements FiscalVerificationContract
{
    public function verifyInvoice(Invoice $invoice): array
    {
        // 1. Validar datos de la factura
        $this->validateInvoiceData($invoice);
        
        // 2. Construir XML para AEAT
        $xml = $this->buildAEATXML($invoice);
        
        // 3. Comunicar con API de AEAT
        $response = $this->sendToAEAT($xml);
        
        // 4. Parsear respuesta
        $verification = $this->parseResponse($response);
        
        // 5. Generar QR
        $qr = $this->generateQRCode($verification);
        
        return [
            'id' => $verification['csv'],  // CSV de AEAT
            'qr' => $qr,
            'hash' => $verification['hash'],
            'metadata' => [
                'aeat_timestamp' => $verification['timestamp'],
                'aeat_response' => $verification['raw'],
            ],
        ];
    }
    
    protected function sendToAEAT(string $xml): array
    {
        // HTTP Client con reintentos
        $response = Http::timeout(30)
            ->retry(3, 100) // 3 intentos, 100ms entre intentos
            ->post(config('lara-verifactu.aeat_endpoint'), [
                'xml' => $xml,
            ]);
            
        if ($response->failed()) {
            throw new \RuntimeException(
                "AEAT API failed: {$response->status()} - {$response->body()}"
            );
        }
        
        return $response->json();
    }
}
```

---

## 📋 Queue Configuration

### **config/queue.php**

```php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ],
    
    // ✅ Cola dedicada para verificación fiscal
    'fiscal' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'fiscal_verification',
        'retry_after' => 300, // 5 minutos
        'block_for' => null,  // No bloquear (manejamos con Cache::lock)
    ],
],
```

### **Supervisor config (production)**

```ini
[program:larabill-fiscal-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work fiscal --queue=fiscal_verification --tries=1 --timeout=60 --sleep=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=forge
numprocs=1  ; ✅ CRÍTICO: SOLO 1 worker (secuencialidad)
redirect_stderr=true
stdout_logfile=/path/to/storage/logs/fiscal-worker.log
stopwaitsecs=3600
```

---

## 🚨 Mecanismo de Recuperación

### **Comando Artisan para Retry Manual**

```php
namespace AichaDigital\Larabill\Console\Commands;

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Jobs\FiscalVerificationJob;
use AichaDigital\Larabill\Models\Invoice;
use Illuminate\Console\Command;

class RetryFiscalVerificationCommand extends Command
{
    protected $signature = 'larabill:retry-fiscal-verification {invoice_id}';
    
    protected $description = 'Retry failed fiscal verification (manual intervention)';

    public function handle(): int
    {
        $invoiceId = $this->argument('invoice_id');
        $invoice = Invoice::find($invoiceId);

        if (!$invoice) {
            $this->error("Invoice {$invoiceId} not found");
            return 1;
        }

        if ($invoice->status !== InvoiceStatus::VERIFICATION_FAILED) {
            $this->error("Invoice {$invoice->fiscal_number} is not in VERIFICATION_FAILED status");
            return 1;
        }

        // Confirmar con operador
        if (!$this->confirm("Retry fiscal verification for {$invoice->fiscal_number}?")) {
            return 0;
        }

        // Dispatch de nuevo
        FiscalVerificationJob::dispatch($invoice)
            ->onQueue('fiscal_verification');

        $this->info("Fiscal verification job dispatched for {$invoice->fiscal_number}");
        return 0;
    }
}
```

---

## 🎯 Flujo Completo con Errores

```
┌──────────────────────────────────────────────────────────────┐
│ FLUJO NORMAL                                                 │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ 1. Usuario crea factura                                     │
│    → BillingService::createInvoice()                        │
│    → Invoice creada (status = VERIFICATION_PENDING)         │
│    → FiscalVerificationJob dispatched                       │
│                                                              │
│ 2. Worker procesa job                                       │
│    → Adquiere lock único                                    │
│    → Verifica secuencialidad (OK)                           │
│    → Llama a Verifactu                                      │
│    → AEAT responde OK                                       │
│    → Invoice actualizada (status = SENT)                    │
│    → Invoice inmutable                                      │
│    → Libera lock                                            │
│                                                              │
│ 3. PDF generado con QR                                      │
│    → Usuario recibe factura                                 │
│                                                              │
└──────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ FLUJO CON ERROR (Sistema BLOQUEA)                           │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ 1. Usuario crea factura FAC-2025-047                        │
│    → Job dispatched                                         │
│                                                              │
│ 2. Worker procesa job                                       │
│    → Adquiere lock                                          │
│    → Verifica secuencialidad (OK)                           │
│    → Llama a Verifactu                                      │
│    → 🚨 AEAT responde ERROR (timeout, XML inválido, etc.)   │
│    → Invoice marcada VERIFICATION_FAILED                    │
│    → Notificación URGENTE a admin                           │
│    → Libera lock                                            │
│    → Job falla (queda en failed_jobs)                       │
│                                                              │
│ 3. Usuario intenta crear FAC-2025-048                       │
│    → Job dispatched                                         │
│    → Worker procesa                                         │
│    → ensureSequentialOrder() DETECTA:                       │
│       "FAC-2025-047 sin verificar"                          │
│    → 🚨 Job FALLA inmediatamente                            │
│    → Sistema BLOQUEADO                                      │
│                                                              │
│ 4. Admin recibe alerta                                      │
│    → Investiga error en FAC-2025-047                        │
│    → Soluciona (ej: XML corregido, AEAT disponible)         │
│    → Ejecuta: php artisan larabill:retry-fiscal-verification│
│    → FAC-2025-047 se verifica OK                            │
│    → FAC-2025-048 ahora puede procesarse                    │
│    → Sistema DESBLOQUEADO                                   │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## 📋 Checklist de Implementación

### En LARABILL (este paquete)
- [ ] `FiscalVerificationJob` con lock único
- [ ] Verificación de secuencialidad en job
- [ ] Estados `VERIFICATION_PENDING`, `VERIFICATION_FAILED`
- [ ] Notificación `FiscalVerificationFailedNotification`
- [ ] Comando `larabill:retry-fiscal-verification`
- [ ] Config `fiscal_verification.enabled`
- [ ] Tests de job (con fake)
- [ ] Tests de secuencialidad
- [ ] Documentación de recuperación

### En LARA-VERIFACTU (paquete externo)
- [ ] Implementar `FiscalVerificationContract`
- [ ] Comunicación con AEAT API
- [ ] Generación de XML
- [ ] Parsing de respuestas
- [ ] Generación de QR
- [ ] Manejo de errores AEAT
- [ ] Tests con AEAT sandbox

---

## ❓ Decisiones Pendientes

1. **¿Dónde vive la lógica de secuencialidad?**
   - Opción A: En LARABILL (mi propuesta) ✅
   - Opción B: En LARA-VERIFACTU
   
2. **¿Cómo notificar al admin?**
   - Email ✅
   - Slack
   - SMS (Twilio)
   - Dashboard alert
   
3. **¿Timeout del lock?**
   - 5 minutos (mi propuesta)
   - 10 minutos
   - Configurable

---

**¿Te parece correcto este diseño? ¿Implementamos primero el job en LARABILL o revisamos LARA-VERIFACTU?** 🚀

