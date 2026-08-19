# ADR-013: `effective_price` es estado contractual, no caché (larabill)

> **Status**: Accepted
> **Date**: 2026-08-19
> **Relates**: cierra AID-956. Cumple la promesa de **ADR-004**, que desde 2025-12-09 declaraba `effective_price` como «precio al momento de contratación» mientras el motor derivaba del catálogo en cada emisión. Hermano de **AID-589** (el paquete no inventa valores de negocio del consumidor) y de **ADR-012** (resolución determinista de precios). La liquidación de lo no consumido —qué artefacto fiscal la documenta y si existe— vive en **ADR-014**, no aquí.

## Contexto

La emisión recurrente **nunca leía `effective_price`**. Derivaba el importe del catálogo en el instante de facturar:

```php
// PricingService::createPricingDetails() — la columna del contrato no se consultaba
$basePrice    = $article->getPriceFor($frequency) ?? 0.0;
$override     = $customerId ? $this->getActiveOverride($article, $customerId) : null;
$appliedPrice = $override?->custom_price?->unscaledValue() ?? $basePrice;
```

La columna se escribía (`ArticleServiceStatus::updateEffectivePrice()`), se declaraba en propiedad, `fillable` y cast, y la leía **únicamente** el cálculo de reembolso. Ningún camino de emisión la consultaba.

No era documentación obsoleta, era contradicción entre decisión y código, y la cronología dice cuál manda: `updateEffectivePrice()` (2025-10-19) y `ARCHITECTURE.md:506` (2025-11-14) describían una caché refrescable; **ADR-004 (2025-12-09) es posterior a ambos y es la decisión aceptada**. El propio `ARCHITECTURE.md` se contradecía siete líneas después, en `:513`, describiendo `unit_price: effective_price`.

Cuatro consecuencias, todas vivas hasta esta release:

- **La factura y el reembolso usaban bases distintas.** Un contrato importado a precio pactado se facturaba a catálogo y se reembolsaba al pactado.
- **Sin precio de catálogo para esa frecuencia se facturaba a cero.** El `?? 0.0` no fallaba loud: emitía un importe nulo.
- **Un cambio de catálogo u override repreciaba contratos vivos** en la corrida siguiente, sin acto contractual.
- **El resolver de override del motor ignoraba la vigencia.** `PricingService::getActiveOverride()` filtraba solo `is_active` y cliente, sin `valid_from`/`valid_to` y sin `ORDER BY`: aplicaba overrides caducados y aún no vigentes, y ante varios candidatos elegía según el recorrido del índice.

## Decisión

### D1 — El motor factura SIEMPRE el `effective_price` almacenado

`RecurringBillingService` no consulta catálogo ni override para decidir el importe. `effective_price` es **estado contractual**.

### D2 — Cambiar `ArticlePrice` o `ArticleOverride` no altera el **importe** de contratos existentes

Acotación deliberada: **solo el importe**. `shouldGenerateInvoice()` sigue leyendo `ArticlePrice.billing_days_in_advance`, así que modificar o caducar ese precio de catálogo sigue cambiando **cuándo** se emite la factura. Enunciar D2 sin este matiz prometería una independencia del catálogo que el código no da. La independencia total exigiría persistir también la política de antelación en el contrato, y queda fuera de alcance.

### D3 — `updateEffectivePrice()` es mutación explícita del consumidor, nunca etapa del motor

El consumidor declara una **revisión contractual** llamándola. El motor jamás la invoca. Si el motor la invocara: sobrescribiría los precios importados justo antes de facturarlos, una expiración de override cambiaría contratos sin acto contractual, y sería imposible garantizar que factura y liquidación usan la misma base.

Además, `ArticleOverride` pertenece a **cliente + artículo** y no tiene frecuencia ni referencia a la instancia de servicio: **no puede representar dos contratos distintos del mismo cliente y producto**. El contrato sí.

### D4 — La revisión afecta solo a periodos aún no emitidos

Coherente con la inmutabilidad de lo emitido. **Y por tanto también a su liquidación:** `calculateRefund()` mide sobre la línea de factura emitida que cubre el periodo, no sobre el precio que el contrato lleva hoy. Sin esto, D4 era falso — una revisión posterior cambiaba el importe a devolver de un periodo ya facturado.

Localización de la línea: por la referencia de servicio que el motor congela en cada línea que emite (`metadata.source_reference.service_status_id`), que es lo que distingue dos acuerdos del mismo cliente para el mismo artículo y frecuencia. Sin línea que cubra el periodo —servicio importado de un sistema previo— cae al precio del contrato y **no lanza**: es una cifra orientativa para el consumidor, no un documento fiscal.

### D5 — Método contractual separado, y `ARCHITECTURE.md` corregido

Se añade `PricingService::createPricingDetailsForContract()` y **no** se toca la semántica de `createPricingDetailsForService()`, que permanece como cotización a catálogo actual. La razón es de gobierno: `docs/api-surface.md` clasifica *«changed signatures/semantics → major»*, y la línea 6.x está fijada con `approvals/majors/` vacío.

En el DTO resultante, `basePrice === appliedPrice` (ambos el precio contractual), los descuentos van a `null` —no existe base histórica almacenada contra la que calcularlos, y no se inventa una consultando catálogo— y `overrideId` se conserva **como observación**: «a qué descuento apuntaba el contrato en el momento de emitir», nunca como procedencia del importe. Ambas columnas son `fillable` independientes y la FK es `nullOnDelete`, así que nada garantiza que ese override fijara ese precio. Se conserva porque no lo lee nadie y es la única pista al investigar un precio raro meses después.

`ARCHITECTURE.md:506` pasa de «Verificar precio efectivo actual (puede haber cambiado override)» a «Leer precio contractual efectivo», con lo que deja de contradecir a su propio `:513`.

### D6 — Ausente es `null`, y solo `null`

`MissingContractPriceException` (`@api`) en los dos caminos: al cotizar un contrato sin precio, y al revisar un contrato sin candidato —donde además **se calcula primero y se escribe después**, de modo que una revisión fallida conserva el precio anterior intacto.

**Un precio contractual de cero es VÁLIDO y se factura a cero.** Va una prueba explícita para impedir que alguien escriba un `if (! $price)` que confunda cero con ausente.

### D7 — El comentario `(cache)` de la migración NO se corrige

`create_article_service_status_table.php:49-50` dice `// Pricing efectivo (cache)` y `comment('... (from ArticlePrice or override)')`. Ambos son ahora doctrinalmente falsos y **aun así no se tocan**: la migración está publicada, con su `sha256` e `in_base: true` en el manifiesto, y editarla pondría rojo `ShippedMigrationImmutabilityTest` mientras `MigrationOrderConsistencyTest` daría falso verde. Mismo caso que D6 de ADR-012. La corrección vive en este ADR.

### D8 — Sin backfill

El paquete **no crea `ArticleServiceStatus` en ningún punto de `src/`** y nadie llama a `updateEffectivePrice()` salvo los tests. Todo valor de `effective_price` en producción lo escribió el consumidor o su importación. Reescribirlo desde el paquete sería exactamente lo contrario de D2.

## La contrapartida, explícita

Los overrides temporales con `valid_to` **dejan de devolver automáticamente el servicio al precio base** cuando expiran. Es consecuencia directa de D2.

Si esa funcionalidad hace falta, se modela aparte —una revisión de precio programada, o una política contractual «seguir override»— y **no** convirtiendo todos los contratos en precios flotantes.

## Consecuencias

- Para cualquier consumidor cuyo `effective_price` no coincidiera con el catálogo, **el importe facturado cambia** — hacia el pactado.
- Un servicio sin precio contractual utilizable deja de facturarse a catálogo o a cero: falla loud y el servicio se cuenta como `failed`, sin persistir nada ni consumir numeración (frontera atómica de AID-836).
- Cambiar `ArticlePrice`/`ArticleOverride` deja de tener efecto sobre el importe de contratos vivos.
- `createPricingDetailsForService()` no cambia de comportamiento.

## Por qué MINOR y no MAJOR

`STABILITY.md`, «What this means in practice», es la cláusula específica y aplica literalmente: *«A defect discovered in production is fixed in a patch or minor unless a breaking fix is demonstrably the only correct option»*. Aquí un fix breaking no es la única opción correcta: **ni siquiera es una opción**, porque romper no arregla nada que el minor no arregle. La regla 5 del mismo documento nombra este caso: los fixes que cambian comportamiento observable se documentan en el CHANGELOG bajo **Fixed**, dentro de `N.x`.

El contrato nunca cambió: ADR-004 siempre dijo que la línea recurrente sale de `effective_price`. Lo que cambia no es la semántica pública, sino que el código deja de contradecir la semántica ya publicada — la cláusula de `api-surface.md` gobierna *redefinir* una superficie, y ésta la *restaura*. Y no se reescribe ningún dato persistido (D8).

## Sin comando de diagnóstico

Se rechazó publicar un `larabill:diagnose-contract-price-drift`: la diferencia entre catálogo y contrato pasa a ser un **estado válido**, no una invariante rota, y no es comparable con `DiagnosePriceOverlapsCommand`, donde los solapes son *siempre* inválidos. Además no puede existir un SQL exactamente equivalente al motor anterior, porque su resolver usaba `first()` sin orden y sin aplicar vigencia: prometer equivalencia sería falso.

La release entrega en su lugar una **consulta de impacto** en el CHANGELOG, que no se llama «drift» y no promete reproducir el precio anterior.

## Nota de verificación

El spec de AID-956 registraba como hallazgo colateral que `updateEffectivePrice()` «funciona por accidente», porque leía `$override->custom_price` sin operador null-safe. **Medido en PHP 8.3, esa premisa es falsa:** el operador `??` suprime el aviso «Attempt to read property on null» de toda la expresión izquierda, así que esa línea nunca emitió warning alguno. El `?->` sería redundante ahí y PHPStan lo rechaza. El defecto real de ese método era solo el que corrige D6: escribir antes de comprobar que hay candidato.

## Criterios de reapertura

- Un consumidor con necesidad real de que los overrides temporales revuelvan al precio base al expirar. Se modela como revisión programada, no revirtiendo D1.
- Necesidad de independencia total del catálogo, incluida la fecha de emisión: exigiría persistir la política de antelación en el contrato (ver acotación de D2).
