# Diseño AID-601: la invariante de precios por frecuencia se garantiza donde se escribe

> **Status**: Approved (pendiente de plan de implementación)
> **Date**: 2026-07-21
> **Issue**: AID-601
> **Relates**: ADR-004 (precios por frecuencia — es su promesa la que aquí se cumple), AID-570 (el retry pertenece a quien abre la transacción exterior), ADR-006 (UUID-first), ADR-007 (stub derivado del `.php`), AID-398/AID-412 (inmutabilidad de migraciones publicadas — ver D6), AID-413 (taxonomía `@api`/`@internal`), lección 2026-07-11 (el bump semver lo decide la superficie añadida, no la etiqueta «bugfix»).
> **ADR**: la decisión se registrará como ADR-012 (slot libre; el último aceptado es ADR-011), redactado junto a la implementación.
> **Versión objetivo**: `v6.8.0` (MINOR — superficie pública añadida: excepción, servicio, comando). Cero migraciones: el upgrade manifest no se toca.

## 1. Problema

El índice `article_prices_unique` (`(article_id, billing_frequency, valid_from)`) declara en su comentario «one active price per article+frequency at any time» y no lo garantiza:

- `valid_from` es nullable; en MySQL los NULL no colisionan en un unique → N filas sin fecha de la misma (article, frequency) coexisten.
- El índice ignora `is_active` e ignora `valid_to` → aun cerrando el NULL, dos precios vigentes con rangos solapados (valid_from distintos, valid_to abiertos) caben igualmente.

Es un bug de dinero, no de higiene de datos: `Article::getPriceFor()` (`src/Models/Article.php:307-311`) resuelve con `->value('price')` **sin ORDER BY**; ante duplicados vigentes devuelve uno arbitrario según el recorrido del índice, y ese valor alimenta `PricingService::getEffectivePrice()` (`src/Services/PricingService.php:29`) → importes de líneas de factura no deterministas.

La invariante existe hoy **tres veces en lectura** (`activePrices()` en `src/Models/Article.php:136`, `scopeCurrentlyValid` e `isValidAt()` en `src/Models/ArticlePrice.php:115-174`) y **una vez en la UI del consumidor de referencia** (Clientes —proyecto de Castris— aborta en `ArticleEdit::save()` con «No puede haber dos precios con la misma frecuencia»). Falta exactamente donde debería estar: en la escritura del paquete. El consumidor está supliendo a mano una garantía que el paquete promete en un comentario y no cumple.

## 2. Invariante

> Para toda fecha *t*, a lo sumo una fila con `is_active ∧ (valid_from ≤ t ∨ NULL) ∧ (valid_to ≥ t ∨ NULL)` por `(article_id, billing_frequency)`.
> Equivalentemente: los intervalos de las filas **activas** de una misma frecuencia nunca se intersecan (NULL = extremo abierto).

No es ambición nueva: es exactamente la unicidad que la lectura ya presupone. El trabajo es cerrar la escritura para cumplir lo que `activePrices()` asume. Las filas inactivas nunca participan: son histórico deshabilitado y no bloquean nada. Los rangos disjuntos (precio 2024, precio 2025) son price-history legítimo y se permiten.

## 3. Decisiones

- **D1 — La garantía vive en la aplicación, no en DDL.** Ningún índice unique de MySQL expresa «vigente en una fecha dada» (sin exclusion constraints ni índices parciales reales). El índice físico se deja quieto (§5 para las alternativas descartadas).
- **D2 — Red de seguridad en el modelo:** hook `saving` en `ArticlePrice` que detecta solape (excluyéndose a sí mismo con `whereKeyNot`) y lanza `OverlappingArticlePriceException`. Muerde en `prices()->create()`, que es por donde escribe Clientes. Best-effort: sin transacción no hay garantía bajo concurrencia, y se declara así (§8).
- **D3 — API recomendada:** servicio de escritura `ArticlePriceService`, gemelo de `PricingService`, que abre transacción y toma `SELECT … FOR UPDATE` **sobre la fila del artículo** antes de validar y escribir. Ahí sí hay garantía (§4.3, incluido por qué el lock no es sobre las filas de precios).
- **D4 — Lectura determinista:** `getPriceFor()` añade `orderByDesc('valid_from')->orderByDesc('id')` — ante duplicados legacy, «el más reciente» de forma predecible en vez de lo que devuelva el índice. Commit propio dentro del mismo PR: es el mismo defecto de dinero. Es un no-op sobre datos válidos.
- **D5 — Diagnóstico de datos existentes:** comando read-only `larabill:diagnose-price-overlaps` que lista los solapes y devuelve exit code no-cero si los hay (gate de CI pre-upgrade). La misma query se documenta en UPGRADE. No hay migración correctora: elegir qué precio gana es decidir dinero por el consumidor, y eso no nos toca (§7).
- **D6 — El comentario del índice NO se corrige.** La migración está en el manifiesto de publicación con su `sha256`, y `ShippedMigrationImmutabilityTest` (AID-398/AID-412) exige byte-identidad de toda migración publicada: editar el comentario la rompe, y su lista de divergencias toleradas debe permanecer vacía por política. La inmutabilidad de lo publicado pesa más que la exactitud de un comentario. La verdad se documenta donde sí es editable: ADR-012, docblock de `ArticlePrice` y docblock del servicio (§4.6).
- **D7 — MINOR `6.8.0`:** el bump lo decide la superficie añadida (excepción, servicio, comando). No se rompe ningún comportamiento *correcto*: se rechaza uno que producía estado inválido y facturación no determinista, contraviniendo una invariante que el paquete ya prometía y que Clientes ya validaba en su UI (§6).
- **D8 — Rechazar y punto.** Sin modo de reemplazo: YAGNI (ningún consumidor lo pide; Clientes usa delete+create), el price-history ya es expresable con dos escrituras explícitas (cerrar `valid_to` del vigente + crear el nuevo), y la mutación implícita de filas ajenas es la clase de magia que luego factura mal. Reapertura documentada si aparece un consumidor que lo pida.

## 4. Detalle de diseño

### 4.1 La condición de solape (fuente única)

Un candidato con intervalo `[vf, vt]` solapa con una fila existente `[valid_from, valid_to]` si y solo si:

```
(vf IS NULL ∨ valid_to IS NULL ∨ vf ≤ valid_to)
∧ (valid_from IS NULL ∨ vt IS NULL ∨ valid_from ≤ vt)
```

Scope compartido en `ArticlePrice`: `scopeOverlapping(int $articleId, BillingFrequency $frequency, ?Carbon $vf, ?Carbon $vt)` que usan el hook y el servicio — una sola implementación de la intersección, con la matriz de NULLs probada una vez. Ámbito del filtro: mismo `article_id`, mismo `billing_frequency`, `is_active = true`.

**El scope es puro: la auto-exclusión NO vive dentro.** Excluir la propia fila es estado del modelo que se guarda, no parte de la definición de solape; meterlo dentro contamina el scope y el comando —que compara pares ya existentes— no podría reutilizarlo limpiamente. La exclusión la aplica el hook sobre el scope (§4.2).

El comando tiene query propia (busca **pares** existentes: self-join con `A.id < B.id`, ambas activas, misma condición de intersección) — relacionada, pero no es la misma forma que la del candidato.

### 4.2 Red del modelo

- Hook `saving` en `ArticlePrice`. Solo evalúa cuando la fila que se guarda es (`is_active = true`): guardar una fila inactiva nunca puede violar la invariante.
- Cubre create y update — incluida la reactivación (`is_active` false→true), que es un camino de solape tan real como cualquier create.
- Lanza `OverlappingArticlePriceException` (nueva, en `src/Exceptions`, `@api`). El mensaje incluye: `article_id`, `billing_frequency`, y para cada fila conflictiva su `id`, `valid_from`, `valid_to` — más la referencia a `larabill:diagnose-price-overlaps`. En el momento del rechazo, el consumidor ya sabe qué limpiar (§7).
- **Auto-exclusión explícita:** al actualizar, la fila que se guarda no puede ser su propio conflicto. Se aplica sobre el scope con `when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))`. Nota para quien lo lea después: no es la corrección de un fallo — `whereKeyNot(null)` no produce `id != NULL`, porque el query builder reescribe un valor nulo con operador `!=` a `IS NOT NULL` (`Query/Builder.php:989-990`), que toda fila persistida cumple. La forma condicional se elige por legibilidad, para no depender de una reescritura que casi nadie conoce.
- Redundancia deliberada: el servicio (D3) valida con lock y luego escribe, y el hook vuelve a validar en el `save`. Coste despreciable; la red del modelo es la última línea para todo camino Eloquent.
- **Coste, declarado:** `is_active` es `true` por defecto en el esquema, así que en la práctica casi todo `save` de `ArticlePrice` paga una consulta extra. Es asumible y no se optimiza por adelantado, pero queda escrito en el ADR para que nadie lo descubra como sorpresa en un seeder grande.

### 4.3 Servicio de escritura

`ArticlePriceService` (`src/Services`, `@api`), gemelo de `PricingService` (lectura). Operación propuesta: `setPrice(Article $article, BillingFrequency $frequency, FixedDecimal $price, ?Carbon $validFrom = null, ?Carbon $validTo = null, ?int $billingDaysInAdvance = null): ArticlePrice`.

Secuencia: `DB::transaction()` → `lockForUpdate()` sobre **la fila del artículo** → validación de solape (§4.1) → escritura.

**Por qué el lock es sobre el artículo y no sobre las filas de precios:** `FOR UPDATE` bloquea solo filas existentes que matchean el WHERE. La primera inserción de una frecuencia (cero filas) no toma lock alguno — dos creates concurrentes del primer precio se cuelan ambos. La fila de `articles` siempre existe; bloquearla serializa toda escritura de precios de ese artículo, cubra o no filas existentes.

**Sin retry/attempts (AID-570):** Clientes ya llama dentro de su propia `DB::transaction`; un `attempts` interno sería savepoint e inútil. El servicio ofrece el lock; el retry ante deadlock pertenece a quien abre la transacción exterior. Se documenta en la docblock del servicio. La transacción anidada es segura: si el llamador ya abrió una, el servicio se integra (savepoint) y los locks se mantienen hasta el commit exterior.

### 4.4 Lectura determinista

`getPriceFor()` añade `orderByDesc('valid_from')->orderByDesc('id')` antes de `value('price')`. En MySQL los NULL ordenan como mínimos, así que un `valid_from` NULL (precio legacy sin fecha) queda después que cualquier fecha concreta — heurística aceptable para estados que ya son inválidos; el objetivo es determinismo, no adivinación. Sin efecto sobre datos válidos (una sola fila vigente → mismo resultado).

### 4.5 Comando de diagnóstico

`DiagnosePriceOverlapsCommand`, firma `larabill:diagnose-price-overlaps`. Read-only, sin flags destructivos. Salida: tabla con `article_id`, `billing_frequency`, y por cada par solapado los `id`, rangos y precios de ambas filas. Exit code `0` sin solapes, `1` con solapes — usable como gate en el pipeline del consumidor antes de actualizar. Idempotente por construcción.

### 4.6 Comentario del índice: por qué se queda mintiendo

La corrección obvia —que el comentario declare lo que el índice sí garantiza— **no es viable**, y conviene dejar escrito el porqué para que no se reintente por pulcritud.

`2025_01_20_000004_create_article_prices_table.php` figura en `tests/Contract/release-migration-manifest.json` con `in_base: true` y `sha256: 599359d9…`. `ShippedMigrationImmutabilityTest` compara ese hash contra HEAD y su constante de divergencias toleradas lleva escrito que debe permanecer vacía y que jamás se añada una entrada para silenciar un fallo: el cambio se entrega como migración nueva, o no se entrega. Aquí no hay migración nueva que valga —el DDL es correcto, lo que sobra es una frase— así que la única salida sería la prohibida.

Verificado empíricamente: aplicando el cambio y ejecutando `bin/sync-migration-stubs`, `ShippedMigrationImmutabilityTest` falla con «content changed», **mientras `MigrationOrderConsistencyTest` pasa** — porque el sync restablece la byte-identidad `.php`↔`.stub`, que es lo único que ese gate vigila (su propia cabecera lo dice: los stubs se cubren transitivamente). Verificar este cambio con `MigrationOrderConsistencyTest` daría verde en local y rojo en CI.

Dónde va la verdad, entonces:

- **ADR-012** — la decisión y su tensión: la exactitud de un comentario no justifica romper la inmutabilidad de lo publicado (AID-398/AID-412). Referencia ADR-004, cuya promesa es la que este trabajo cumple.
- **Docblock de `ArticlePrice`** — qué garantiza el índice (unicidad física de la terna, nada sobre vigencia) y qué garantiza la capa de aplicación.
- **Docblock del servicio** — la garantía real y su límite de concurrencia (§8).

## 5. Opciones descartadas (§8)

- **Cerrar el NULL en DDL** (NOT NULL con default sentinela, o columna generada): coste de esquema publicado (nuevo stub, entrada en `$migrationOrder`, riesgo sobre datos existentes de consumidores al migrar), migra la semántica «NULL = desde siempre» que vive en cuatro puntos del modelo (`activePrices`, `scopeValidAt`, `scopeCurrentlyValid`, `isValidAt`) — y **sigue sin cerrar el solape de rangos**. Pagar esquema sin cerrar el riesgo de negocio.
- **Unique endurecido incluyendo `is_active`:** ningún unique de MySQL expresa «vigente en una fecha dada». Y el día que exista una fila inactiva de esa frecuencia sin fecha, un unique que muerda `valid_from` hace reventar el guardado de artículos de Clientes — exactamente lo que §9 obliga a evitar.
- **Exclusion constraint:** no existe en MySQL (PostgreSQL sí); larabill soporta MySQL.
- **Modo reemplazo** (cierre automático del `valid_to` vigente): D8. Reapertura documentada si un consumidor lo pide.

## 6. Compatibilidad con Clientes (§9)

Evidencia, no esperanza:

- Clientes escribe con `prices()->where('is_active', true)->delete()` + `create()` (`ArticleEdit.php:188-194`, `ArticleCreate.php:150-160`). Borra las activas antes de crear → la validación nueva **pasa** sin tocar Clientes.
- Clientes ya aborta duplicados en su UI (`ArticleEdit::save()` líneas 166-169): la invariante no le es nueva, la está supliendo.
- Las filas inactivas nunca cuentan como solape → el escenario «inactiva sin fecha» no rompe nada.
- §14: conformidad de Clientes contra el commit candidato exacto antes de mergear.

## 7. Datos existentes (§13)

No hay migración correctora: elegir qué precio gana entre duplicados es decidir dinero por el consumidor. El camino es triple:

1. **Proactivo:** `larabill:diagnose-price-overlaps` (gate de CI, exit no-cero) + la query documentada en UPGRADE.
2. **Reactivo:** la excepción del hook referencia el comando y lista las filas conflictivas — el consumidor que no leyó UPGRADE descubre el camino en el propio error.
3. **Lectura nunca bloqueada:** la facturación sigue funcionando sobre datos duplicados, ahora de forma determinista (D4). Facturas ya emitidas: inmutables (ADR-001), fuera de alcance — el fix es forward-only.

## 8. Concurrencia: límites declarados

El hook del modelo, solo, **no** es garantía bajo concurrencia: dos procesos pueden validar a la vez y escribir a la vez. La garantía existe únicamente escribiendo a través del servicio (lock sobre la fila del artículo). El ADR lo dirá así, sin vestir la red de lo que no es.

## 9. Superficie pública añadida

Tres entradas `@api` nuevas en `docs/api-surface.md`: `OverlappingArticlePriceException`, `ArticlePriceService`, `larabill:diagnose-price-overlaps`. El hook es comportamiento, no superficie. D4 es cambio de comportamiento visible solo en estados inválidos.

## 10. Plan de pruebas

### 10.0 Paso cero: barrido de fixtures que ya violen la invariante

**Antes de escribir código.** Instalar el hook pone en rojo cualquier fixture existente que cree dos precios activos solapados de la misma frecuencia. No sería un problema —sería la señal que buscamos— pero descubrirlo a mitad de la implementación confunde el diagnóstico. Se barre primero y se decide fixture a fixture.

`ArticlePriceFactory` asigna `article_id => Article::factory()->withoutPrices()`, así que cada precio de factory nace con artículo propio y los tests planos no pueden violar la invariante. Los objetivos reales del barrido son otros:

- la creación de precios por defecto de `ArticleFactory` (el `withoutPrices()` implica que existe): verificar que no apila dos activos de la misma frecuencia;
- tests que compartan un `article_id` explícito entre varios precios;
- flujos completos que escriben precios de rebote: `RecurringBillingService`, `PricingService`.

### 10.1 Casos

- **Matriz de intersección:** combinaciones NULL/no-NULL en `valid_from`/`valid_to` del candidato × de la existente, tocar/no tocar — unit tests del scope §4.1.
- **Hook:** create solapado rechazado; update de la propia fila permitido (`whereKeyNot`); reactivación que solapa rechazada; fila inactiva nunca bloquea; **delete+create patrón Clientes pasa** (regresión §9).
- **Servicio:** lock sobre el artículo y escritura correcta (integración MySQL según CONTRIBUTING); la excepción propaga.
- **Comando:** exit 0/1; la salida lista los pares solapados; read-only verificado.
- **Lectura:** `getPriceFor()` determinista ante duplicados (mayor `valid_from`, desempate mayor `id`).
- **Excepción:** el mensaje incluye IDs, rangos y la referencia al comando.
- **Contrato de migraciones:** `ShippedMigrationImmutabilityTest` y `MigrationOrderConsistencyTest` siguen verdes **sin tocarse**, porque no se edita ninguna migración (D6).
- **Taxonomía de superficie:** `SurfaceTaxonomyTest` exige clasificar las tres piezas nuevas (excepción y servicio `@api`; el comando, superficie de consola).

## 11. Contrato de migraciones

Intacto. No hay migración nueva, ni cambio de DDL, ni cambio de comentario (D6, §4.6). No se ejecuta `bin/sync-migration-stubs`, `$migrationOrder` no se toca, el upgrade manifest no se regenera y los conteos fijados de instalación quedan igual.
