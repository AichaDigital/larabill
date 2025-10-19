# 🚀 Plan de Implementación: Dominio Articles

> **Versión:** 1.0  
> **Estado:** ✅ FASE 4 COMPLETADA - Events & Listeners implementados  
> **Fecha inicio:** 2024-10-19  
> **Última actualización:** 2024-10-19 - Fase 4.3 completa (100% tests passing)

---

## 📋 Índice

1. [Fase 1: Fundamentos](#fase-1-fundamentos)
2. [Fase 2: Modelos Core](#fase-2-modelos-core)
3. [Fase 3: DTOs y Servicios](#fase-3-dtos-y-servicios)
4. [Fase 4: Lógica de Negocio](#fase-4-lógica-de-negocio)
5. [Fase 5: Testing](#fase-5-testing)
6. [Fase 6: Documentación](#fase-6-documentación)

---

## 🎯 Objetivos Globales

- ✅ Implementar dominio completo de Articles
- ✅ Sistema de pricing por cliente (overrides)
- ✅ Control de estado de servicios recurrentes
- ✅ Facturación automática y manual
- ✅ Cobertura de tests > 90%
- ✅ Documentación completa

---

## 📦 FASE 1: Fundamentos

### 1.1 Enums

#### ✅ Enums Existentes (verificar)
- [x] `ItemType` - Verificar si existe o crear

#### 🆕 Enums Nuevos

- [ ] **BillingFrequency** (`src/Enums/BillingFrequency.php`)
  ```php
  enum BillingFrequency: string {
      case MONTHLY = 'M';
      case QUARTERLY = 'Q';
      case YEARLY = 'Y';
      case LIFETIME = 'L';
      
      public function label(): string;
      public function months(): ?int;
      public function addToDate(Carbon $date, int $interval = 1): Carbon;
  }
  ```

- [ ] **ServiceStatus** (`src/Enums/ServiceStatus.php`)
  ```php
  enum ServiceStatus: string {
      case ACTIVE = 'A';
      case PENDING = 'P';
      case SUSPENDED = 'S';
      case CANCELLED = 'C';
      case EXPIRED = 'E';
      
      public function label(): string;
      public function canBill(): bool;
      public function color(): string; // para UI externa
  }
  ```

- [ ] **CancellationType** (`src/Enums/CancellationType.php`)
  ```php
  enum CancellationType: string {
      case IMMEDIATE = 'I';
      case END_OF_PERIOD = 'E';
      case NOTICE_PERIOD = 'N';
      
      public function label(): string;
      public function requiresRefund(): bool;
  }
  ```

**Tiempo estimado:** 1-2 horas  
**Dependencias:** Ninguna  
**Tests:** 3 archivos de test (uno por enum)

---

### 1.2 Migraciones

#### Orden de ejecución (importante por FKs):

1. [x] **`create_articles_table.php`** ✅ (base) - `2025_01_20_000001`
   ```php
   // Campos críticos:
   - id: bigint
   - code: varchar(50) unique
   - item_type: char(1)
   - is_recurring: boolean
   - billing_frequency: char(1) nullable
   - billing_interval: unsignedTinyInteger
   - subscription_type: varchar(100) nullable
   - base_price: integer (Base100)
   - tax_group_id: unsignedSmallInteger nullable
   - unit_measure_id: unsignedTinyInteger nullable
   - metadata: json nullable
   - is_active: boolean
   - timestamps, softDeletes
   ```

2. [x] **`create_article_overrides_table.php`** ✅ - `2025_01_20_000002`
   ```php
   // Campos críticos:
   - id: bigint
   - customer_id: unsignedBigInteger (agnóstico)
   - article_id: FK → articles.id (cascade delete)
   - custom_price: integer (Base100)
   - reason: text nullable
   - valid_from: date nullable
   - valid_to: date nullable
   - is_active: boolean
   - timestamps
   
   // Índices:
   - unique: [customer_id, article_id, valid_from]
   - index: [customer_id, is_active]
   ```

3. [x] **`create_article_service_status_table.php`** ✅ - `2025_01_20_000003`
   ```php
   // Campos críticos:
   - id: bigint
   - customer_id: unsignedBigInteger
   - article_id: FK → articles.id (restrict delete)
   - instance_identifier: varchar(255) nullable
   - instance_name: varchar(255) nullable
   - started_at: date
   - next_billing_date: date nullable
   - expires_at: date nullable
   - status: char(1)
   - cancellation_type: char(1) nullable
   - cancellation_requested_at: date nullable
   - cancellation_effective_at: date nullable
   - refund_unused: boolean
   - effective_price: integer (Base100)
   - current_override_id: FK → article_overrides.id (null delete)
   - external_reference: varchar(255) nullable
   - instance_data: json nullable
   - metadata: json nullable
   - timestamps
   
   // Índices:
   - unique: [customer_id, article_id, instance_identifier]
   - index: [customer_id, status]
   - index: [article_id, status]
   - index: [next_billing_date]
   - index: [instance_identifier]
   ```

4. [ ] **`update_invoice_items_table.php`** (si necesario)
   ```php
   // Verificar que existan:
   - service_date_from: date nullable
   - service_date_to: date nullable
   - metadata: json nullable
   
   // Si no existen, crear migración para añadirlos
   ```

**Tiempo estimado:** 2-3 horas  
**Dependencias:** Enums completados  
**Tests:** Tests de migración + rollback

---

## 📦 FASE 2: Modelos Core

### 2.1 Modelo Article

- [ ] **Crear modelo** (`src/Models/Article.php`)
  ```php
  class Article extends Model {
      use HasFactory, SoftDeletes;
      
      // Casts
      protected $casts = [
          'item_type' => ItemType::class,
          'billing_frequency' => BillingFrequency::class,
          'base_price' => Base100::class,
          'cost_price' => Base100::class,
          'is_recurring' => 'boolean',
          'is_active' => 'boolean',
          'metadata' => 'array',
      ];
      
      // Relationships
      public function taxGroup(): BelongsTo;
      public function unitMeasure(): BelongsTo;
      public function overrides(): HasMany; // ArticleOverride
      public function serviceStatuses(): HasMany; // ArticleServiceStatus
      
      // Scopes
      public function scopeActive(Builder $query): void;
      public function scopeGoods(Builder $query): void;
      public function scopeServices(Builder $query): void;
      public function scopeRecurring(Builder $query): void;
      
      // Helpers
      public function isService(): bool;
      public function isGood(): bool;
      public function requiresInstanceIdentifier(): bool;
      public function validateInstanceIdentifier(?string $identifier): bool;
      public function getEffectivePriceFor(?int $customerId): int;
      public function hasActiveOverrideFor(int $customerId): bool;
  }
  ```

- [ ] **Crear factory** (`src/database/factories/ArticleFactory.php`)
  ```php
  // Estados:
  - service()
  - good()
  - recurring()
  - withTaxGroup()
  - inactive()
  ```

**Tiempo estimado:** 2 horas  
**Dependencias:** Migraciones, Enums  
**Tests:** `tests/Unit/Models/ArticleTest.php` (objetivo: 100%)

---

### 2.2 Modelo ArticleOverride

- [ ] **Crear modelo** (`src/Models/ArticleOverride.php`)
  ```php
  class ArticleOverride extends Model {
      use HasFactory;
      
      // Casts
      protected $casts = [
          'custom_price' => Base100::class,
          'valid_from' => 'date',
          'valid_to' => 'date',
          'is_active' => 'boolean',
      ];
      
      // Relationships
      public function article(): BelongsTo;
      public function customer(): BelongsTo; // Polimórfico vía config
      
      // Scopes
      public function scopeActive(Builder $query): void;
      public function scopeValidAt(Builder $query, Carbon $date): void;
      public function scopeForCustomer(Builder $query, int $customerId): void;
      
      // Helpers
      public function isValidAt(Carbon $date): bool;
      public function isExpired(): bool;
      public function daysUntilExpiration(): ?int;
  }
  ```

- [ ] **Crear factory** (`src/database/factories/ArticleOverrideFactory.php`)

**Tiempo estimado:** 1.5 horas  
**Dependencias:** Article model  
**Tests:** `tests/Unit/Models/ArticleOverrideTest.php` (objetivo: 100%)

---

### 2.3 Modelo ArticleServiceStatus

- [ ] **Crear modelo** (`src/Models/ArticleServiceStatus.php`)
  ```php
  class ArticleServiceStatus extends Model {
      use HasFactory;
      
      // Casts
      protected $casts = [
          'status' => ServiceStatus::class,
          'cancellation_type' => CancellationType::class,
          'started_at' => 'date',
          'next_billing_date' => 'date',
          'expires_at' => 'date',
          'cancellation_requested_at' => 'date',
          'cancellation_effective_at' => 'date',
          'refund_unused' => 'boolean',
          'effective_price' => Base100::class,
          'instance_data' => 'array',
          'metadata' => 'array',
      ];
      
      // Relationships
      public function article(): BelongsTo;
      public function customer(): BelongsTo;
      public function currentOverride(): BelongsTo;
      
      // Scopes
      public function scopeActive(Builder $query): void;
      public function scopePendingBilling(Builder $query): void;
      public function scopeDueForBilling(Builder $query, ?Carbon $date = null): void;
      public function scopeForCustomer(Builder $query, int $customerId): void;
      public function scopeForArticle(Builder $query, int $articleId): void;
      
      // Lifecycle methods
      public function activate(): void;
      public function suspend(string $reason): void;
      public function cancel(CancellationType $type, ?string $reason = null): void;
      public function expire(): void;
      
      // Billing helpers
      public function calculateNextBillingDate(): Carbon;
      public function updateEffectivePrice(): void;
      public function shouldBeBilled(): bool;
      public function getDaysUntilNextBilling(): int;
      
      // Instance helpers
      public function getInstanceData(string $key, mixed $default = null): mixed;
      public function setInstanceData(string $key, mixed $value): void;
  }
  ```

- [ ] **Crear factory** (`src/database/factories/ArticleServiceStatusFactory.php`)
  ```php
  // Estados:
  - active()
  - pending()
  - suspended()
  - cancelled()
  - expired()
  - dueForBilling()
  - withOverride()
  ```

**Tiempo estimado:** 3 horas  
**Dependencias:** Article, ArticleOverride models  
**Tests:** `tests/Unit/Models/ArticleServiceStatusTest.php` (objetivo: 100%)

---

## 📦 FASE 3: DTOs y Servicios

### 3.1 DTOs para Metadata

- [ ] **SourceReference** (`src/DataTransferObjects/SourceReference.php`)
  ```php
  final class SourceReference {
      public function __construct(
          public readonly string $type,
          public readonly ?int $articleId,
          public readonly ?int $serviceStatusId,
          public readonly ?string $instanceIdentifier,
      ) {}
      
      public static function fromArticle(Article $article): self;
      public static function fromService(ArticleServiceStatus $service): self;
      public static function fromArray(array $data): self;
      public function toArray(): array;
  }
  ```

- [ ] **PricingDetails** (`src/DataTransferObjects/PricingDetails.php`)
  ```php
  final class PricingDetails {
      public function __construct(
          public readonly int $basePrice,
          public readonly int $appliedPrice,
          public readonly ?string $pricingRule,
          public readonly ?int $discountPercentage,
          public readonly ?int $overrideId,
      ) {}
      
      public static function fromArticle(Article $article, ?ArticleOverride $override): self;
      public static function fromArray(array $data): self;
      public function toArray(): array;
      public function hasDiscount(): bool;
  }
  ```

- [ ] **BillingDetails** (`src/DataTransferObjects/BillingDetails.php`)
  ```php
  final class BillingDetails {
      public function __construct(
          public readonly ?BillingFrequency $billingCycle,
          public readonly ?Carbon $periodStart,
          public readonly ?Carbon $periodEnd,
          public readonly ?int $billingInterval,
      ) {}
      
      public static function fromService(ArticleServiceStatus $service): self;
      public static function fromArray(array $data): self;
      public function toArray(): array;
      public function getDurationInDays(): ?int;
  }
  ```

- [ ] **InvoiceItemMetadata** (`src/DataTransferObjects/InvoiceItemMetadata.php`)
  ```php
  final class InvoiceItemMetadata {
      public function __construct(
          public readonly ?SourceReference $sourceReference,
          public readonly ?PricingDetails $pricingDetails,
          public readonly ?BillingDetails $billingDetails,
          public readonly ?CustomData $customData,
          public readonly array $auditTrail,
      ) {}
      
      public static function fromArray(array $data): self;
      public function toArray(): array;
      public function addAuditEntry(AuditEntry $entry): self;
  }
  ```

- [ ] **AuditEntry** (`src/DataTransferObjects/AuditEntry.php`)
  ```php
  final class AuditEntry {
      public function __construct(
          public readonly string $action,
          public readonly Carbon $timestamp,
          public readonly ?int $userId,
          public readonly ?string $field,
          public readonly mixed $oldValue,
          public readonly mixed $newValue,
          public readonly ?string $reason,
      ) {}
      
      public static function forCreation(?int $userId, ?string $reason): self;
      public static function forModification(string $field, mixed $old, mixed $new, ?string $reason): self;
      public static function fromArray(array $data): self;
      public function toArray(): array;
  }
  ```

- [ ] **CustomData** (`src/DataTransferObjects/CustomData.php`)
  ```php
  final class CustomData {
      public function __construct(
          public readonly array $data,
      ) {}
      
      public static function fromArray(array $data): self;
      public function toArray(): array;
      public function get(string $key, mixed $default = null): mixed;
      public function has(string $key): bool;
  }
  ```

**Tiempo estimado:** 3 horas  
**Dependencias:** Enums  
**Tests:** `tests/Unit/DataTransferObjects/` (objetivo: 100%)

---

### 3.2 Servicios

- [ ] **PricingService** (`src/Services/PricingService.php`)
  ```php
  class PricingService {
      public function getEffectivePrice(
          Article $article, 
          int $customerId
      ): int;
      
      public function getActiveOverride(
          Article $article, 
          int $customerId
      ): ?ArticleOverride;
      
      public function calculateDiscount(
          int $basePrice, 
          int $customPrice
      ): int;
      
      public function applyOverride(
          Article $article, 
          int $customerId, 
          int $customPrice, 
          ?string $reason = null,
          ?Carbon $validFrom = null,
          ?Carbon $validTo = null
      ): ArticleOverride;
  }
  ```

- [ ] **ArticleTemplateService** (`src/Services/ArticleTemplateService.php`)
  ```php
  class ArticleTemplateService {
      public function createFromArticle(
          Article $article,
          int $customerId,
          ?string $instanceIdentifier = null,
          ?array $instanceData = null
      ): array; // Returns InvoiceItem data array
      
      public function hydrateInvoiceItem(
          InvoiceItem $item,
          Article $article,
          ?ArticleServiceStatus $service = null,
          ?ArticleOverride $override = null
      ): void;
  }
  ```

- [x] **RecurringBillingService** ✅ (`src/Services/RecurringBillingService.php`)
  ```php
  class RecurringBillingService {
      // ✅ IMPLEMENTADO Y TESTEADO (14 tests passing)
      // Mejoras implementadas:
      // - Invoice numbering con fiscal_year
      // - Date handling con addMonthsNoOverflow()
      // - Event dispatching completo
      // - Window logic con buffer de 30 días
      // - article-specific days_in_advance
      
      public function processRecurringBilling(Carbon $date, bool $dryRun = false): array;
      protected function getServicesInBillingWindow(Carbon $date): Collection;
      protected function shouldGenerateInvoice(ArticleServiceStatus $service, Carbon $date): bool;
      protected function createInvoiceForService(ArticleServiceStatus $service, Carbon $date): Invoice;
      protected function calculateNextBillingDate(ArticleServiceStatus $service): Carbon;
      protected function calculatePeriodEnd(ArticleServiceStatus $service): Carbon;
      protected function updateNextBillingDate(ArticleServiceStatus $service): void;
      protected function generateInvoiceNumber(): array; // fiscal_number, prefix, serie, series_number, fiscal_year
  }
  ```

- [ ] **ServiceLifecycleService** (`src/Services/ServiceLifecycleService.php`)
  ```php
  class ServiceLifecycleService {
      public function activate(ArticleServiceStatus $service): void;
      
      public function suspend(ArticleServiceStatus $service, string $reason): void;
      
      public function cancel(
          ArticleServiceStatus $service, 
          CancellationType $type, 
          ?string $reason = null
      ): void;
      
      public function expire(ArticleServiceStatus $service): void;
      
      public function calculateRefund(ArticleServiceStatus $service): ?int;
      
      public function processExpiredServices(): int; // Returns count
      
      public function processPendingCancellations(): int;
  }
  ```

**Tiempo estimado:** 6 horas  
**Dependencias:** Modelos, DTOs  
**Tests:** `tests/Unit/Services/` (objetivo: 100%)

---

## 📦 FASE 4: Lógica de Negocio

### 4.1 Actions (Laravel Actions) ✅

- [x] **ProcessRecurringBilling** ✅ (`src/Actions/ProcessRecurringBilling.php`)
  ```php
  class ProcessRecurringBilling {
      use AsAction; // Laravel Actions pattern
      
      // ✅ IMPLEMENTADO (3 tests passing)
      // Soporta múltiples contextos:
      // - Command: php artisan larabill:process-recurring
      // - Job: ProcessRecurringBilling::dispatch()
      // - Direct: ProcessRecurringBilling::run()
      
      public function handle(?Carbon $date = null, bool $dryRun = false): array;
      public function asCommand(Command $command): void; // --date --dry-run
      public function getCommandSignature(): string;
      public function getCommandDescription(): string;
  }
  ```
  
  **Tests:** `tests/Unit/Actions/ProcessRecurringBillingTest.php` (3/3 passing)
  - ✅ Direct call execution
  - ✅ Custom date parameter
  - ✅ Dry-run mode

**Nota:** Usando **Laravel Actions** en vez de Jobs separados. Más flexible y moderno.

- [ ] **ProcessServiceExpiration** (`src/Jobs/ProcessServiceExpiration.php`)
  ```php
  class ProcessServiceExpiration implements ShouldQueue {
      public function handle(ServiceLifecycleService $service): void;
  }
  ```

- [ ] **ProcessPendingCancellations** (`src/Jobs/ProcessPendingCancellations.php`)
  ```php
  class ProcessPendingCancellations implements ShouldQueue {
      public function handle(ServiceLifecycleService $service): void;
  }
  ```

**Tiempo estimado:** 2 horas  
**Dependencias:** Servicios  
**Tests:** `tests/Unit/Jobs/` (objetivo: 100%)

---

### 4.2 Commands

- [ ] **ProcessRecurringBillingCommand** (`src/Console/Commands/ProcessRecurringBillingCommand.php`)
  ```php
  class ProcessRecurringBillingCommand extends Command {
      protected $signature = 'larabill:bill-recurring {--date=}';
      
      public function handle(RecurringBillingService $service): int;
  }
  ```

- [ ] **ProcessServiceLifecycleCommand** (`src/Console/Commands/ProcessServiceLifecycleCommand.php`)
  ```php
  class ProcessServiceLifecycleCommand extends Command {
      protected $signature = 'larabill:process-services';
      
      public function handle(ServiceLifecycleService $service): int;
  }
  ```

**Tiempo estimado:** 1 hora  
**Dependencias:** Jobs, Servicios  
**Tests:** `tests/Feature/Console/` (objetivo: 100%)

---

### 4.3 Events & Listeners ✅ **COMPLETADO**

#### Events Implementados
- [x] **RecurringInvoiceGenerated** (`src/Events/RecurringInvoiceGenerated.php`)
  - Dispatched: Cuando se genera una factura recurrente exitosamente
  - Payload: `Invoice $invoice`, `ArticleServiceStatus $service`
  
- [x] **RecurringBillingCompleted** (`src/Events/RecurringBillingCompleted.php`)
  - Dispatched: Al finalizar el proceso de facturación recurrente
  - Payload: `Carbon $billingDate`, `array $results`, `bool $dryRun`
  
- [x] **RecurringBillingFailed** (`src/Events/RecurringBillingFailed.php`)
  - Dispatched: Cuando falla la facturación de un servicio específico
  - Payload: `ArticleServiceStatus $service`, `Exception $exception`, `array $context`

#### Listeners Implementados
- [x] **SendInvoiceNotification** (`src/Listeners/SendInvoiceNotification.php`)
  - Escucha: `RecurringInvoiceGenerated`
  - Acción: Envía notificación de factura al cliente (ShouldQueue)
  - TODO: Implementar lógica de envío de emails
  
- [x] **LogBillingSummary** (`src/Listeners/LogBillingSummary.php`)
  - Escucha: `RecurringBillingCompleted`
  - Acción: Registra resumen del proceso de facturación
  - Nivel: Info (si OK) / Error (si hay fallos)
  
- [x] **AlertBillingFailure** (`src/Listeners/AlertBillingFailure.php`)
  - Escucha: `RecurringBillingFailed`
  - Acción: Alerta sobre fallos en facturación
  - TODO: Implementar notificación a admins

#### Service Provider
- [x] Eventos registrados en `LarabillServiceProvider`
- [x] Listeners mapeados en `$listen` array
- [x] Método `registerEventListeners()` implementado

#### Tests
- [x] Events & Listeners integrados en `RecurringBillingServiceTest.php`
- [x] `ProcessRecurringBillingTest.php` (3 tests)
- [x] **100% tests passing (842 total)**

#### Pendientes para futuras fases
- [ ] **ServiceActivated** event (Fase 4.4)
- [ ] **ServiceSuspended** event (Fase 4.4)
- [ ] **ServiceCancelled** event (Fase 4.4)
- [ ] **ServiceExpired** event (Fase 4.4)

**Tiempo real:** 3 horas  
**Tests:** ✅ 100% passing (842 tests)  
**Commit:** `3432967` - feat: add Events & Listeners for recurring billing lifecycle

---

## 📦 FASE 5: Testing

### 5.1 Tests Unitarios

- [ ] `tests/Unit/Enums/BillingFrequencyTest.php`
- [ ] `tests/Unit/Enums/ServiceStatusTest.php`
- [ ] `tests/Unit/Enums/CancellationTypeTest.php`
- [ ] `tests/Unit/Models/ArticleTest.php`
- [ ] `tests/Unit/Models/ArticleOverrideTest.php`
- [ ] `tests/Unit/Models/ArticleServiceStatusTest.php`
- [ ] `tests/Unit/DataTransferObjects/SourceReferenceTest.php`
- [ ] `tests/Unit/DataTransferObjects/PricingDetailsTest.php`
- [ ] `tests/Unit/DataTransferObjects/BillingDetailsTest.php`
- [ ] `tests/Unit/DataTransferObjects/InvoiceItemMetadataTest.php`
- [ ] `tests/Unit/DataTransferObjects/AuditEntryTest.php`
- [ ] `tests/Unit/Services/PricingServiceTest.php`
- [ ] `tests/Unit/Services/ArticleTemplateServiceTest.php`
- [x] `tests/Unit/Services/RecurringBillingServiceTest.php` ✅ (14/14 passing)
- [ ] `tests/Unit/Services/ServiceLifecycleServiceTest.php`
- [x] `tests/Unit/Actions/ProcessRecurringBillingTest.php` ✅ (3/3 passing)
- [ ] `tests/Unit/Jobs/ProcessServiceExpirationTest.php`

**Objetivo:** Cobertura > 95% en todos los archivos  
**Tiempo estimado:** 12 horas

---

### 5.2 Tests de Integración

- [ ] **`tests/Feature/Articles/CreateArticleTest.php`**
  - Crear artículo Good
  - Crear artículo Service
  - Crear artículo recurrente
  - Validaciones

- [ ] **`tests/Feature/Articles/ArticlePricingTest.php`**
  - Precio base
  - Precio con override
  - Override expirado
  - Múltiples overrides

- [ ] **`tests/Feature/Articles/ServiceLifecycleTest.php`**
  - Contratar servicio
  - Activar servicio
  - Suspender servicio
  - Cancelar servicio (3 tipos)
  - Expirar servicio

- [ ] **`tests/Feature/Articles/RecurringBillingTest.php`**
  - Facturación mensual
  - Facturación trimestral
  - Facturación anual
  - Múltiples instancias
  - Con override
  - Respeto de fechas (no desfases)

- [ ] **`tests/Feature/Articles/MultipleInstancesTest.php`**
  - 3 hostings mismo cliente
  - Dominios múltiples
  - Pricing diferenciado por instancia

- [ ] **`tests/Feature/Articles/InvoiceItemMetadataTest.php`**
  - Metadata completo en factura
  - Source reference
  - Pricing details
  - Billing details
  - Audit trail

**Tiempo estimado:** 8 horas

---

### 5.3 Tests de Regresión

- [ ] Verificar que tests existentes siguen pasando
- [ ] Actualizar tests de InvoiceItem si necesario
- [ ] Verificar integración con TaxCalculationService

**Tiempo estimado:** 2 horas

---

## 📦 FASE 6: Documentación

### 6.1 Documentación de Código

- [ ] PHPDoc completo en todos los modelos
- [ ] PHPDoc completo en todos los servicios
- [ ] Comentarios inline donde sea necesario

**Tiempo estimado:** 2 horas

---

### 6.2 Guías de Usuario

- [ ] **`docs/ARTICLES_GUIDE.md`**
  - Crear artículos
  - Configurar recurrencia
  - Metadata y configuración avanzada

- [ ] **`docs/PRICING_GUIDE.md`**
  - Sistema de overrides
  - Pricing por cliente
  - Validez temporal

- [ ] **`docs/SERVICES_GUIDE.md`**
  - Contratar servicios
  - Ciclo de vida
  - Instancias múltiples
  - Cancelaciones

- [ ] **`docs/RECURRING_BILLING_GUIDE.md`**
  - Configurar job
  - Cron setup
  - Monitoreo
  - Troubleshooting

**Tiempo estimado:** 4 horas

---

### 6.3 Ejemplos

- [ ] **`docs/examples/create-hosting-article.md`**
- [ ] **`docs/examples/setup-customer-pricing.md`**
- [ ] **`docs/examples/contract-recurring-service.md`**
- [ ] **`docs/examples/manual-billing.md`**

**Tiempo estimado:** 2 horas

---

## 📊 Resumen de Tiempos

| Fase | Descripción | Tiempo Estimado |
|------|-------------|-----------------|
| 1 | Fundamentos (Enums + Migraciones) | 3-5 horas |
| 2 | Modelos Core | 6.5 horas |
| 3 | DTOs y Servicios | 9 horas |
| 4 | Lógica de Negocio | 5 horas |
| 5 | Testing | 22 horas |
| 6 | Documentación | 8 horas |
| **TOTAL** | **53.5-55.5 horas** | ~**7 días hábiles** |

---

## 🎯 Criterios de Aceptación

### Funcionales
- ✅ Crear artículos (Goods y Services)
- ✅ Crear servicios recurrentes con instancias
- ✅ Sistema de pricing con overrides
- ✅ Facturación automática de servicios
- ✅ Facturación manual
- ✅ Ciclo de vida completo (activar, suspender, cancelar, expirar)
- ✅ Metadata completo en facturas

### Técnicos
- ✅ Cobertura de tests > 90%
- ✅ 0 errores de linter
- ✅ 0 warnings de PHPStan
- ✅ Tipado estricto en todos los archivos
- ✅ DTOs inmutables
- ✅ Cálculo temporal correcto (addMonths/addYears)

### Documentación
- ✅ Arquitectura documentada (✅ HECHO)
- ✅ Guías de usuario completas
- ✅ Ejemplos funcionales
- ✅ PHPDoc completo

---

## 🚦 Orden de Ejecución Recomendado

1. **Día 1:** Fase 1 completa (Enums + Migraciones)
2. **Día 2:** Fase 2 completa (Modelos + Factories)
3. **Día 3:** Fase 3.1 (DTOs)
4. **Día 4:** Fase 3.2 (Servicios)
5. **Día 5:** Fase 4 (Jobs, Commands, Events)
6. **Día 6:** Fase 5 (Testing completo)
7. **Día 7:** Fase 6 (Documentación) + Revisión final

---

## ⚠️ Riesgos y Mitigaciones

| Riesgo | Impacto | Mitigación |
|--------|---------|------------|
| Incompatibilidad con tests existentes | Alto | Ejecutar suite completa tras cada fase |
| Cálculo temporal incorrecto | Crítico | Tests específicos para cada mes del año |
| Metadata mal estructurado | Medio | Uso obligatorio de DTOs |
| Performance en facturación masiva | Medio | Chunk queries, queue jobs |

---

## 📝 Notas de Implementación

### Prioridades Críticas

1. **Cálculo temporal:** SIEMPRE `addMonths()/addYears()`, NUNCA `addDays()`
2. **Service dates:** OBLIGATORIOS para servicios recurrentes en facturas
3. **Estado del servicio:** Validar antes de facturar
4. **Metadata:** Usar DTOs, nunca arrays crudos
5. **Tipos optimizados:** `unsignedSmallInteger` y `unsignedTinyInteger` donde aplique

### Git Commits Sugeridos

- `feat: add billing frequency and service status enums`
- `feat: add articles table migration`
- `feat: add article overrides migration`
- `feat: add article service status migration`
- `feat: implement Article model with relationships`
- `feat: implement ArticleOverride model`
- `feat: implement ArticleServiceStatus model`
- `feat: add invoice item metadata DTOs`
- `feat: implement PricingService`
- `feat: implement RecurringBillingService`
- `feat: add recurring billing job`
- `test: add article model tests`
- `test: add recurring billing integration tests`
- `docs: add articles and services guides`

---

**Última actualización:** 2024-10-19  
**Responsable:** Equipo Larabill  
**Estado:** ✅ Listo para iniciar

