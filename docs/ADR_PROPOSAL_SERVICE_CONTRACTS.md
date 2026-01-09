# ADR Proposal: Service Contracts / Subscriptions

**Status**: PROPOSAL (pendiente revision)

**Fecha**: 2026-01-02

**Autor**: Claude Code (propuesta inicial)

**Revisor**: Pendiente

---

## Contexto

Larabill maneja facturacion pero carece de un modelo para representar la relacion contractual entre un usuario y un producto/servicio facturable de forma recurrente.

Actualmente, las facturas se generan sin contexto de:

- Ciclo de facturacion (mensual, trimestral, anual)
- Fecha de inicio/fin del servicio
- Renovacion automatica
- Estado del servicio (activo, suspendido, cancelado)

Esto dificulta:

1. Generacion automatica de facturas recurrentes
2. Gestion de renovaciones
3. Historico de servicios contratados por usuario
4. Cancelaciones y bajas

## Decision Propuesta

Crear modelo `ServiceContract` en larabill como solucion agnostica para cualquier tipo de servicio o bien facturable con periodicidad.

## Modelo Propuesto

### ServiceContract

```php
Schema::create('service_contracts', function (Blueprint $table) {
    $table->uuid('id')->primary();

    // Relaciones
    $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('article_id')->constrained()->cascadeOnDelete();

    // Configuracion de ciclo
    $table->unsignedTinyInteger('billing_cycle'); // Enum: monthly=1, quarterly=2, yearly=3, biennial=4, custom=99
    $table->unsignedSmallInteger('custom_cycle_days')->nullable(); // Solo si cycle=custom

    // Fechas
    $table->date('start_date');
    $table->date('end_date')->nullable(); // null = renovacion indefinida
    $table->date('next_billing_date');
    $table->date('last_billed_date')->nullable();

    // Estado
    $table->unsignedTinyInteger('status')->default(1); // Enum: pending=0, active=1, suspended=2, cancelled=3, expired=4

    // Renovacion
    $table->boolean('auto_renew')->default(true);
    $table->unsignedTinyInteger('renewal_reminder_days')->default(30);

    // Cancelacion
    $table->timestamp('cancelled_at')->nullable();
    $table->string('cancellation_reason')->nullable();
    $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users');

    // Precios (override del articulo si aplica)
    $table->unsignedBigInteger('unit_price_override')->nullable(); // Base 100
    $table->unsignedBigInteger('discount_percent')->nullable(); // Base 100 (ej: 1000 = 10%)

    // Metadata
    $table->json('metadata')->nullable(); // Datos especificos del dominio (ej: dominio, IP, etc)

    // Audit
    $table->timestamps();
    $table->softDeletes();

    // Indices
    $table->index(['user_id', 'status']);
    $table->index('next_billing_date');
    $table->index('status');
});
```

### Enum BillingCycle

```php
enum BillingCycle: int
{
    case MONTHLY    = 1;   // Cada mes
    case QUARTERLY  = 2;   // Cada 3 meses
    case BIANNUAL   = 3;   // Cada 6 meses
    case YEARLY     = 4;   // Cada 12 meses
    case BIENNIAL   = 5;   // Cada 24 meses
    case TRIENNIAL  = 6;   // Cada 36 meses
    case CUSTOM     = 99;  // custom_cycle_days define el periodo

    public function days(): int
    {
        return match ($this) {
            self::MONTHLY   => 30,
            self::QUARTERLY => 90,
            self::BIANNUAL  => 180,
            self::YEARLY    => 365,
            self::BIENNIAL  => 730,
            self::TRIENNIAL => 1095,
            self::CUSTOM    => 0, // Usar custom_cycle_days
        };
    }
}
```

### Enum ContractStatus

```php
enum ContractStatus: int
{
    case PENDING   = 0;  // Creado, pendiente de primer pago
    case ACTIVE    = 1;  // Activo y al dia
    case SUSPENDED = 2;  // Suspendido por impago u otra razon
    case CANCELLED = 3;  // Cancelado por usuario o admin
    case EXPIRED   = 4;  // Finalizado por fecha fin

    public function canBeBilled(): bool
    {
        return $this === self::ACTIVE;
    }

    public function canBeReactivated(): bool
    {
        return in_array($this, [self::SUSPENDED, self::CANCELLED]);
    }
}
```

## Relaciones

```
User (1) -----> (*) ServiceContract
Article (1) --> (*) ServiceContract
ServiceContract (1) --> (*) InvoiceItem (via contract_id?)
```

### Opcion: Vincular facturas a contratos

Añadir a `invoice_items`:

```php
$table->foreignUuid('service_contract_id')->nullable()->constrained();
```

Esto permite:

- Saber que items de factura vienen de que contrato
- Historico de facturacion por contrato
- Calcular revenue por contrato

## Servicios Propuestos

### ContractBillingService

```php
class ContractBillingService
{
    // Generar facturas para contratos que toca facturar
    public function generateDueInvoices(Carbon $date = null): Collection;

    // Facturar un contrato especifico
    public function billContract(ServiceContract $contract): Invoice;

    // Obtener contratos pendientes de facturar
    public function getDueContracts(Carbon $date = null): Collection;

    // Renovar contrato (extender next_billing_date)
    public function renewContract(ServiceContract $contract): void;

    // Cancelar contrato
    public function cancelContract(ServiceContract $contract, string $reason, User $cancelledBy): void;

    // Suspender por impago
    public function suspendContract(ServiceContract $contract, string $reason): void;

    // Reactivar contrato suspendido/cancelado
    public function reactivateContract(ServiceContract $contract): void;
}
```

### Job: ProcessContractBilling

```php
// Ejecutar diariamente
class ProcessContractBilling implements ShouldQueue
{
    public function handle(ContractBillingService $service): void
    {
        $invoices = $service->generateDueInvoices();

        foreach ($invoices as $invoice) {
            // Notificar, enviar por email, etc.
            event(new InvoiceGenerated($invoice));
        }
    }
}
```

## Casos de Uso

### 1. Hosting (larafactu)

```php
$contract = ServiceContract::create([
    'user_id' => $user->id,
    'article_id' => $hostingPlan->id,
    'billing_cycle' => BillingCycle::YEARLY,
    'start_date' => now(),
    'auto_renew' => true,
    'metadata' => [
        'domain' => 'example.com',
        'disk_quota' => '10GB',
    ],
]);
```

### 2. SaaS generico

```php
$contract = ServiceContract::create([
    'user_id' => $user->id,
    'article_id' => $saasProPlan->id,
    'billing_cycle' => BillingCycle::MONTHLY,
    'start_date' => now(),
    'metadata' => [
        'seats' => 5,
        'features' => ['api', 'support'],
    ],
]);
```

### 3. Alquiler de equipo (bienes)

```php
$contract = ServiceContract::create([
    'user_id' => $user->id,
    'article_id' => $serverRental->id,
    'billing_cycle' => BillingCycle::MONTHLY,
    'start_date' => now(),
    'end_date' => now()->addYear(), // Contrato a 1 ano
    'auto_renew' => false,
    'metadata' => [
        'serial_number' => 'SRV-12345',
        'location' => 'DC-Madrid',
    ],
]);
```

## Migracion desde Larafactu

Si larafactu tiene datos de "planes" o "servicios" actuales:

1. Crear migracion que lea datos existentes
2. Generar ServiceContract por cada servicio activo
3. Vincular facturas historicas via `service_contract_id`

## Configuracion

```php
// config/larabill.php
'contracts' => [
    'enabled' => true,
    'auto_billing' => [
        'enabled' => true,
        'days_before' => 0, // 0 = dia exacto, -7 = 7 dias antes
        'grace_period_days' => 7, // Dias antes de suspender por impago
    ],
    'notifications' => [
        'renewal_reminder' => true,
        'invoice_generated' => true,
        'payment_overdue' => true,
        'contract_suspended' => true,
    ],
],
```

## Alternativas Consideradas

### 1. Modelo en aplicacion (larafactu)

**Pros**: Especifico para hosting
**Contras**: No reutilizable, duplicacion si otro proyecto necesita lo mismo

### 2. Paquete separado (laracontracts)

**Pros**: Totalmente desacoplado
**Contras**: Mas dependencias, mas complejidad

### 3. Usar paquete existente (laravel-cashier, etc)

**Pros**: Probado, mantenido
**Contras**: Acoplado a Stripe/payment gateways, no agnostico

## Consecuencias

### Positivas

- Modelo unificado para cualquier servicio recurrente
- Automatizacion de facturacion
- Historico completo por contrato
- Agnostico: hosting, SaaS, alquiler, consultoria...

### Negativas

- Complejidad añadida a larabill
- Migracion necesaria para proyectos existentes
- Overhead si solo se necesita facturacion simple

### Neutras

- Campo `metadata` JSON permite flexibilidad pero sin tipado fuerte
- Requiere job programado para facturacion automatica

## Plan de Implementacion (si se aprueba)

1. [ ] Crear migracion para `service_contracts`
2. [ ] Crear enums `BillingCycle` y `ContractStatus`
3. [ ] Crear modelo `ServiceContract`
4. [ ] Crear `ContractBillingService`
5. [ ] Crear job `ProcessContractBilling`
6. [ ] Añadir `service_contract_id` a `invoice_items`
7. [ ] Documentar API
8. [ ] Tests unitarios y feature
9. [ ] Publicar migracion opcional

---

## Referencias

- ADR-003: User/Customer Unification
- ADR-004: Authorization System
- Laravel Cashier (inspiracion, no dependencia)

---

**Pendiente**: Revision y aprobacion antes de implementacion.
