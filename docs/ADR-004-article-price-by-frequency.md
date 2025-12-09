# ADR-004: Precios de Artículos por Frecuencia de Facturación

## Estado

**Aceptado** - Implementado el 2025-12-08

## Fecha

2025-12-08

## Contexto

El modelo actual de `Article` es agnóstico respecto a la frecuencia de facturación:

- Un artículo tiene UN único precio (`base_price`)
- La frecuencia (`billing_frequency`) es informativa, no determina precio
- Para ofrecer un servicio con diferentes precios según frecuencia, se requiere crear artículos duplicados

### Problema Actual

```
// Para ofrecer "Hosting Pro" con precios por frecuencia:
Article: HOST-PRO-MONTHLY   → €29/mes
Article: HOST-PRO-QUARTERLY → €79/trimestre
Article: HOST-PRO-YEARLY    → €290/año
```

Esto genera:

- Duplicación de datos
- Inconsistencias al actualizar
- Imposibilidad de comparar precios
- Reporting fragmentado

### Distinción GOOD vs SERVICE

| Tipo | Naturaleza | Recurrencia |
|------|------------|-------------|
| GOOD | Bien tangible (dominio, licencia, certificado) | Pago único. Renovación = nueva compra |
| SERVICE | Servicio (hosting, soporte, mantenimiento) | Contratación por período con múltiples frecuencias |

La distinción `item_type` es **fiscal** (IVA, retenciones), no de temporalidad.

## Decisión

### 1. Crear modelo `ArticlePrice`

Nuevo modelo que relaciona artículo + frecuencia + precio:

```php
// Aichadigital\Larabill\Models\ArticlePrice
class ArticlePrice extends Model
{
    protected $fillable = [
        'article_id',
        'billing_frequency',
        'price',
        'billing_days_in_advance',  // Movido desde Article
        'valid_from',
        'valid_to',
        'is_active',
    ];

    protected $casts = [
        'billing_frequency'       => BillingFrequency::class,
        'price'                   => Base100Int::class,
        'billing_days_in_advance' => 'integer',
        'valid_from'              => 'date',
        'valid_to'                => 'date',
        'is_active'               => 'boolean',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
```

### 2. Expandir enum `BillingFrequency`

```php
enum BillingFrequency: int
{
    case ONE_TIME    = 0;   // Pago único
    case WEEKLY      = 1;   // Semanal
    case BIWEEKLY    = 2;   // Quincenal
    case MONTHLY     = 3;   // Mensual
    case BIMONTHLY   = 4;   // Bimensual
    case QUARTERLY   = 5;   // Trimestral
    case SEMIANNUAL  = 6;   // Semestral
    case YEARLY      = 7;   // Anual
    case BIENNIAL    = 8;   // Bienal
    case TRIENNIAL   = 9;   // Trienal

    public function label(): string { /* ... */ }
    public function months(): ?int { /* ... */ }
    public function days(): ?int { /* ... */ }
    public function isRecurring(): bool { /* ... */ }
    public function addToDate(Carbon $date, int $interval = 1): Carbon { /* ... */ }
    public function subtractFromDate(Carbon $date, int $interval = 1): Carbon { /* ... */ }
}
```

### 3. Campos eliminados de `Article`

Los siguientes campos han sido **eliminados** (no deprecados):

- `base_price` → ahora en `ArticlePrice.price`
- `billing_frequency` → ahora en `ArticlePrice.billing_frequency`
- `billing_interval` → eliminado (ya no necesario con enum expandido)
- `is_recurring` → se infiere de si tiene precios con frecuencias recurrentes
- `billing_days_in_advance` → ahora en `ArticlePrice.billing_days_in_advance`

### 4. Campo añadido a `ArticleServiceStatus`

El servicio contratado guarda la frecuencia seleccionada al momento de la contratación:

```php
// ArticleServiceStatus
$table->unsignedTinyInteger('billing_frequency')
    ->comment('BillingFrequency enum value selected at contract time');
```

Esto permite:

- Inmutabilidad del contrato (cambios en ArticlePrice no afectan contratos existentes)
- El servicio tiene toda la información necesaria para facturar

### 5. Schema `article_prices`

```php
Schema::create('article_prices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('article_id')->constrained()->cascadeOnDelete();
    $table->unsignedTinyInteger('billing_frequency');
    $table->integer('price'); // Base100
    $table->unsignedTinyInteger('billing_days_in_advance')->nullable();
    $table->date('valid_from')->nullable();
    $table->date('valid_to')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['article_id', 'billing_frequency', 'valid_from']);
    $table->index(['article_id', 'is_active']);
});
```

### 6. Actualizar modelo `Article`

```php
// Relación
public function prices(): HasMany
{
    return $this->hasMany(ArticlePrice::class);
}

public function activePrices(): HasMany
{
    return $this->prices()
        ->where('is_active', true)
        ->where(function ($q) {
            $q->whereNull('valid_from')
              ->orWhere('valid_from', '<=', now());
        })
        ->where(function ($q) {
            $q->whereNull('valid_to')
              ->orWhere('valid_to', '>=', now());
        });
}

// Obtener precio por frecuencia
public function getPriceFor(BillingFrequency $frequency): ?float
{
    return $this->activePrices()
        ->where('billing_frequency', $frequency)
        ->value('price');
}

// Check si es recurrente (tiene precios con frecuencias recurrentes)
public function isRecurring(): bool
{
    return $this->activePrices()
        ->where('billing_frequency', '!=', BillingFrequency::ONE_TIME)
        ->exists();
}
```

## Modelo Final

```
Article                          ArticlePrice
├── code                         ├── article_id (FK)
├── name                         ├── billing_frequency (enum)
├── item_type: GOOD|SERVICE      ├── price (Base100)
├── is_active                    ├── billing_days_in_advance
├── cost_price                   ├── valid_from
└── prices() ─────────────────── ├── valid_to
                                 └── is_active

ArticleServiceStatus
├── article_id (FK)
├── billing_frequency (enum) ← frecuencia contratada
├── effective_price          ← precio al momento de contratación
└── ...

GOOD    → típicamente 1 ArticlePrice (ONE_TIME)
SERVICE → típicamente N ArticlePrice (MONTHLY, QUARTERLY, YEARLY...)
```

## Servicios Actualizados

### PricingService

Ahora requiere la frecuencia de facturación:

```php
public function getEffectivePrice(
    Article $article,
    BillingFrequency $frequency,
    ?int $customerId
): ?float;

public function createPricingDetails(
    Article $article,
    BillingFrequency $frequency,
    ?int $customerId
): PricingDetails;

// Nuevo método para servicios
public function createPricingDetailsForService(
    ArticleServiceStatus $service
): PricingDetails;
```

### RecurringBillingService

Usa `ArticleServiceStatus.billing_frequency` en lugar de `Article.billing_frequency`.

## Consecuencias

### Positivas

- Un artículo = múltiples precios por frecuencia
- Eliminación de duplicados
- Reporting unificado
- Flexibilidad para descuentos por frecuencia (anual más barato)
- Historial de precios con `valid_from/to`
- Cada frecuencia puede tener su propio `billing_days_in_advance`
- Inmutabilidad del contrato (frecuencia guardada en `ArticleServiceStatus`)

### Negativas

- Migración de datos existentes (no aplica - desarrollo greenfield)
- Actualización de UI (selección de frecuencia al facturar)
- Complejidad adicional en queries de precio

## Trabajo Futuro

### ADR-005: Precios por Volumen (Productos)

El problema de precios por volumen para productos (GOODS) es **diferente** al de frecuencia:

| Concepto | `ArticlePrice` | Precio por volumen |
|----------|---------------|-------------------|
| **Aplica a** | SERVICES (principalmente) | GOODS (principalmente) |
| **Dimensión** | Tiempo (frecuencia) | Cantidad |
| **Modelo** | 1 artículo → N precios por frecuencia | 1 artículo → N rangos de cantidad |

Propuesta conceptual:

```php
// article_volume_prices (futuro)
$table->foreignId('article_id');
$table->unsignedInteger('min_quantity');
$table->unsignedInteger('max_quantity')->nullable();
$table->integer('price'); // Base100
$table->date('valid_from')->nullable();
$table->date('valid_to')->nullable();
$table->boolean('is_active')->default(true);
```

Esto debería implementarse en un ADR separado cuando sea necesario.

## Referencias

- ADR-003: User/Customer Unification
- `ANALISIS_ENUMS_Y_TIPOS_DATOS.md`
- `ARCHITECTURE.md`
