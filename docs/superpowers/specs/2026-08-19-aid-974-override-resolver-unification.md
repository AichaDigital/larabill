# Diseño AID-974: un único resolver de `ArticleOverride`, con vigencia, orden determinista e invariante de no-solape

- **Ticket:** AID-974 (High) — segregado de AID-956 §8.
- **Base:** `main` @ `6ea9e11` (v6.13.0 publicada).
- **Estado:** **rev 3** — pendiente de revisión del propietario. Dos rondas adversariales adjudicadas en §8 (Codex) y §9 (segunda ronda). Todos los hallazgos verificables comprobados contra el código antes de aceptarse. **Veredicto de la segunda ronda: HOLD** — resuelto en esta revisión.
- **Decisión de alcance:** opción **B** (lectura determinista **+** invariante dura), aprobada por el propietario el 2026-08-19 con los límites de §2.
- **Relacionado:** ADR-012 (invariante de no-solape de `article_prices`) es el precedente estructural directo. ADR-013 (`effective_price` es estado contractual) explica por qué esto ya no decide importes recurrentes.
- **Enmienda 1 (2026-08-20, Abdelkarim Mateos):** el entregable de §7 sobre `getActiveOverrideFor()` describía la rev 1 y contradecía la §D3 que el propio documento fijó en rev 2. Corregido **solo** en §7, anotado como enmienda; el resto del spec no se toca. Origen: pase de correcciones pre-merge de AID-974 (F8).

## 1. El defecto, y su alcance real medido

El paquete resuelve «el override activo de este cliente para este artículo» por **dos caminos que no significan lo mismo**:

- **`PricingService::getActiveOverride()`** (`:60`) → `->active()->forCustomer($id)->first()`. `ArticleOverride::scopeActive()` (`:96-99`) es **solo** `where('is_active', true)`: no aplica `valid_from` ni `valid_to`.
- **`Article::getActiveOverrideFor()`** (`:375-389`) → sí aplica la ventana de vigencia, pero **reimplementándola a mano** con dos closures.

Y **`ArticleOverride::scopeValidAt()`** (`:106`) implementa exactamente esa ventana: `grep -rn "validAt" src/` devuelve **cero resultados**. Existe el mecanismo correcto y ninguna ruta lo usa — uno lo ignora y el otro lo duplica.

**Ninguno de los dos ordena.** Ambos terminan en `->first()` sin `ORDER BY`, así que ante varios candidatos gana el que devuelva el recorrido del índice. Es el mismo defecto que ADR-012 cerró en la tabla vecina, y el esquema lo permite igual: el unique es `(customer_id, article_id, valid_from)` con `valid_from` **nullable**, y los NULL duplicados no colisionan.

### Lo que la medición dice, y por qué se hace AHORA

Medido el 2026-08-19, no estimado:

- En **`clientes`** (repo entero, `origin/main`): las dos únicas coincidencias de `PricingService`/`ArticleOverride` están en **documentación** (`schema.dbml`, `docs/domains/billing.md`). **Ningún código invoca los siete métodos.**
- En **`larafactu`**: `article_overrides` tiene **0 filas**; **0** acuerdos con `current_override_id`.

El defecto es **real en el código e inerte en la práctica**. Esa medición es justamente la razón de hacerlo ahora: sin overrides que migrar y sin consumidores que romper, la invariante **no necesita** backfill, ni colapso de duplicados, ni comando de diagnóstico — toda la maquinaria que ADR-012 sí tuvo que construir. Después de que entren los 583 acuerdos de la migración WHMCS, decidir qué override sobrevive sería decidir dinero del consumidor.

## 2. Límites del encargo (fijados por el propietario)

Vinculantes. Cualquier desviación vuelve a revisión.

- **Sin migraciones, sin backfill, sin cambios de esquema.** La garantía vive en la capa de aplicación, como en ADR-012.
- **Una única definición de vigencia y de solape**, en `ArticleOverride`.
- **Los métodos públicos actuales conservan su firma** y delegan en ese resolver.
- **Lectura determinista + rechazo de solapes.**
- **Servicio de escritura con lock sobre `articles`**, siguiendo el patrón de ADR-012 §D3.
- **Nada de abstracciones temporales genéricas ni arquitectura «para v7».**

## 3. Decisiones

### D1 — `ArticleOverride::scopeOverlapping()` es la fuente única del solape

Simétrico a `ArticlePrice::scopeOverlapping()` (ADR-012 §D2): las filas **activas** de ese `(cliente, artículo)` cuyo intervalo interseca el rango candidato, con `NULL` como extremo abierto en ambos lados.

Una sola definición, dos consumidores: el hook de guardado (D4) y el servicio de escritura (D5). Ninguno reimplementa la condición.

### D2 — `scopeValidAt()` deja de ser código muerto y pasa a ser el único filtro de vigencia

`Article::getActiveOverrideFor()` deja de duplicar la ventana con closures y la obtiene del scope. La ventana de vigencia pasa a tener **una sola definición SQL** en el paquete.

*Precisión de rev 2:* «una única definición» a secas no sería cierto — `ArticleOverride::isValidAt()` (`:141`) seguirá siendo un predicado PHP sobre un modelo ya cargado, que es otra cosa que una cláusula de consulta y no se puede retirar sin tocar superficie pública. Lo que se unifica es **la consulta**; el predicado se deja como está y se añade un test que fija su equivalencia con el scope para los mismos datos.

### D2bis — La ventana de vigencia es INCLUSIVA por día, y hoy no lo es en ningún motor

**Hallazgo de la segunda ronda, verificado empíricamente y más grave de lo reportado.**

`valid_from` y `valid_to` son columnas **`date`** (migración `:32-33`) con cast `'date'`, pero `scopeValidAt(Carbon $date)` y `Article::getActiveOverrideFor()` comparan contra un **instante con hora** (`now()`). El resultado medido:

| Comparación | SQLite | MySQL |
|---|---|---|
| `valid_to = 2026-08-19` vs `2026-08-19 21:08:00` | CADUCADO | CADUCADO |
| `valid_to = 2026-08-19` vs `2026-08-19 00:00:00` | **CADUCADO** | VIGENTE |

Un override cuyo último día de vigencia es hoy **deja de aplicarse durante todo el día de hoy**. En SQLite ni siquiera a medianoche, porque la comparación es lexicográfica sobre texto y `'2026-08-19'` ordena antes que `'2026-08-19 00:00:00'`. Es además una **divergencia de motor**, la clase de defecto que este paquete ya conoce (AID-836).

Esto obliga a fijar la semántica antes de canonizar nada — no se puede declarar «fuente única» un scope defectuoso:

- **`valid_from` y `valid_to` son inclusivos y de grano DÍA.** Un override con `valid_to = D` es válido durante todo el día `D`.
- **La normalización ocurre en el scope**, comparando por fecha (`->whereDate()` o `$date->toDateString()`), nunca contra un instante con hora. Un único punto de normalización, no en cada llamador.
- **Paridad query ↔ modelo:** `isValidAt()`, `isCurrentlyValid()` e `isExpired()` (`ArticleOverride.php:141`) mantienen su propia implementación en PHP. Se les exige **el mismo resultado** que al scope para los mismos datos, con test de equivalencia en los tres bordes: `valid_from`, `valid_to` y el día siguiente.

**Es un cambio de comportamiento adicional** —un override que hoy se ignora su último día pasará a aplicarse— y va al CHANGELOG con viejo y nuevo, junto al resto.

### D3 — Un solo resolver, con orden determinista, SIN tocar firmas

`Article::getActiveOverrideFor(int|string $customerId): ?ArticleOverride` **conserva su firma exacta**. No se le añade `?Carbon $at`.

**Corregido en rev 2.** La rev 1 proponía añadir ese parámetro «sin cambiar la firma», lo cual era falso por dos motivos verificados: `tests/Contract/snapshots/Article.json:170` congela `parameters: [{name: customerId, type: string|int}]`, así que añadirlo rompe el gate de contrato; y `docs/api-surface.md:7` clasifica literalmente *«changed signatures/semantics → major»*. Además violaba el límite del propietario «los métodos públicos actuales conservan firma».

La resolución vive en un método nuevo con la fecha explícita, y el existente delega:

```php
// nuevo — resolución con instante explícito
public function resolveOverrideFor(int|string $customerId, Carbon $at): ?ArticleOverride

// existente — firma intacta, delega
public function getActiveOverrideFor(int|string $customerId): ?ArticleOverride
{
    return $this->resolveOverrideFor($customerId, now());
}
```

El resolver filtra cliente, `is_active` y vigencia a `$at` vía `scopeValidAt()`, y ordena **`valid_from` DESC, `id` DESC** — el mismo desempate que ADR-012 fijó para `article_prices`. `NULL` ordena más bajo en MySQL y SQLite, así que una fila con fecha vence a una legacy de inicio abierto. Es predictibilidad, no adivinación: el arreglo real es no tener el duplicado, que es lo que garantiza D5.

**`PricingService::getActiveOverride()` conserva su firma y delega.** Ahí está el cambio de comportamiento observable: pasa a aplicar vigencia y orden.

### D4 — El hook `saving` es la red

`ArticleOverride::saving` rechaza una fila activa que solape con otra activa del mismo `(cliente, artículo)`, lanzando **`OverlappingArticleOverrideException`** (`@api`, hermana de `OverlappingArticlePriceException`).

**Autoexclusión obligatoria en update.** El hook consulta también la fila que se está guardando, así que sin excluirla **cualquier actualización de un override activo se detectaría a sí misma como solape**. El precedente lo resuelve explícitamente (`ArticlePrice.php:107`):

```php
->when($override->exists, fn (Builder $q) => $q->whereKeyNot($override->getKey()))
```

Se copia tal cual, con sus tests de actualización sin cambio de rango y de reactivación conflictiva. *(Añadido en rev 2: la rev 1 lo omitía y habría hecho imposible editar un override.)*

Es **red, no garantía**: como en ADR-012, dos procesos concurrentes pueden pasar el hook a la vez porque cada uno consulta antes de que el otro escriba. La garantía es D5.

### D5 — `ArticleOverrideService::setOverride()` es la garantía

Servicio de escritura simétrico a `ArticlePriceService::setPrice()` (ADR-012 §D3): dentro de una transacción, **bloquea la fila padre de `articles`** con `lockForUpdate()` y luego evalúa el solape y escribe.

**Firma y alcance, fijados en rev 2** (la rev 1 no los definía, y sin ellos el servicio no es implementable):

```php
public function setOverride(
    Article $article,
    int|string $customerId,
    FixedDecimal $customPrice,
    ?Carbon $validFrom = null,
    ?Carbon $validTo = null,
    ?string $reason = null,
): ArticleOverride
```

Es **create-only**: crea una fila activa nueva y rechaza el solape. No actualiza, no reactiva y **no sustituye automáticamente** al override vigente — reemplazar en silencio sería decidir el precio del consumidor. Rechaza también `valid_from > valid_to`. Actualizar o desactivar un override existente se hace por el modelo, cubierto por la red de D4.

Se bloquea el artículo, y no los overrides, por la razón de ADR-012: `FOR UPDATE` no serializa un alta que casa con **cero** filas, así que bloquear el rango candidato no impide que dos altas disjuntas-en-lectura se pisen. La fila padre existe siempre y sí serializa.

El lock por artículo es **más amplio de lo estrictamente necesario** —dos clientes distintos del mismo artículo no pueden solapar entre sí— y se acepta a propósito: es el patrón ya probado en el paquete, y la contención real es despreciable en una tabla de alta manual.

### D5bis — El alcance de la garantía, dicho sin exagerar

**Corregido en rev 2.** «Invariante dura» era una afirmación excesiva: `ArticleOverride` es superficie `@api`, así que un consumidor puede hacer `create()`, actualizar o reactivar por el modelo, y el lock del servicio no serializa a esos escritores.

La promesa exacta es:

- **Garantía** para las altas hechas por `ArticleOverrideService::setOverride()`.
- **Red best-effort** (hook `saving`) para cualquier otra escritura: rechaza el solape que ve, pero dos escrituras concurrentes por el modelo pueden pasar a la vez.

Una garantía global exigiría una restricción de base de datos, y eso está fuera de los límites del encargo (sin cambios de esquema). Se documenta como límite conocido, no se disfraza.

### D6 — `ArticleOverrideService` nace `@api`, y es una decisión consciente

Publicar superficie `@api` compromete a conservarla o deprecarla durante **una major completa** (`STABILITY.md` regla 3). Se asume deliberadamente:

- Es **simétrico a `ArticlePriceService`**, que ya es la primitiva de escritura equivalente. Un paquete que ofrece garantía dura en una tabla y no en su hermana es incoherente.
- Marcarlo `@internal` daría una **falsa garantía**: el consumidor no tendría camino de escritura soportado, y acabaría escribiendo por el modelo — es decir, por la red (D4) y no por la garantía (D5).

### D7 — Clasificación: MINOR, documentado en `Fixed`

**Hay dos cláusulas del repositorio en tensión, y rev 2 las cita ambas** (la rev 1 solo citaba la favorable, que Codex marcó con razón):

- `docs/api-surface.md:7` — *«removed surface or **changed signatures/semantics** → major»*.
- `STABILITY.md` («What this means in practice») — un defecto encontrado en producción se corrige en **patch o minor** salvo que romper sea demostrablemente la única opción correcta.

Se resuelve así, y solo así: **ninguna firma cambia** (D3), de modo que la primera cláusula deja de aplicarse en su mitad de «signatures». Queda el cambio de *semántica*, que es exactamente el caso de AID-956 —código que deja de contradecir el comportamiento correcto— y que el propietario ya aprobó como MINOR con justificación escrita en ADR-013. Aquí romper no arregla nada que el minor no arregle.

**Condición explícita:** si durante la implementación resultara necesario tocar una firma, esta clasificación decae y hay que volver a decidir. No se fuerza el MINOR.

Las adiciones `@api` (`ArticleOverrideService`, `OverlappingArticleOverrideException`, el scope nuevo) son lo que lo hace minor y no patch. El cambio de comportamiento de `getActiveOverride()` se documenta con **viejo y nuevo** explícitos.

**Sin cambio de esquema**, así que la regla 2 de `STABILITY.md` (schema additive-only en minor) no entra en juego.

### D8 — Sin comando de diagnóstico

ADR-012 publicó `larabill:diagnose-price-overlaps` porque había bases con duplicados legacy que el paquete iba a empezar a rechazar. **Aquí no hay ninguna fila**, así que un comando de pre-upgrade no tendría nada que diagnosticar y sería superficie `@api` que mantener para siempre.

**Rev 2, matizado:** **ningún despliegue conocido tiene filas** — cero en `larafactu`, cero usos en `clientes` — lo cual no demuestra cero datos en **todos** los consumidores de una superficie `@api` — solo en los dos medidos. Esto se declara como **riesgo aceptado**, no como ausencia demostrada de necesidad.

El riesgo es tolerable porque el síntoma es explícito y accionable (`OverlappingArticleOverrideException` al guardar), no un fallo silencioso, y porque el consumidor puede detectarlo con una consulta de una línea que el CHANGELOG incluye.

## 4. No objetivos

- **No se toca el esquema.** El unique `(customer_id, article_id, valid_from)` con `valid_from` nullable **se queda como está**: no da la garantía, y esa es justamente la razón de que la garantía viva en la aplicación.
- **No se modela vigencia genérica** ni se extrae una abstracción temporal compartida con `ArticlePrice`. Dos implementaciones simétricas y legibles, no una jerarquía.
- **No se vincula a ninguna major futura.** El spec de v7 (`docs/superpowers/specs/2026-07-13-…`) sigue declarándose `APPROVED` y «constitución de v7.0.0» mientras su épica AID-459 está `Canceled` y el `CLAUDE.md` fija la línea 6.x. Esa incoherencia documental **existe y se ticketea aparte**; AID-974 no depende de ella.
- **No se retira ni deprecia** ninguno de los siete métodos actuales.

## 5. Behavior changes

- **`PricingService::getActiveOverride()` pasa a aplicar la ventana de vigencia.** Un override `is_active = true` con `valid_to` pasado, o con `valid_from` futuro, **deja de aplicarse**. Es el defecto que se corrige.
- **`hasActiveOverride()` hereda el cambio**: deja de devolver `true` por un descuento caducado.
- **`getEffectivePrice()`, `getEffectivePriceForService()`, `createPricingDetails()` y `createPricingDetailsForService()`** heredan la corrección por delegación: cotizan con el override realmente vigente.
- **Ante varios candidatos vigentes**, la elección pasa a ser determinista en vez de depender del índice.
- **Guardar un override que solape con otro activo del mismo par pasa a fallar** con `OverlappingArticleOverrideException`, donde antes se persistía en silencio.
- **`ArticleServiceStatus::updateEffectivePrice()` hereda SOLO el orden determinista.** ⚠️ **Corregido en rev 2:** la rev 1 afirmaba que por este camino se congelaba un override caducado. **Es falso** — `updateEffectivePrice()` (`:300`) resuelve por `Article::getActiveOverrideFor()`, que **ya filtra `valid_from` y `valid_to`** (`Article.php:380-387`). Por esta ruta el único defecto actual es la elección no determinista ante varios overrides vigentes. El agravante de «override caducado congelado» solo aplica al consumidor que cotiza con `PricingService` y persiste ese precio por su cuenta.
- **`RecurringBillingService` no cambia**: desde ADR-013 no consulta overrides para decidir importes.

## 6. Plan de tests (TDD, rojo primero)

**Unit — la definición de solape (D1):**

1. Dos rangos que se intersecan → `scopeOverlapping()` los encuentra.
2. Dos rangos **disjuntos** del mismo `(cliente, artículo)` → no solapan. Es el caso legítimo que distingue esta tabla de `article_prices`.
3. `NULL` como extremo abierto solapa por ese lado (inicio abierto, fin abierto).
4. Un override **inactivo** no cuenta como solape.
5. Overrides de **otro cliente** o de **otro artículo** no cuentan.

**Unit — la ventana inclusiva (D2bis):** *(añadidos en rev 3)*

5bis. Un override con `valid_to = hoy` **está vigente hoy**, a cualquier hora del día. Corre en **SQLite y MySQL**: hoy discrepan entre sí, así que un solo motor no demuestra nada.
5ter. Con `valid_from = hoy` está vigente hoy; con `valid_to = ayer` no lo está. Los tres bordes: `valid_from`, `valid_to`, día siguiente.
5quater. **Paridad query ↔ modelo:** para los mismos datos, `scopeValidAt()` e `isValidAt()` devuelven lo mismo en los tres bordes.

**Feature — la resolución (D2, D3):**

6. Un override caducado (`valid_to` pasado) **no** se aplica. ⚠️ Este es el rojo que demuestra el defecto: hoy `PricingService::getActiveOverride()` lo devuelve.
7. Un override aún **no vigente** (`valid_from` futuro) no se aplica.
8. Con dos overrides vigentes, gana **`valid_from` más reciente**; con **ambos `valid_from = NULL`**, gana el **`id` mayor**.
    - ⚠️ **Fixture, corregido en rev 2:** el desempate por `id` **no** puede sembrarse con dos `valid_from` iguales no nulos — el unique `(customer_id, article_id, valid_from)` los rechaza. Con dos `NULL` sí, porque los NULL duplicados no colisionan. Y como D4 rechaza el solape al guardar, el segundo candidato se siembra **sin eventos** (inserción directa), simulando el duplicado legacy que la lectura determinista existe para tolerar.
    - ⚠️ **Sensibilidad, corregida en rev 2:** «determinista en corridas repetidas» **no** demuestra nada — un SQL sin `ORDER BY` puede devolver la misma fila cien veces seguidas y seguir siendo incorrecto. El criterio es **mutación**: retirar los dos `orderBy` debe poner este test rojo.
9. `getActiveOverrideFor($customer, $at)` resuelve **a la fecha dada**, no a hoy.
10. **Los seis** métodos públicos de `PricingService` heredan la corrección, y se ejercitan **los seis** con un override caducado: `getEffectivePrice()`, `getEffectivePriceForService()`, `getActiveOverride()`, `hasActiveOverride()`, `createPricingDetails()` y `createPricingDetailsForService()`. *(Rev 2: la rev 1 prometía seis y exigía dos.)*

10bis. **Regresión de `updateEffectivePrice()`**: con dos overrides vigentes duplicados sembrados sin eventos, la revisión contractual escribe `effective_price` **y** `current_override_id` del ganador determinista. *(Añadido en rev 2: es el único cambio real en esa ruta y §6 no lo cubría.)*

**Feature — la red y la garantía (D4, D5):**

11. Guardar un override solapado por el modelo lanza `OverlappingArticleOverrideException`.
12. Guardar rangos disjuntos **no** lanza.
13. `ArticleOverrideService::setOverride()` escribe correctamente el caso válido.
13bis. **Rechazo y rollback del servicio** *(rev 3)*: ante un solape, `setOverride()` lanza y **no deja fila escrita** — la transacción revierte. Sin este caso, D5 solo estaría probada por su camino feliz.
13ter. Casos del hook que la autoexclusión hace posibles: guardar sin cambiar rango, actualizar a solape, reactivar una fila inactiva conflictiva, y guardar/desactivar una fila inactiva. El mensaje de la excepción nombra **IDs y rangos** en conflicto — es lo que sostiene la promesa de D8 de que el error es accionable.

**Concurrencia — que D5 sea garantía y no teatro:**

14. Fork test con N procesos creando overrides solapados del mismo `(cliente, artículo)`: **exactamente uno** sobrevive.
    - Gateado (`RUN_CONCURRENCY_IT=1` + MySQL), fuera de los testsuites por defecto.
    - ⚠️ **STD-004 es un gate contractual real de este repo, no una recomendación** *(añadido en rev 2)*. `tests/ConcurrencyGateContractTest.php` corre en la suite normal y **pone rojo** cualquier fork test nuevo que no cumpla dos requisitos: liberar a los hijos en un instante absoluto con **`time_sleep_until`** (`:69`), y **persistir la excepción real del hijo** (`:91`). Sin barrera, «N procesos» no garantiza simultaneidad y el test es teatro — y además rompe la suite.
    - **`valid_from = null` en los candidatos** *(rev 3)*: con fechas distintas el unique `(customer_id, article_id, valid_from)` haría el trabajo del lock y enmascararía el defecto — el test pasaría sin que D5 sirva de nada.
    - **`ArticlePriceConcurrencyTest` no se copia literal** *(rev 3)*: está declarado en `std004PendingBarrier()` por deuda histórica, es decir, exento del gate. Copiarlo heredaría justo lo que STD-004 prohíbe.
    - **Prueba de sensibilidad obligatoria:** retirar el lock de D5 y confirmar que escriben varios. Sin ese rojo, el test no demuestra nada (lecciones AID-264/AID-700).
    - Recuento con **suelo medido y techo declarado** por `innodb_lock_wait_timeout`; se mide **por motor**, MySQL y MariaDB no discriminan igual (AID-836).

**Prueba de sensibilidad, por mutante** *(corregida en rev 2: «revertir la delegación pone rojos 6-10» era imposible, porque el test 9 llama al resolver directamente y no pasa por `PricingService`)*:

| Mutante | Debe poner rojos |
|---|---|
| Retirar la delegación de `PricingService::getActiveOverride()` | 6, 7, 10 (y los directos de `PricingService`) |
| Retirar `scopeValidAt()` del resolver | 6, 7, 9 |
| Retirar los dos `orderBy` | 8, 10bis |
| Retirar el hook de D4 | 11 |
| Retirar el lock de D5 | 14 (fork) |
| Retirar la normalización por día de D2bis | 5bis, 5ter, 5quater |

Ningún mutante debe poner rojos los tests 1-5, que son de la definición de solape y no dependen de la resolución.

## 7. Entregables

- `ArticleOverride::scopeOverlapping()` y uso real de `scopeValidAt()`.
- **Enmienda 1 (2026-08-20):** `Article::resolveOverrideFor(int|string $customerId, Carbon $at)` **nuevo**, con orden determinista, y `Article::getActiveOverrideFor()` **conservando su firma exacta** y delegando en él con `now()`. La redacción original de esta línea —«`getActiveOverrideFor()` con `?Carbon $at`»— describía la rev 1: contradice §D3, rompería el snapshot de contrato (`Article.json`) y `docs/api-surface.md:7` clasifica el cambio de firma como **major**, que es justo lo que sostiene la clasificación MINOR de este trabajo.
- `PricingService::getActiveOverride()` delegando, misma firma.
- Hook `saving` + `OverlappingArticleOverrideException` (`@api`).
- `ArticleOverrideService::setOverride()` (`@api`) con lock sobre `articles`.
- Tests de §6, incluido el fork test gateado con su sensibilidad.
- Entrada de CHANGELOG bajo `### Fixed` con viejo/nuevo, y bajo `### Added` las adiciones `@api`.
- Actualización de `docs/api-surface.md` con la clase nueva.
- **No** se escribe ADR: esto ejecuta el patrón ya decidido en ADR-012 sobre otra tabla. Si durante la implementación aparece una decisión que ADR-012 no cubra, se para y se propone ADR.

## 8. Adjudicación de la ronda adversarial de Codex (rev 1 → rev 2)

Corrida del 2026-08-19 (`codex exec`, consult, `medium`): **10 [P1] y 4 [P2]**. Cada P1 verificable se comprobó contra el código **antes** de aceptarse, según la doctrina de la casa. Ninguno se aceptó por autoridad.

| # | Hallazgo | Verificación | Dónde aterriza |
|---|---|---|---|
| 1 | D3 viola «conservar firmas»: el snapshot congela la firma y `api-surface.md:7` clasifica el cambio como major | **Confirmado.** `Article.json:170` congela `parameters:[{customerId, string\|int}]`; `api-surface.md:7` dice literal *«changed signatures/semantics → major»* | **D3 reescrita**: la firma no se toca; resolver nuevo `resolveOverrideFor()` y el existente delega con `now()` |
| 2 | MINOR no resuelto: D7 citaba solo la cláusula favorable | **Confirmado**, y era peor de lo escrito | **D7 reescrita**: se citan las dos cláusulas y se resuelve por «ninguna firma cambia», con condición explícita de que la clasificación decae si eso deja de ser cierto |
| 3 | D4 omite la autoexclusión: un update se detecta a sí mismo como solape | **Confirmado.** `ArticlePrice.php:107` lo hace con `->when($price->exists, …whereKeyNot(…))` y un comentario que explica por qué | **D4**: autoexclusión copiada literal + tests de update y reactivación |
| 4 | «Invariante dura» es afirmación excesiva: el modelo es `@api` y admite escritura directa | Confirmado por lectura | **D5bis nueva**: garantía para el servicio, red best-effort para el resto, límite declarado |
| 5 | D5 no especifica una API implementable | Confirmado | **D5**: firma completa, create-only, sin reemplazo automático, rechazo de `valid_from > valid_to` |
| 6 | La afirmación sobre `ArticleServiceStatus` es **falsa** | **Confirmado, y es un error propio.** `updateEffectivePrice():300` resuelve por `getActiveOverrideFor()`, que ya filtra ambas fechas (`Article.php:380-387`) | **§5 corregida**: esa ruta hereda **solo el orden**; el agravante del caducado aplica al que cotiza y persiste por su cuenta |
| 7 | Test 8 no es sembrable tras D4, y choca con el unique | **Confirmado.** El unique es `(customer_id, article_id, valid_from)`; dos fechas iguales no nulas colisionan | **Test 8**: desempate con dos `valid_from = NULL`, sembrados sin eventos |
| 8 | El test determinista puede pasar contra el código roto | Confirmado — «corridas repetidas» no discrimina | **Test 8**: criterio pasa a ser mutación (retirar los `orderBy`) |
| 9 | La sensibilidad global es lógicamente imposible | Confirmado: el test 9 llama al resolver directamente | **§6**: tabla de mutantes, cada uno con sus rojos |
| 10 | El fork test omite requisitos mecánicos obligatorios | **Confirmado, y es el hallazgo de mayor valor operativo.** `tests/ConcurrencyGateContractTest.php` es un gate REAL que corre en la suite normal y exige `time_sleep_until` (`:69`) y captura de la excepción del hijo (`:91`) | **§6.14**: STD-004 citado como requisito, no como consejo |
| P2 | «Única definición de vigencia» no es literalmente cierto (`isValidAt()` sigue aparte) | Confirmado (`ArticleOverride.php:141`) | **D2**: se acota a «única definición SQL» + test de equivalencia |
| P2 | Promete probar seis métodos y exige dos | Confirmado | **§6.10**: los seis, enumerados |
| P2 | Falta regresión del único cambio real en `updateEffectivePrice()` | Confirmado | **§6.10bis** nuevo |
| P2 | D8 extrapola de dos consumidores medidos | Confirmado | **D8**: pasa a declararse riesgo aceptado, no ausencia demostrada |

**Matiz que la adjudicación añade al hallazgo 1**, y que no invalida su conclusión: el snapshot marca `"api": false` para `getActiveOverrideFor`, porque ese flag registra la anotación **explícita del método**, no la herencia de la clase — y `Article` sí es `@api` a nivel de clase (`:49`), con precedente de anotación por método en `:340`. Da igual para la decisión: la firma no se toca, y además cambiarla rompería el snapshot contractual por sí solo.

## 9. Adjudicación de la segunda ronda adversarial (rev 2 → rev 3)

Veredicto recibido: **HOLD antes de adversarial**, 4 [P1] y 2 [P2].

**Nota de lectura, comprobada antes de adjudicar:** la ronda se hizo sobre la **rev 1**. Verificado por mapeo de líneas — las citadas 68, 124 y 140 corresponden a `### D5`, `**Feature — la resolución**` y el fork test de `e8defee`, y en rev 2 esas líneas ya no existen. Cuatro de los seis hallazgos ya estaban corregidos por la ronda de Codex. Eso **no** los invalida: tres traen matices que rev 2 no cubría, y se incorporan.

| # | Hallazgo | Estado | Dónde aterriza |
|---|---|---|---|
| 1 | `?Carbon $at` cambia la firma (snapshot + `ModelContractSnapshotTest.php:25`) | Ya corregido en rev 2 por el mismo hallazgo de Codex | D3 |
| 2 | **Granularidad temporal: columnas `date` comparadas contra un instante con hora** | **NUEVO. Confirmado empíricamente, y peor de lo reportado** | **D2bis nueva** + tests 5bis/5ter/5quater |
| 3 | `setOverride()` `@api` sin contrato completo | Ya corregido en rev 2; **matiz nuevo aceptado**: los *nombres* de parámetros son compromiso público por argumentos nombrados, y el precedente de AID-601 congeló la firma antes de implementar | D5 |
| 4 | Hook sin autoexclusión ni casos de update | Ya corregido en rev 2; **matiz nuevo aceptado**: faltaban los casos concretos y el contenido del mensaje de excepción | D4 + test 13ter |
| 5 | Fork test sin STD-004 explícito | Ya corregido en rev 2; **dos matices nuevos aceptados**: `valid_from = null` para que el unique no enmascare el defecto, y que `ArticlePriceConcurrencyTest` está exento del gate y no se puede copiar literal | §6.14 |
| 6 | Sensibilidad no corresponde con los tests | Ya corregido en rev 2 con la tabla de mutantes; **matiz nuevo aceptado**: faltaba probar rechazo y rollback del servicio | Test 13bis + fila nueva de la tabla |
| menor | El lock está en ADR-012 **§D3**, no §D1 | **Confirmado** (`ADR-012:33`) | Corregido en §2 y D5 |
| menor | D8 debería decir «ningún despliegue conocido tiene filas» | Aceptado — el propio texto admite consumidores desconocidos | D8 |

**El hallazgo 2 es el que justificaba el HOLD.** Ninguna de las dos rondas anteriores lo vio, y habría canonizado como «fuente única de vigencia» un scope que descarta overrides el último día de su validez, con resultados distintos según el motor.
