# 📦 Larabill: Arquitectura de Artículos y Facturación

> **Documento de Arquitectura v1.1**
> Sistema agnóstico de facturación para servicios digitales y productos tangibles

---

> ⚠️ **NOTA ADR-004 (Diciembre 2024)**: La arquitectura de precios ha sido refactorizada.
>
> **Cambios principales**:
>
> - `Article.base_price` → Movido a `ArticlePrice.price`
> - `Article.billing_frequency` → Movido a `ArticlePrice.billing_frequency`
> - Un Article ahora puede tener múltiples precios por frecuencia
> - `ArticleServiceStatus.billing_frequency` almacena la frecuencia contratada (inmutable)
>
> Ver [ADR-004-article-price-by-frequency.md](./ADR-004-article-price-by-frequency.md) para detalles completos.

---

## 📋 Tabla de Contenidos

1. [Contexto y Principios](#contexto-y-principios)
2. [Modelo de Dominio](#modelo-de-dominio)
3. [Modelo de Datos (DBML)](#modelo-de-datos-dbml)
4. [Diagrama Conceptual](#diagrama-conceptual)
5. [Flujos de Proceso](#flujos-de-proceso)
6. [Casos de Uso Detallados](#casos-de-uso-detallados)
7. [Decisiones de Diseño](#decisiones-de-diseño)

---

## 🎯 Contexto y Principios

### Requisitos del Sistema

- ✅ **Servicios digitales recurrentes** (principal)
- ✅ **Productos tangibles ocasionales** (secundario)
- ✅ **Sin stock interno** (gestión externa vía jobs si necesario)
- ✅ **Sin frontend** (solo lógica + API)
- ✅ **Auditoría completa** (metadata + DTOs)
- ✅ **Agnosticismo total** (usuario decide UI y modelos)
- ✅ **Pricing diferenciado por cliente**
- ✅ **Facturación manual y automática**
- ✅ **Estado del ciclo de vida** para servicios recurrentes
- ✅ **Fechas de servicio obligatorias** para líneas fiscales recurrentes
- ✅ **Cálculo temporal preciso** usando meses/años (no días)

### Principios SOLID Aplicados

| Principio | Implementación |
|-----------|----------------|
| **Single Responsibility** | `Article` = catálogo<br>`ArticleOverride` = pricing<br>`ArticleServiceStatus` = estado |
| **Open/Closed** | Extensible vía `subscription_type`, `instance_data`, `metadata` |
| **Liskov Substitution** | Cualquier `Article` puede usarse sin conocer su tipo |
| **Interface Segregation** | Tablas separadas según responsabilidad |
| **Dependency Inversion** | Acople externo vía identificadores configurables |

### Decisiones Clave

1. ❌ **NO hay modelo "Subscription"** como entidad de dominio
2. ✅ **Recurrencia es característica del Article**, no entidad separada
3. ✅ **ArticleServiceStatus** es tabla técnica de control, no dominio
4. ✅ **Instancias de servicios** mediante `instance_identifier`
5. ✅ **Metadata con DTOs** para estructuras tipadas
6. ✅ **InvoiceItem como snapshot inmutable** sin FK a productos
7. ✅ **Estados del ciclo de vida** obligatorios para servicios recurrentes
8. ✅ **service_date_from/to** obligatorios en líneas fiscales de servicios
9. ✅ **Cálculo temporal con addMonths/addYears** para evitar desfases

---

## 🏗️ Modelo de Dominio

```
┌─────────────────────────────────────────────────────────────────┐
│                        DOMINIO CORE                             │
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │                     Article                                │ │
│  │              (Catálogo Maestro)                           │ │
│  │                                                           │ │
│  │  Describe QUÉ es (plantilla/tipo)                        │ │
│  │  ├─ item_type: 'G' (Good) o 'S' (Service)               │ │
│  │  ├─ base_price: precio estándar                          │ │
│  │  ├─ tax_group_id: impuestos aplicables                   │ │
│  │  │                                                        │ │
│  │  └─ Para Services:                                        │ │
│  │     ├─ is_recurring: bool                                │ │
│  │     ├─ billing_frequency: M/Q/Y/L                        │ │
│  │     └─ subscription_type: acople externo                 │ │
│  └───────────────────────────────────────────────────────────┘ │
│                           │                                     │
│         ┌─────────────────┴─────────────────┐                  │
│         │                                   │                  │
│         ▼                                   ▼                  │
│  ┌──────────────────┐             ┌──────────────────────┐    │
│  │ ArticleOverride  │             │ ArticleServiceStatus │    │
│  │  (Pricing)       │             │  (Control Estado)    │    │
│  │                  │             │                      │    │
│  │ customer_id      │             │ customer_id          │    │
│  │ article_id       │             │ article_id           │    │
│  │ custom_price     │             │ instance_identifier  │    │
│  │ valid_from/to    │             │ started_at           │    │
│  │                  │             │ next_billing_date    │    │
│  │ Define precio    │             │ status               │    │
│  │ especial para    │             │                      │    │
│  │ cliente          │             │ Controla CUÁNDO y    │    │
│  └──────────────────┘             │ QUÉ instancia está   │    │
│                                   │ activa               │    │
│                                   └──────────────────────┘    │
│                                            │                   │
│                                            │ genera            │
│                                            ▼                   │
│                                   ┌──────────────────────┐    │
│                                   │   InvoiceItem        │    │
│                                   │   (Snapshot)         │    │
│                                   │                      │    │
│                                   │ description: text    │    │
│                                   │ unit_price: capturado│    │
│                                   │ metadata: auditoría  │    │
│                                   │                      │    │
│                                   │ NO tiene FK a Article│    │
│                                   │ (inmutable)          │    │
│                                   └──────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

### Concepto de Instancias

```
Article (Plantilla)                ArticleServiceStatus (Instancia)
══════════════════════             ════════════════════════════════

Hosting Pro (€29/mes)          →   instance: example.com
                               →   instance: another-site.com
                               →   instance: third-site.com

Dominio .com (€12/año)         →   instance: myapp.com
                               →   instance: othersite.com

Alquiler Casa (€1500/mes)      →   instance: Calle Mayor 123
                               →   instance: Avenida Sol 456

Good: Tarjeta Gráfica          →   (NO tiene instancias, venta directa)
```

---

## 🗄️ Modelo de Datos (DBML)

```dbml
// ============================================
// CATÁLOGO DE ARTÍCULOS
// ============================================

Table articles {
  id bigint [pk, increment]
  
  // Identificación
  code varchar(50) [unique, not null]
  name varchar(255) [not null]
  description text
  
  // Clasificación
  item_type char(1) [not null, note: 'G=Good, S=Service']
  category varchar(100)
  
  // Pricing (Base100)
  base_price integer [not null, note: '€12.34 => 1234']
  cost_price integer
  
  // Recurrencia (solo Services)
  is_recurring boolean [default: false]
  billing_frequency char(1) [note: 'M=Monthly, Q=Quarterly, Y=Yearly, L=Lifetime']
  billing_interval tinyint [default: 1, note: 'cada X meses/años']
  
  // Integración externa
  subscription_type varchar(100) [note: 'stripe:price_xxx, cpanel:account, etc']
  
  // Relaciones
  tax_group_id smallint [note: 'unsigned']
  unit_measure_id tinyint [note: 'unsigned']
  
  // Estado
  is_active boolean [default: true]
  
  // Metadata JSON
  metadata json [note: '{
    "physical": {...},     // Para Goods
    "service": {           // Para Services
      "setup_fee": 5000,
      "requires_instance": true,
      "instance_type": "domain",
      "cancellation_policy": {...}
    }
  }']
  
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp
  
  Indexes {
    (code, is_active)
    (item_type, is_recurring)
  }
}

// ============================================
// PRICING POR CLIENTE
// ============================================

Table article_overrides {
  id bigint [pk, increment]
  
  // Relaciones
  customer_id bigint [not null]
  article_id bigint [not null, ref: > articles.id]
  
  // Override
  custom_price integer [not null, note: 'Base100']
  reason text
  
  // Vigencia
  valid_from date
  valid_to date
  
  // Estado
  is_active boolean [default: true]
  
  created_at timestamp
  updated_at timestamp
  
  Indexes {
    (customer_id, article_id, valid_from) [unique]
    (customer_id, is_active)
  }
}

// ============================================
// ESTADO DE SERVICIOS CONTRATADOS
// ============================================

Table article_service_status {
  id bigint [pk, increment]
  
  // Relaciones
  customer_id bigint [not null]
  article_id bigint [not null, ref: > articles.id]
  
  // INSTANCIA ESPECÍFICA
  instance_identifier varchar(255) [note: 'domain, address, license-key, etc']
  instance_name varchar(255) [note: 'Nombre amigable']
  
  // Control temporal
  started_at date [not null]
  next_billing_date date
  expires_at date
  
  // Estado
  status char(1) [not null, note: 'A=Active, P=Pending, S=Suspended, C=Cancelled, E=Expired']
  
  // 🔴 VALIDACIÓN CRÍTICA:
  // Todo Article con is_recurring=true DEBE tener al menos un
  // ArticleServiceStatus asociado para ser facturable automáticamente.
  // El estado controla el ciclo de vida del servicio.
  
  // Cancelación
  cancellation_type char(1) [note: 'I=Immediate, E=EndOfPeriod, N=NoticePeriod']
  cancellation_requested_at date
  cancellation_effective_at date
  refund_unused boolean [default: false]
  
  // Pricing efectivo (cache)
  effective_price integer [not null, note: 'Base100']
  current_override_id bigint [ref: > article_overrides.id]
  
  // Integración externa
  external_reference varchar(255) [note: 'ID en sistema externo']
  
  // Datos de la instancia (JSON)
  instance_data json [note: '{
    "hosting": {"domain": "...", "ip": "..."},
    "domain": {"nameservers": [...]},
    "property": {"address": "...", "m2": 120}
  }']
  
  metadata json
  
  created_at timestamp
  updated_at timestamp
  
  Indexes {
    (customer_id, article_id, instance_identifier) [unique, name: 'customer_article_instance_unique']
    (customer_id, status)
    (article_id, status)
    (next_billing_date)
    (instance_identifier)
  }
}

// ============================================
// FACTURAS (existente)
// ============================================

Table invoices {
  id binary(16) [pk, note: 'UUID']
  fiscal_number varchar(100) [unique]
  user_id bigint [not null]
  invoice_date date
  status char(1)
  // ... otros campos ...
  
  Indexes {
    (user_id, invoice_date)
  }
}

// ============================================
// ITEMS DE FACTURA (snapshot inmutable)
// ============================================

Table invoice_items {
  id bigint [pk, increment]
  invoice_id binary(16) [not null, ref: > invoices.id]
  
  // Snapshot (NO FK a articles)
  item_type char(1) [not null, note: 'G o S']
  description text [not null]
  internal_code varchar(100) [note: 'Referencia opcional']
  
  // Quantities & Pricing (Base100)
  quantity integer [not null]
  unit_measure_id bigint
  unit_price integer [not null]
  taxable_amount integer [not null]
  
  // Taxes (snapshot)
  total_tax_amount integer [not null]
  taxes_applied json [not null]
  total_amount integer [not null]
  
  // Service dates
  service_date_from date
  service_date_to date
  
  // 🔴 OBLIGATORIO para servicios recurrentes:
  // Las líneas fiscales de servicios recurrentes DEBEN tener
  // service_date_from y service_date_to para expresar el período
  // facturado de forma clara y legalmente correcta.
  
  // Metadata con auditoría (DTO)
  metadata json [note: '{
    "source_reference": {
      "type": "article_service",
      "article_id": 123,
      "service_status_id": 456,
      "instance_identifier": "example.com"
    },
    "pricing_details": {
      "base_price": 29.00,
      "applied_price": 24.00,
      "pricing_rule": "customer_override"
    },
    "billing_details": {
      "billing_cycle": "monthly",
      "period_start": "2024-01-01",
      "period_end": "2024-01-31"
    },
    "audit_trail": [...]
  }']
  
  created_at timestamp
  updated_at timestamp
  
  Indexes {
    (invoice_id)
    (item_type, internal_code)
  }
}

// ============================================
// RELACIONES ADICIONALES
// ============================================

Table tax_groups {
  id bigint [pk, increment]
  name varchar(255)
}

Table unit_measures {
  id bigint [pk, increment]
  code varchar(50)
  name varchar(255)
}

Ref: articles.tax_group_id > tax_groups.id
Ref: articles.unit_measure_id > unit_measures.id
```

---

## 📊 Diagrama Conceptual de Procesos

### Flujo 1: Contratación de Servicio Recurrente

```
┌─────────────┐
│   Cliente   │
│  Contrata   │
│  Hosting    │
└──────┬──────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 1. Buscar Article "Hosting Pro"                     │
│    - item_type: 'S'                                  │
│    - is_recurring: true                              │
│    - billing_frequency: 'M'                          │
│    - base_price: 2900 (€29)                          │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 2. Verificar ArticleOverride                         │
│    ¿Cliente tiene precio especial?                   │
│    customer_id + article_id                          │
│                                                       │
│    SÍ → custom_price: 2400 (€24)                    │
│    NO → base_price: 2900 (€29)                      │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 3. Crear ArticleServiceStatus                        │
│    - customer_id: 42                                 │
│    - article_id: 1                                   │
│    - instance_identifier: "example.com"              │
│    - started_at: hoy                                 │
│    - next_billing_date: hoy + 1 mes                  │
│    - status: 'A' (Active)                            │
│    - effective_price: 2400 o 2900                    │
│    - instance_data: {"hosting": {...}}               │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 4. Generar Primera Factura                          │
│                                                       │
│    InvoiceItem (Setup Fee si aplica):                │
│    - description: "Setup Fee - Hosting Pro"          │
│    - unit_price: 5000 (€50)                         │
│                                                       │
│    InvoiceItem (Primer período):                     │
│    - description: "Hosting Pro - example.com"        │
│    - unit_price: 2400                                │
│    - service_date_from: hoy                          │
│    - service_date_to: hoy + 30 días                  │
│    - metadata: {source, pricing, billing}            │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 5. Provisión (opcional)                             │
│    - Crear cuenta cPanel                             │
│    - Configurar DNS                                  │
│    - Actualizar external_reference                   │
└──────────────────────────────────────────────────────┘
```

### Flujo 2: Facturación Recurrente Automática

```
┌─────────────┐
│   CRON      │
│   Diario    │
└──────┬──────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ Job: ProcessRecurringBilling                         │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 1. Query ArticleServiceStatus                        │
│    WHERE status = 'A'                                │
│      AND next_billing_date <= HOY                    │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 2. Para cada servicio:                               │
│                                                       │
│    a) Calcular período                               │
│       periodStart = next_billing_date                │
│                                                       │
│       🔴 CRÍTICO: Usar addMonths/addYears            │
│       periodEnd = periodStart->copy()                │
│         ->addMonths($interval * $frequency)          │
│         ->subDay()                                   │
│                                                       │
│       ❌ NO usar addDays(30) → produce desfases      │
│       ✅ SÍ usar addMonth() → respeta calendario     │
│                                                       │
│    b) Verificar precio efectivo actual               │
│       (puede haber cambiado override)                │
│                                                       │
│    c) Crear Invoice                                  │
│                                                       │
│    d) Crear InvoiceItem                              │
│       - description: "Hosting - example.com - Feb"   │
│       - unit_price: effective_price                  │
│       - service_date_from: periodStart (OBLIGATORIO) │
│       - service_date_to: periodEnd (OBLIGATORIO)     │
│       - metadata completo                            │
│                                                       │
│    e) Actualizar ArticleServiceStatus                │
│       - next_billing_date = periodEnd->addDay()      │
│       - metadata.last_invoice_id                     │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 3. Notificar al cliente                             │
│    - Email con factura                               │
│    - Webhook si configurado                          │
└──────────────────────────────────────────────────────┘
```

### Flujo 3: Cancelación de Servicio

```
┌─────────────┐
│   Cliente   │
│  Solicita   │
│  Cancelar   │
└──────┬──────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 1. Seleccionar tipo de cancelación                  │
└──────┬───────────────────────────────────────────────┘
       │
       ├─── Tipo I (Immediate) ─────────────────────┐
       │                                             │
       │    - status: 'C'                            │
       │    - cancellation_effective_at: HOY         │
       │    - next_billing_date: null                │
       │    - refund_unused: false                   │
       │    - Desprovisionamiento inmediato          │
       │                                             │
       ├─── Tipo E (End of Period) ─────────────────┤
       │                                             │
       │    - status: sigue 'A'                      │
       │    - cancellation_effective_at:             │
       │      next_billing_date                      │
       │    - refund_unused: false                   │
       │    - Se cancela al fin del período          │
       │                                             │
       └─── Tipo N (Notice Period) ─────────────────┤
                                                     │
            - cancellation_effective_at:             │
              HOY + notice_days                      │
            - refund_unused: true                    │
            - Calcular días no consumidos            │
            - Generar nota de crédito                │
                                                     │
                                                     ▼
                            ┌───────────────────────────────┐
                            │ 2. Actualizar                 │
                            │    ArticleServiceStatus       │
                            └───────────────────────────────┘
                                                     │
                                                     ▼
                            ┌───────────────────────────────┐
                            │ 3. Desprovisionamiento        │
                            │    (si immediate o al fin)    │
                            │    - Eliminar cuenta          │
                            │    - Liberar recursos         │
                            └───────────────────────────────┘
```

### Flujo 4: Venta de Producto (Good)

```
┌─────────────┐
│   Cliente   │
│   Compra    │
│  Producto   │
└──────┬──────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 1. Buscar Article "Tarjeta Gráfica"                 │
│    - item_type: 'G'                                  │
│    - is_recurring: false                             │
│    - base_price: 179900 (€1,799)                    │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 2. Verificar override (si existe)                   │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 3. Crear Invoice directamente                       │
│    (NO se crea ArticleServiceStatus)                │
│                                                       │
│    InvoiceItem:                                      │
│    - item_type: 'G'                                  │
│    - description: "NVIDIA RTX 4090"                  │
│    - quantity: 1                                     │
│    - unit_price: 179900                              │
│    - metadata: {                                     │
│        source_reference: {                           │
│          type: "article",                            │
│          article_id: 5                               │
│        },                                            │
│        physical_item: true,                          │
│        sku: "NV-RTX4090"                            │
│      }                                               │
└──────┬───────────────────────────────────────────────┘
       │
       ▼
┌──────────────────────────────────────────────────────┐
│ 4. Job Externo (si aplica)                          │
│    dispatch(UpdateInventory($article_id, -1))       │
│                                                       │
│    - Reducir stock                                   │
│    - Notificar si stock bajo                         │
│    - Actualizar sistema externo                      │
└──────────────────────────────────────────────────────┘
```

---

## 💼 Casos de Uso Detallados

### Caso 1: Cliente con Múltiples Instancias del Mismo Servicio

**Escenario:** Cliente tiene 3 hostings del mismo plan

```php
$hostingPro = Article::where('code', 'HOST-PRO')->first();
// base_price: 2900 (€29/mes)

// Instancia 1: example.com
ArticleServiceStatus::create([
    'customer_id' => $customer->id,
    'article_id' => $hostingPro->id,
    'instance_identifier' => 'example.com',
    'instance_name' => 'Hosting para Example.com',
    'started_at' => today(),
    'next_billing_date' => today()->addMonth(),
    'status' => ServiceStatus::ACTIVE,
    'effective_price' => 2900,
    'instance_data' => [
        'hosting' => [
            'domain' => 'example.com',
            'cpanel_username' => 'examplecom',
            'disk_space_gb' => 10,
            'ip' => '192.168.1.1',
        ],
    ],
]);

// Instancia 2: another-site.com
ArticleServiceStatus::create([
    'customer_id' => $customer->id,
    'article_id' => $hostingPro->id, // MISMO article_id
    'instance_identifier' => 'another-site.com', // DIFERENTE instance
    'instance_name' => 'Hosting para Another Site',
    'started_at' => today(),
    'next_billing_date' => today()->addMonth(),
    'status' => ServiceStatus::ACTIVE,
    'effective_price' => 2900,
]);

// Instancia 3: third-site.com
ArticleServiceStatus::create([
    'customer_id' => $customer->id,
    'article_id' => $hostingPro->id, // MISMO article_id
    'instance_identifier' => 'third-site.com', // DIFERENTE instance
    'instance_name' => 'Hosting para Third Site',
    'started_at' => today(),
    'next_billing_date' => today()->addMonth(),
    'status' => ServiceStatus::ACTIVE,
    'effective_price' => 2900,
]);

// Resultado: 3 facturas mensuales independientes
// - "Hosting Pro - example.com - Febrero 2024"
// - "Hosting Pro - another-site.com - Febrero 2024"
// - "Hosting Pro - third-site.com - Febrero 2024"
```

### Caso 2: Cliente Premium con Descuento

**Escenario:** Cliente obtiene 20% descuento en todos sus hostings

```php
$hostingPro = Article::where('code', 'HOST-PRO')->first();
// base_price: 2900 (€29/mes)

// Crear override
$override = ArticleOverride::create([
    'customer_id' => $premiumCustomer->id,
    'article_id' => $hostingPro->id,
    'custom_price' => 2320, // €23.20 (20% desc)
    'reason' => 'Cliente premium - contrato anual',
    'valid_from' => today(),
    'valid_to' => today()->addYear(),
    'is_active' => true,
]);

// Al crear nueva instancia, se aplica override automáticamente
$service = ArticleServiceStatus::create([
    'customer_id' => $premiumCustomer->id,
    'article_id' => $hostingPro->id,
    'instance_identifier' => 'premium-site.com',
    'started_at' => today(),
    'next_billing_date' => today()->addMonth(),
    'status' => ServiceStatus::ACTIVE,
    'effective_price' => 2320, // Precio con override
    'current_override_id' => $override->id,
]);

// Factura mostrará:
// "Hosting Pro - premium-site.com - €23.20"
// metadata contendrá:
// {
//   "pricing_details": {
//     "base_price": 29.00,
//     "applied_price": 23.20,
//     "pricing_rule": "customer_override",
//     "discount_percentage": 20,
//     "pricing_override_id": 5
//   }
// }
```

### Caso 3: Dominio con Renovación Anual

```php
$domainCom = Article::where('code', 'DOMAIN-COM')->first();
// billing_frequency: 'Y' (Yearly)
// base_price: 1200 (€12/año)

$service = ArticleServiceStatus::create([
    'customer_id' => $customer->id,
    'article_id' => $domainCom->id,
    'instance_identifier' => 'myawesomeapp.com',
    'instance_name' => 'Dominio MyAwesomeApp',
    'started_at' => today(),
    'next_billing_date' => today()->addYear(),
    'expires_at' => today()->addYear(),
    'status' => ServiceStatus::ACTIVE,
    'effective_price' => 1200,
    'instance_data' => [
        'domain' => [
            'name' => 'myawesomeapp.com',
            'tld' => 'com',
            'registrar' => 'GoDaddy',
            'registrar_lock' => true,
            'auto_renew' => true,
            'nameservers' => [
                'ns1.cloudflare.com',
                'ns2.cloudflare.com',
            ],
        ],
    ],
    'external_reference' => 'godaddy_domain_123456',
]);

// Se facturará automáticamente dentro de 1 año
// Job verificará next_billing_date diariamente
```

### Caso 4: Cancelación con Reembolso Proporcional

```php
$service = ArticleServiceStatus::find(1);
// Cliente cancela el día 10 de un mes facturado entero

// Días consumidos: 10
// Días no consumidos: 20
// Precio mensual: €29
// Reembolso: (20/30) * €29 = €19.33

$service->update([
    'cancellation_type' => CancellationType::NOTICE_PERIOD,
    'cancellation_requested_at' => today(),
    'cancellation_effective_at' => today()->addDays(30),
    'refund_unused' => true,
    'status' => ServiceStatus::CANCELLED,
    'metadata' => [
        ...$service->metadata,
        'cancellation' => [
            'days_consumed' => 10,
            'days_unused' => 20,
            'refund_amount' => 1933, // Base100
            'refund_invoice_id' => 'uuid-xxx', // Nota crédito
        ],
    ],
]);

// Se genera automáticamente una nota de crédito
```

---

## 🧩 Decisiones de Diseño

### 1. ¿Por qué NO hay modelo "Subscription"?

**Porque la recurrencia es una característica del artículo, no una entidad de dominio.**

```
❌ INCORRECTO (Over-engineering):
Article → genera → Subscription → genera → Invoice

✅ CORRECTO (SOLID):
Article → define características
ArticleServiceStatus → controla estado temporal (técnico)
Invoice → captura snapshot en momento de facturación
```

### 2. ¿Por qué `instance_identifier`?

**Porque un mismo artículo puede tener múltiples instancias específicas.**

```
Sin instance_identifier:
❌ Cliente solo puede tener 1 hosting

Con instance_identifier:
✅ Cliente puede tener N hostings (example.com, site2.com, etc)
```

### 3. ¿Por qué `ArticleOverride` separado?

**Separación de responsabilidades: pricing es ortogonal al catálogo.**

```
Article → Qué es y cuánto cuesta normalmente
ArticleOverride → Excepciones de precio para clientes específicos
```

### 4. ¿Por qué InvoiceItem NO tiene FK a Article?

**Inmutabilidad contable: la factura debe ser independiente.**

```
Razones:
✅ Modificación manual de descripciones
✅ Ajustes de precio negociados
✅ Correcciones contables
✅ Requisitos legales de descripción
✅ Artículo puede ser eliminado del catálogo

La factura debe ser un documento legal inmutable.
```

### 5. ¿Metadata vs Campos?

**Metadata para flexibilidad, campos para queries críticos.**

```
Campos directos:
- Datos que se filtran/ordenan frecuentemente
- Claves foráneas
- Datos con constraints

Metadata JSON:
- Datos específicos del tipo
- Configuraciones opcionales
- Integración con sistemas externos
- Auditoría y trazabilidad
```

### 6. ¿CHAR(1) vs ENUM en DB?

**CHAR(1) o unsignedTinyInteger en DB + Enum PHP para tipado.**

```php
// En DB - Opción 1: CHAR(1)
status CHAR(1) -- 'A', 'C', 'E', etc

// En DB - Opción 2: unsignedTinyInteger
status TINYINT UNSIGNED -- 1, 2, 3, etc

// En código (siempre Enum PHP)
enum ServiceStatus: string {
    case ACTIVE = 'A';
    case CANCELLED = 'C';
    case EXPIRED = 'E';
}

Ventajas:
✅ Flexible (no requiere ALTER TABLE)
✅ Tipado fuerte en código
✅ CHAR(1) más legible en queries directas
✅ unsignedTinyInteger más eficiente en espacio
```

### 7. ¿Base100 para precios?

**Sí, para evitar problemas de precisión con decimales.**

```
€12.34 → 1234 (integer)
€0.99 → 99 (integer)

Ventajas:
✅ Sin pérdida de precisión
✅ Cálculos exactos
✅ Compatible con sistemas contables
✅ Cast automático con lara100 package
```

### 8. ¿Optimización de tipos?

**Usar tipos mínimos necesarios para ahorrar espacio.**

```php
// En migraciones
$table->unsignedSmallInteger('tax_group_id');    // max: 65,535
$table->unsignedTinyInteger('unit_measure_id');  // max: 255

// Justificación:
// - tax_groups: raramente > 100 grupos
// - unit_measures: raramente > 50 unidades
// - Ahorro significativo en tablas grandes (invoice_items)
```

### 9. ¿Cálculo temporal para recurrencia?

**SIEMPRE usar addMonths/addYears, NUNCA addDays.**

```php
// ❌ INCORRECTO
$nextDate = $date->addDays(30); 
// Problema: Febrero tiene 28 días, produce desfase acumulativo

// ✅ CORRECTO
$nextDate = $date->addMonth();
// Respeta el calendario: 31-Ene → 28-Feb → 31-Mar

// ✅ CORRECTO para trimestral
$nextDate = $date->addMonths(3);

// ✅ CORRECTO para anual
$nextDate = $date->addYear();

// Ventajas:
✅ Sin desfases acumulativos
✅ Respeta días del mes (28, 30, 31)
✅ Maneja años bisiestos correctamente
✅ Más legible y mantenible
```

---

## 🎨 Resumen Visual

```
┌─────────────────────────────────────────────────────────────┐
│                    ARQUITECTURA FINAL                       │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Articles (Catálogo)                                        │
│  ├─ Goods: venta directa → Invoice                         │
│  └─ Services:                                               │
│     ├─ One-time → Invoice directa                           │
│     └─ Recurring:                                           │
│        └─ ArticleServiceStatus (instancias)                 │
│           ├─ instance_identifier                            │
│           ├─ next_billing_date                              │
│           └─ → Job → Invoice automática                     │
│                                                             │
│  ArticleOverride (Pricing)                                  │
│  └─ custom_price por cliente                                │
│                                                             │
│  InvoiceItem (Snapshot)                                     │
│  ├─ NO FK a Article                                         │
│  ├─ metadata con auditoría                                  │
│  └─ Inmutable después de emisión                            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## ✅ Checklist de Implementación

- [ ] Migración `articles`
- [ ] Migración `article_overrides`
- [ ] Migración `article_service_status`
- [ ] Actualizar migración `invoice_items` (metadata)
- [ ] Modelo `Article` con scopes y helpers
- [ ] Modelo `ArticleOverride`
- [ ] Modelo `ArticleServiceStatus`
- [ ] Enums: `ItemType`, `BillingFrequency`, `ServiceStatus`, `CancellationType`
- [ ] DTOs: `InvoiceItemMetadata`, `SourceReference`, `PricingDetails`, `BillingDetails`, `AuditEntry`
- [ ] Servicio `PricingService`
- [ ] Servicio `ArticleTemplateService`
- [ ] Servicio `InvoiceItemAuditService`
- [ ] Job `ProcessRecurringBilling`
- [ ] Tests unitarios para cada modelo
- [ ] Tests de integración para flujos completos
- [ ] Documentación de API

---

**Fecha:** 2024-10-19  
**Versión:** 1.0  
**Autor:** Arquitectura Larabill

