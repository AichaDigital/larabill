# Diseño AID-956: `effective_price` es estado contractual, no caché

- **Ticket:** AID-956 (Urgent) — reclasificado de «decisión de dominio abierta» a **bug**: la decisión ya existe en ADR-004 y el código la incumple.
- **Base:** `main` @ `f0c7880` (v6.12.0). El árbol de trabajo tiene `CLAUDE.md` modificado y este spec sin trackear; `src/` está limpio.
- **Autor de la decisión de dominio:** Abdelkarim Mateos (veredicto en sesión de 2026-08-17, recogido literalmente en §3).
- **Estado:** **aprobado** (rev 3, 2026-08-17). Adjudicación del propietario en §10, ronda adversarial de Codex en §11.

## 1. El defecto: confirmado, con la cadena completa

La emisión recurrente **nunca lee `effective_price`**. Deriva el precio del catálogo en el instante de facturar. Cadena verificada línea a línea:

```php
// src/Services/RecurringBillingService.php:399-400
// Get effective pricing (with customer overrides) using service's billing frequency
$pricingDetails = $this->pricingService->createPricingDetailsForService($service);

// src/Services/PricingService.php:125-132 — descarta el servicio, se queda con tres campos
return $this->createPricingDetails($service->article, $service->billing_frequency, $service->customer_id);

// src/Services/PricingService.php:105-107 — re-deriva
$basePrice    = $article->getPriceFor($frequency) ?? 0.0;
$override     = $customerId ? $this->getActiveOverride($article, $customerId) : null;
$appliedPrice = $override?->custom_price?->unscaledValue() ?? $basePrice;

// src/Services/RecurringBillingService.php:432 — lo que acaba en la línea de factura
'base_price' => (int) $pricingDetails->appliedPrice,
```

La formulación exacta es: **ningún camino de emisión LEE `effective_price`.** La columna sí se escribe en `ArticleServiceStatus::updateEffectivePrice()` (`:286`) y se declara en propiedad, `fillable` y cast; las únicas **lecturas** de `src/` son `ServiceLifecycleService.php:116,131` (reembolso), más las asignaciones de las factories.

Cuatro consecuencias, todas vivas hoy:

- **La factura y el reembolso usan bases distintas.** El reembolso lee `effective_price` (`ServiceLifecycleService:131`); la factura lee catálogo re-derivado. Un contrato importado a precio pactado se factura a catálogo y se reembolsa al pactado. No es un riesgo futuro: es el comportamiento actual.
- **Sin precio de catálogo para esa frecuencia, se factura a cero.** El `?? 0.0` de `PricingService:105` no falla loud, emite un importe nulo.
- **Un cambio de catálogo u override reprecia contratos vivos** sin acto contractual, en la corrida siguiente.
- **El resolver de override del motor ignora la vigencia.** `PricingService::getActiveOverride()` (`:59-65`) es `->active()->forCustomer($id)->first()`, y `ArticleOverride::scopeActive()` (`:96-99`) es **solo** `where('is_active', true)`. El scope que aplica la ventana —`scopeValidAt()` (`:106`)— no se invoca nunca desde ahí, y no hay `ORDER BY`. Es decir: el motor viene aplicando overrides **caducados y aún no vigentes**, y ante varios candidatos elige según el recorrido del índice. Esto es un resolver distinto de `Article::getActiveOverrideFor()` (`:375-389`), que sí filtra `valid_from`/`valid_to` y es el que usa `updateEffectivePrice()` — **dos resolvers con semánticas distintas conviviendo en el paquete**.

El impacto medido que abrió el ticket: 51 de 154 combinaciones producto/ciclo de la cartera tienen más de un precio contratado, con desviaciones de hasta 3,6×.

## 2. La contradicción es real, no documentación obsoleta

Hay dos intenciones enfrentadas **en el repositorio**, y la cronología (verificada con `git log --follow`) explica cuál manda:

| Artefacto | Commit | Fecha | Qué afirma |
|---|---|---|---|
| `ArticleServiceStatus::updateEffectivePrice()` | `0ba13ac` | 2025-10-19 | la columna es caché mutable, refrescable a demanda |
| `docs/ARCHITECTURE.md:506` | `d51d346` | 2025-11-14 | «b) Verificar precio efectivo actual (puede haber cambiado override)» |
| `docs/ADR-004:204` | `89315d5` | 2025-12-09 | «`effective_price` ← precio al momento de contratación» |

**ADR-004 es posterior a las dos y es la decisión aceptada.** Garantiza que los cambios de catálogo no alteran contratos existentes. Pero la implementación del propio ADR conservó la resolución dinámica, así que esto no se arregla editando documentación: hay contradicción entre decisión y código.

Además `ARCHITECTURE.md` **se contradice a sí mismo siete líneas después**: `:513` describe el paso «d) Crear InvoiceItem — `unit_price: effective_price`». La lectura que reconcilia ambas es que `effective_price` es la autoridad y su refresco es un acto deliberado — que es exactamente lo que fija §3.

## 3. Decisiones

### D1 — El motor factura SIEMPRE el `effective_price` almacenado ✅ *(veredicto del propietario)*

`RecurringBillingService` no consulta catálogo ni override para decidir el importe. `effective_price` es **estado contractual**, no caché de lectura.

### D2 — Cambiar `ArticlePrice` o `ArticleOverride` no altera el **importe** de contratos existentes ✅ *(veredicto del propietario, acotada en rev 3)*

Es la garantía que ADR-004 ya prometía. El fix la hace cierta **para el importe, y solo para el importe**.

Acotación obligada por la ronda de Codex: `shouldGenerateInvoice()` (`RecurringBillingService.php:308`) llama a `Article::getBillingDaysInAdvanceFor()`, que lee `activePrices()` → **`ArticlePrice`**. Modificar, caducar o borrar ese precio de catálogo sigue cambiando **cuándo** se emite la factura del contrato. Enunciar D2 sin ese matiz sería prometer una independencia del catálogo que el código no da. Si algún día se quiere independencia total, hay que persistir también la política de antelación en el contrato — fuera del alcance de AID-956.

### D3 — `updateEffectivePrice()` es mutación explícita del consumidor, nunca etapa del motor ✅ *(veredicto del propietario)*

El consumidor declara una **revisión contractual** llamándola. El motor jamás la invoca. Razones registradas por el propietario:

- Sobrescribiría los precios importados justo antes de facturarlos.
- Una modificación, expiración o eliminación de un override cambiaría contratos sin acto contractual.
- `ArticleOverride` pertenece a **cliente + artículo** — verificado: su `fillable` es `customer_id`, `article_id`, `custom_price`, `reason`, `valid_from`, `valid_to`, `is_active`. **No tiene frecuencia ni referencia a la instancia de servicio**, así que no puede representar contratos distintos del mismo cliente y producto.
- Haría imposible garantizar que factura y reembolso usan la misma base.

### D4 — La revisión afecta solo a periodos aún no emitidos ✅ *(veredicto del propietario)*

Coherente con la inmutabilidad de lo emitido: lo ya facturado no se reescribe.

**Y por tanto tampoco su liquidación** — ver D12. Codex demostró que sin D12 esta promesa era falsa: `calculateRefund()` lee el precio vivo, así que una revisión hecha después de emitir un periodo cambiaba el importe a devolver de ese periodo ya emitido.

### D12 — La liquidación de lo no consumido mide sobre la línea emitida ✅ *(veredicto del propietario, rev 3)*

`ServiceLifecycleService::calculateRefund()` deja de leer `effective_price` y deriva de la **línea de factura que cubre el periodo** — que ya lleva su precio y sus fechas de servicio verbatim desde AID-559.

Escenario que lo motiva, medido en euros: hosting a 24 €/mes; se emite febrero a 24 €; el día 5 el consumidor revisa el contrato a 29 € (acto legítimo de D3); el día 10 el cliente cancela. Hoy la calculadora devuelve 18/28 sobre **29 €** = 18,64 €, cuando el cliente pagó 24 €. Y en la dirección inversa —revisión a la baja— se queda con dinero del cliente.

- **Sin línea que cubra el periodo** (servicio importado de un sistema previo, periodo nunca facturado por larabill): cae al precio del contrato y lo documenta. No lanza: esto es una cifra orientativa que el consumidor usa, no un documento fiscal.
- **No toca `refund_unused`, ni `CancellationType`, ni añade configuración, ni migra nada.** Solo cambia de dónde sale el precio.
- **Es invariante bajo ADR-014:** devuelvas el importe, una parte, o lo conviertas en línea de abono de una factura nueva, la cantidad de partida es la misma. Por eso cabe en AID-956 sin prejuzgar el diseño de política.

Todo lo demás de este dominio —si hay abono, cuánto, con qué artefacto fiscal— sale de AID-956 y vive en **ADR-014**.

### D5 — `ARCHITECTURE.md:506` se reescribe ✅ *(veredicto del propietario)*

De «Verificar precio efectivo actual (puede haber cambiado override)» a **«Leer precio contractual efectivo»**. Con ello el documento deja de contradecirse contra su propio `:513`.

### D6 — Método contractual separado ✅ *(veredicto del propietario, opción B)*

Se añade `PricingService::createPricingDetailsForContract(ArticleServiceStatus $service): PricingDetails` y **no** se toca la semántica de `createPricingDetailsForService()`.

**La razón es de gobierno, no de utilidad.** `docs/api-surface.md:7` dice literalmente que sobre superficie `@api` un *«changed signatures/semantics → major»*, y la línea 6.x está fijada con `approvals/majors/` vacío: cambiarle la semántica al método existente exigiría una major bloqueada. (El argumento que traía la revisión anterior —que era la única herramienta de preview— **es falso**: `PricingService::createPricingDetails()` (`:100`) ya es público y cotiza artículo + frecuencia + cliente.)

- **`createPricingDetailsForService()`** permanece como **cotización a catálogo actual**. Se aclara en su docblock, porque el nombre por sí solo sugiere lo contrario.
- **`createPricingDetailsForContract()`** representa el estado contractual y **no consulta catálogo ni overrides**.

```php
new PricingDetails(
    basePrice:          $service->effective_price->unscaledValue(),
    appliedPrice:       $service->effective_price->unscaledValue(),
    pricingRule:        'contract_price',
    discountAmount:     null,
    discountPercentage: null,
    overrideId:         $service->current_override_id,
);
```

- **`basePrice` === `appliedPrice`**, ambos `effective_price`.
- **Descuentos a `null`:** no existe una base histórica almacenada contra la que calcularlos. No se inventa una consultando catálogo.
- **`overrideId`: `current_override_id`, etiquetado como observación, nunca como procedencia** ✅ *(decisión de rev 3)*. Codex demostró que la afirmación anterior seguía siendo demasiado fuerte: `effective_price` y `current_override_id` están **ambos en `$fillable`** (`ArticleServiceStatus.php:62-77`) y se asignan por separado; nada en la base de datos obliga a que ese override fijara ese importe, y además la FK usa `nullOnDelete()`. La redacción exacta, en docblock y ADR, es **«a qué descuento apuntaba el contrato en el momento de emitir»** — cierto por construcción. Se conserva el dato en vez de escribir `null` porque **no lo lee nadie** (verificado: solo `PricingDetails::fromArray()` para el round-trip; ni servicios, ni plantillas PDF, ni informes), es decir, el riesgo es de **mala lectura humana** y se cura con la etiqueta, mientras que borrarlo elimina la única pista disponible al investigar un precio raro meses después.

### D7 — `MissingContractPriceException` en ambos caminos ✅ *(veredicto del propietario)*

Coherente con la doctrina de la casa (AID-589, AID-836: no inventar valores de negocio):

- **`createPricingDetailsForContract()`** lanza si el valor contractual es null.
- **`updateEffectivePrice()`** calcula primero el candidato y **lanza antes de escribir** si no encuentra override ni precio de catálogo — conservando el precio anterior intacto.

**La condición es exactamente ésta, y solo ésta:**

```php
$service->effective_price === null
```

**Un precio contractual de cero es VÁLIDO y se factura a cero.** Va una prueba explícita para blindarlo, precisamente para impedir que alguien escriba en el futuro un `if (! $price)` que lo trate como ausente.

**Cómo se prueba, dado que la columna es `NOT NULL`:**

- **No se debilita el esquema.** Un servicio correctamente persistido no puede llegar nulo al motor.
- **No se fabrica un registro nulo** para una prueba de integración.
- El guard se prueba **unitariamente**, con un modelo transitorio o incompleto. La excepción sigue siendo necesaria porque ambos métodos son públicos y pueden recibir modelos no persistidos, o provenientes de un esquema de consumidor divergente.
- La prueba de persistencia relevante es la otra: **`updateEffectivePrice()` sin candidato lanza y conserva el precio anterior**.

### D8 — El comentario `(cache)` de la migración NO se corrige ✅ *(derivado de regla de la casa)*

`create_article_service_status_table.php:49-50` dice `// Pricing efectivo (cache)` y `comment('Currently applied price in Base100 (from ArticlePrice or override)')`. Ambos son ahora doctrinalmente falsos, y **aun así no se tocan**: la migración está publicada, con `sha256: 6f25ba3e…` e `in_base: true` en `tests/Contract/release-migration-manifest.json`. Editarla pondría rojo `ShippedMigrationImmutabilityTest` — y `MigrationOrderConsistencyTest` daría falso verde, porque compara `.php` contra `.php.stub` y ambos cambiarían igual. Mismo caso que D6 de ADR-012.

La corrección va al ADR nuevo, no al comentario.

### D9 — Sin backfill ✅

El paquete **no crea `ArticleServiceStatus` en ningún punto de `src/`** (verificado) y **nadie llama a `updateEffectivePrice()`** salvo dos tests. Todo valor de `effective_price` en producción lo escribió el consumidor o su import. Reescribirlo desde el paquete sería exactamente lo contrario de D2.

### D10 — MINOR, sin migraciones ✅ *(veredicto del propietario)*

El arreglo del motor cabe como **bug fix observable** bajo `STABILITY.md`: restaura el contrato aceptado, y debe documentar explícitamente comportamiento anterior y nuevo.

Lo que obliga definitivamente a MINOR son las adiciones `@api`: `createPricingDetailsForContract()` y `MissingContractPriceException`.

La entrada de CHANGELOG va bajo **`### Fixed`**, con advertencia visible de que pueden cambiar los importes recurrentes.

**Justificación escrita, exigida por la ronda de Codex** *(que sostenía MAJOR)*. Su argumento: `api-surface.md:7` clasifica *«changed signatures/semantics → major»*, `RecurringBillingService` es `@api` (`api-surface.md:39`), y el Scope de `STABILITY.md` cubre *«the semantics of persisted data»*. La clasificación que prevalece, y por qué:

- **`STABILITY.md`, "What this means in practice", es la cláusula específica y aplica literalmente:** *«A defect discovered in production is fixed in a **patch or minor** unless a breaking fix is demonstrably the only correct option»*. Aquí un fix breaking no es la única opción correcta: es que **ni siquiera es una opción**, porque romper no arregla nada que el minor no arregle.
- **La regla 5 del mismo documento nombra este caso por su nombre:** *«Bug fixes that change observable behaviour are documented in the CHANGELOG under **Fixed** with the old and new behaviour stated»* — dentro de `N.x`, no en una major.
- **El contrato nunca cambió.** ADR-004 (2025-12-09) siempre dijo que la línea recurrente sale de `effective_price`. Lo que cambia no es la semántica pública, sino que el código deja de contradecir la semántica ya publicada. La cláusula de `api-surface.md` gobierna *redefinir* una superficie; ésta la *restaura*.
- **No se reescribe ningún dato persistido:** D9, sin backfill. Cambia lo que se emitirá, no lo emitido.

Esta justificación viaja en el ADR y en el CHANGELOG, no solo en este spec — que es lo que `STABILITY.md` exige cuando la clasificación es contestable.

### D11 — Sin comando permanente; solo consulta de impacto ✅ *(veredicto del propietario)*

**Se rechaza `larabill:diagnose-contract-price-drift`.** Razones registradas:

- La diferencia entre catálogo y contrato pasa a ser un **estado válido**, no «drift» ni una invariante rota.
- Un comando Console es superficie `@api` (precedente: `DiagnosePriceOverlapsCommand` lo lleva declarado) y habría que mantenerlo aunque solo sirviera para esta transición.
- **No es comparable con `DiagnosePriceOverlapsCommand`:** los solapes de precio son *siempre* inválidos; dos precios contractuales distintos pueden ser *siempre* correctos.
- Un comando publicado en la misma versión tampoco sería realmente pre-upgrade.
- **No puede existir un SQL exactamente equivalente al motor anterior**, porque `PricingService::getActiveOverride()` usa `first()` sin orden y ni siquiera aplica `valid_from`/`valid_to`. Prometer equivalencia sería falso.

Lo que sí entrega la release:

- **Una consulta MySQL de impacto en el CHANGELOG**, y nada más.
- **No se la llama «drift»** ni se afirma que reproduzca el precio anterior.
- Muestra por servicio: `effective_price`, precio base vigente, y **todos** los overrides activos candidatos.
- Señala **por separado** los dos casos ambiguos: múltiples overrides candidatos, y ausencia de precio de catálogo.
- Indica **pausar la ejecución recurrente antes de actualizar**.

Si en el futuro se exigiera una puerta automatizada exacta, la única solución honesta sería una **release puente** que publique primero el comando y una posterior que cambie la emisión. Para este Urgent es exceso de alcance.

## 4. La contrapartida, explícita

Los overrides temporales con `valid_to` **dejan de devolver automáticamente el servicio al precio base** cuando expiran. Es consecuencia directa de D2 y se documenta como tal.

Si esa funcionalidad hace falta, se modela aparte —una revisión de precio programada, o una política contractual «seguir override»— y **no** convirtiendo todos los contratos en precios flotantes.

## 5. Behavior changes

- La factura recurrente pasa a usar `effective_price`. Para cualquier consumidor cuyo `effective_price` no coincidiera con el catálogo, **el importe facturado cambia** — hacia el pactado.
- Un servicio sin `effective_price` utilizable deja de facturarse a catálogo o a cero: falla loud y el servicio se cuenta como `failed`, sin persistir nada ni consumir numeración (misma frontera atómica de AID-836).
- Cambiar `ArticlePrice`/`ArticleOverride` deja de tener efecto sobre contratos vivos.
- `PricingService::createPricingDetailsForService()` **no cambia de comportamiento** (D6-B).

## 6. Plan de tests (TDD, rojo primero)

Consolidado tras la adjudicación: los antiguos 4/7 y 1/8 eran duplicados funcionales y se funden sin perder cobertura.

**Feature — el motor factura el contrato:**

1. **Cambiar un override no cambia la siguiente factura.** ⚠️ **La mutación tiene que ser `custom_price` o `is_active = false`.** Expirar `valid_to` **no da rojo antes del fix**, porque el resolver del motor (`PricingService::getActiveOverride()`) nunca miró esas fechas — el test pasaría contra el código roto y sería teatro.
2. **Cambiar el `ArticlePrice` de catálogo no cambia la siguiente factura.**
3. **Catálogo ≠ contrato → se factura al contrato.**
4. **Sin ningún `ArticlePrice` para esa frecuencia → se factura igualmente al contrato.** Antes del fix esto emitía **cero** (el `?? 0.0` de `PricingService:105`); es el rojo más elocuente de la tanda.
5. **Dos servicios idénticos —mismo cliente, mismo artículo, misma frecuencia— conservan precios distintos** y cada uno se factura al suyo.
6. **`effective_price = 0` se factura a cero.** Blindaje explícito contra un futuro `if (! $price)` que confunda cero con ausente.
7. **El motor nunca invoca el refresco** ni consulta catálogo u overrides durante la emisión.

**Feature — la revisión es del consumidor (D3/D4):**

8. **Llamar explícitamente a `updateEffectivePrice()` sí cambia las facturas futuras.**
9. **Una revisión posterior no altera las facturas ya emitidas.**

**Unit — el guard de D7:**

10. **`createPricingDetailsForContract()` con `effective_price === null` lanza `MissingContractPriceException`.** Modelo transitorio o incompleto: **no** se persiste una fila nula ni se debilita el `NOT NULL` de la columna.
11. **`updateEffectivePrice()` sin override ni precio de catálogo lanza y conserva el precio anterior** — ésta sí contra fila persistida, que es donde la garantía importa.

**Condiciones de fixture que Codex demostró necesarias** (sin ellas varios de arriba son falsos verdes):

- **Test 1** — un solo override, y la mutación es `custom_price` o `is_active`. Con `valid_to` no da rojo: el resolver del motor nunca miró esas fechas.
- **Test 2** — **sin override**. Con override activo, hoy el override enmascara el cambio de catálogo y el test pasa contra el código roto.
- **Tests 3 y 4** — sin override, y contrato distinto de cero.
- **Test 6 (cero)** — **conservar un precio de catálogo NO cero** y almacenar contrato cero. Si se quita el catálogo, hoy el `?? 0.0` también factura cero y el test es falso verde.
- **Test 7** — no puede afirmar «el motor no consulta catálogo»: `shouldGenerateInvoice()` seguirá leyendo `ArticlePrice` para `billing_days_in_advance` y la selección seguirá haciendo eager-load de `currentOverride`. Se acota a **«ninguna consulta de catálogo u override decide el importe»**: que la construcción del importe no invoca `createPricingDetailsForService()`, `getPriceFor()` ni `getActiveOverride()`.
- **Test 10** — antes de existir el método, el rojo es «símbolo inexistente», no el guard. Se implementa primero el método sin guard, o se demuestra con mutación.

**Tests de caracterización, no de regresión** (verdes hoy y verdes después — se conservan porque fijan el contrato, pero **no** prueban D1 y no cuentan en la prueba de sensibilidad):

- **8** — hoy `updateEffectivePrice()` y el motor dinámico resuelven el mismo candidato, así que el efecto coincide por casualidad.
- **9** — las líneas emitidas ya son inmutables.

**Tests que la release rompe y hay que arreglar en el mismo PR:**

- **`tests/Unit/Services/RecurringBillingServiceTest.php:330`** — *«applies customer price overrides to invoices»*: artículo a 2900, override a 2400, y un `ArticleServiceStatus::factory()->create()` que **no fija `effective_price`**, heredando el default 2900 del factory. Hoy pasa porque el motor deriva 2400; con D1 facturará 2900 y falla. Se convierte en un contrato de 2400 fijando `effective_price` **y** `current_override_id`, y se renombra para que deje de afirmar resolución dinámica. Es el defecto institucionalizado en la suite, igual que el `pest-testing` de la lección de familias.

**Test que falta y Codex reclama con razón:** ninguno fija el JSON exacto persistido en la línea. Se añade uno que verifique `base_price`, `applied_price`, `pricing_rule`, ambos descuentos y `override_id` en la línea emitida, más el round-trip por `InvoiceItemMetadata::fromArray()`.

**Test de D12:** emitir un periodo → revisar el precio del contrato → cancelar dentro de ese periodo → la liquidación se calcula sobre el importe de la **factura original**, no sobre el precio revisado.

**Prueba de sensibilidad:** revertir el fix debe poner rojos los tests de regresión (1-7 y el de D12) y **ninguno** de los de caracterización. La redacción anterior —«revertir pone rojos exactamente los once»— era falsa y queda retirada.

## 7. No objetivos

- No se toca el modelado de cadencia (AID-952), modalidad (AID-953), cierre (AID-949) ni no-renovación (AID-958). AID-956 sale del cluster de representación precisamente porque su decisión **ya estaba tomada**.
- No se corrige el comentario de la migración (D8).
- No se backfillea nada (D9).
- No se modela la revisión de precio programada (§4).

## 8. Hallazgos colaterales (fuera de scope, para ticketear)

- **Dos resolvers de override con semánticas distintas.** `PricingService::getActiveOverride()` (`:59-65`) filtra solo `is_active` + cliente; `Article::getActiveOverrideFor()` (`:375-389`) filtra además `valid_from`/`valid_to`. Ninguno ordena, así que ambos hacen `->first()` sobre el recorrido del índice — eco de AID-601/ADR-012 en la tabla vecina. Tras este fix el primero **deja de decidir importes recurrentes**, pero sigue gobernando la contratación, la cotización y el preview de la revisión de D3. Ticket propio: unificar en un único resolver con vigencia y orden determinista.
- **`updateEffectivePrice()` funciona por accidente** (`ArticleServiceStatus.php:294`): escribe `$override->custom_price` sin operador null-safe mientras la línea siguiente sí usa `$override?->id`. Con `$override === null`, PHP 8 emite un warning «Attempt to read property on null», evalúa a `null` y el `??` lo captura. Debe ser `$override?->custom_price`. **Entra en este fix**, porque D7 reescribe ese método de todas formas.
- **El paquete no ofrece camino de alta de `ArticleServiceStatus`.** Ninguna clase de `src/` lo crea. Es coherente con que el consumidor sea quien contrata, pero conviene que el ADR lo diga: refuerza por qué el motor no puede «recalcular» un precio que nunca fijó.

## 9. Entregables

- Fix en `RecurringBillingService` + `PricingService::createPricingDetailsForContract()`, y docblock aclaratorio en `createPricingDetailsForService()` (D6).
- `MissingContractPriceException` (`@api`) y endurecimiento de `updateEffectivePrice()`, incluido el null-safe de §8 (D7).
- **ADR-013** con D1-D5 y la contrapartida de §4; corrección de `ARCHITECTURE.md:506` (D5).
- 11 tests + prueba de sensibilidad (§6).
- Entrada de CHANGELOG bajo `### Fixed`, con el cambio de importes en negrita y la **consulta de impacto** de D11 — sin comando, sin la palabra «drift» y sin prometer equivalencia con el precio anterior.

## 10. Adjudicación de la ronda del propietario (rev 1 → rev 2)

Cada hallazgo, verificado contra el código antes de aceptarse, y dónde aterrizó:

| # | Hallazgo del propietario | Verificación | Dónde aterriza |
|---|---|---|---|
| 1 | La razón de D6-B no es que sea la única herramienta de preview — `PricingService:100` ya cotiza artículo+frecuencia+cliente. La razón correcta es que cambiar semántica `@api` exige major | **Confirmado.** `createPricingDetails()` es público en `:100`; `docs/api-surface.md:7` dice literal *«changed signatures/semantics → major»* | D6 reescrita: se retira el argumento falso, entra el de gobierno |
| 2 | `current_override_id` no garantiza procedencia permanente: la FK es `nullOnDelete` | **Confirmado**, migración `:53` | Matiz documental añadido a D6 |
| 3 | «Ausente» en D7 significa exclusivamente `null`; cero es válido y se factura a cero | Aceptado — evita el `if (! $price)` futuro | D7 reescrita + test 6 de §6 |
| 4 | La columna es `NOT NULL`: no debilitar el esquema ni fabricar una fila nula; el guard se prueba unitariamente | **Confirmado**, migración `:50` sin `->nullable()` | D7 + tests 10/11 de §6 |
| 5 | Rechazar el comando permanente: la diferencia catálogo↔contrato es estado válido, no invariante rota; y un comando Console es superficie `@api` | **Confirmado**: `DiagnosePriceOverlapsCommand` lleva `@api` declarado | D11 reescrita: solo consulta de impacto |
| 6 | No puede existir un SQL equivalente al motor anterior: `getActiveOverride()` usa `first()` sin orden y **no aplica `valid_from`/`valid_to`** | **Confirmado, y es peor de lo descrito.** `scopeActive()` (`:96-99`) es solo `is_active`; `scopeValidAt()` (`:106`) nunca se invoca desde ahí. El motor viene aplicando overrides caducados y no vigentes | §1 (cuarta consecuencia) + §8 |
| 7 | El test de expirar `valid_to` no sería sensible: hay que mutar `custom_price` o `is_active` | **Confirmado** — se deriva de 6. Habría sido un test verde contra el código roto | Test 1 de §6, con el aviso explícito |
| 8 | La afirmación sobre el `grep` no es literal: existe la escritura de `ArticleServiceStatus:286` | **Correcto, era impreciso.** La formulación buena es «ningún camino de emisión **lee** `effective_price`» | §1, primer párrafo |
| 9 | Los casos 4/7 y 1/8 son duplicados funcionales | Aceptado | §6 reorganizada: los dos pares se funden y el hueco lo ocupan los casos nuevos (cero, y el guard unitario). Sigue en 11 casos, ahora 7 feature + 2 revisión + 2 unit, sin solapes |

## 11. Ronda adversarial de Codex (rev 2 → rev 3)

11 [P1] y 6 [P2], corrida de 2026-08-17. Los P1 se verificaron contra el código antes de aceptarse. Dónde aterrizó cada uno:

| Hallazgo | Verificado | Aterriza en |
|---|---|---|
| El reembolso rompe la promesa de «misma base»: `calculateRefund()` lee el precio vivo | **Sí** (`ServiceLifecycleService.php:131`) | **D12** (nueva) |
| D2 falsa: `ArticlePrice.billing_days_in_advance` sigue alterando contratos vivos | **Sí** (`RecurringBillingService.php:308` → `Article::getBillingDaysInAdvanceFor()`) | D2 acotada a «el importe» |
| D6 atribuye procedencia que el esquema no garantiza (ambos campos `fillable`) | **Sí** (`ArticleServiceStatus.php:62-77`) | D6, etiqueta de observación |
| Un test existente se rompe y el plan no lo menciona | **Sí** (`RecurringBillingServiceTest.php:330`) | §6, tests que rompe |
| D10 debería ser MAJOR | **Verificado y NO aceptado** — la cláusula específica de `STABILITY.md` manda sobre la genérica de `api-surface.md` | D10, justificación escrita |
| Test 7 no puede afirmar «el motor no consulta catálogo» | **Sí** | §6, acotado al importe |
| Tests 8 y 9 son verdes hoy | **Sí** | §6, separados como caracterización |
| Test 6 es falso verde si se quita el catálogo | **Sí** (`PricingService.php:105`, `?? 0.0`) | §6, condición de fixture |
| Test 10 falla por símbolo inexistente, no por el guard | **Sí** | §6, condición de fixture |
| «Revertir pone rojos exactamente los once» es falso | **Sí**, se deriva de los anteriores | §6, redacción retirada |
| Falta test del JSON exacto persistido | **Sí** (el actual solo mira claves de primer nivel) | §6, test añadido |
| «Sin backfill» correcto, «sin reconciliación» no | Aceptado parcialmente | D11 ya entrega la consulta de impacto; la reconciliación es acto del consumidor |
| [P2] `contract_price` no rompe consumidores | **Confirmado** (`pricingRule` es `?string` sin enum; los PDF no leen `metadata`) | — |
| [P2] El guard de D7 está muerto en la emisión normal | **Confirmado** | D7 ya lo describe como defensa de API pública |
| [P2] El dry-run nunca construye `PricingDetails` | **Confirmado** (`RecurringBillingService.php:118`) | §5, acotar «se cuenta como failed» a emisión real |
| [P2] `updateEffectivePrice()` no es atómica | **Confirmado** (sin transacción ni lock) | §8, ticket propio |
| [P2] «árbol limpio» es falso | **Confirmado** | Cabecera corregida |
| [P2] El dato 51/154 × 3,6 no es reproducible en el repo | **Confirmado** | Marcado en §1 como dato del ticket, externo al repositorio |

## 12. Estado

**Aprobado para implementar (rev 3).** Doce decisiones cerradas con veredicto del propietario, once hallazgos adversariales adjudicados, y el dominio de liquidación acotado y sacado a **ADR-014**.

Siguiente paso: TDD, rojo primero, empezando por el **test 4 de §6** —contrato con precio y artículo sin `ArticlePrice` para esa frecuencia, que hoy emite **cero**— por ser el rojo más inequívoco de la tanda.
