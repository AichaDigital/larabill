# ADR-012: La invariante de no-solape de precios de artículo (larabill)

> **Status**: Accepted
> **Date**: 2026-07-22
> **Relates**: cierra AID-601 (segregado de AID-331 durante la reconciliación Linear↔realidad del 2026-07-21). Cumple la promesa de **ADR-004** (precios por frecuencia). Hermano del fix de `invoice_series_control` de **AID-390**, misma familia de defecto (unique con columna nullable) y fix independiente. No supersede ningún ADR.

## Contexto

`article_prices` define `unique(['article_id', 'billing_frequency', 'valid_from'])` con **`valid_from` nullable**. MySQL y SQLite admiten NULLs duplicados en un índice unique, así que dos filas activas de la misma `(artículo, frecuencia)` sin fecha de inicio conviven sin que el índice se queje. El comentario del índice promete una garantía que el índice **no da**.

No es un defecto cosmético, es **un defecto de dinero**. La invariante «a lo sumo un precio activo por artículo y frecuencia en cualquier fecha» ya se presupone en tres sitios de lectura y en la UI del consumidor de referencia, pero no existía en ninguna escritura:

- `Article::getPriceFor()` resuelve **un** valor y lo hacía **sin `ORDER BY`** — qué precio ganaba dependía del recorrido del índice.
- De ahí el valor viaja a `PricingService` y termina en una **línea de factura**, es decir, en dinero facturado.
- El consumidor de referencia (Castris, `ArticleEdit::save`) borra las filas activas y recrea una por frecuencia — un patrón que solo es correcto **si** la invariante se sostiene.

El resultado era un ganador silencioso: dos precios vivos, y el que llegaba a la factura no estaba determinado.

## Decisión

### D1 — La garantía vive en la capa de aplicación, no en el DDL

MySQL no tiene *exclusion constraints* (PostgreSQL sí, con `EXCLUDE USING gist`), así que «a lo sumo uno válido en cualquier fecha» **no es expresable como restricción de tabla** en el motor soportado. Un índice funcional sobre `COALESCE(valid_from, sentinel)` cubriría únicamente el caso de los NULL duplicados, no el solape de rangos con fechas — que es el problema general, no un caso particular.

La invariante se garantiza, por tanto, en la escritura del paquete.

### D2 — Una sola definición de «solape», con dos consumidores

`ArticlePrice::scopeOverlapping(int $articleId, BillingFrequency $frequency, ?Carbon $validFrom, ?Carbon $validTo)` es la **fuente única**: las filas **activas** de esa `(artículo, frecuencia)` cuyo intervalo interseca el rango candidato, con `NULL` como extremo abierto en ambos lados.

Se mantiene **pura**: no excluye la fila del propio candidato. La auto-exclusión es responsabilidad del llamador, y dejarla fuera permite que el comando de diagnóstico reutilice exactamente la misma condición para reportar pares existentes.

### D3 — Hook `saving` como red de seguridad; servicio con lock como garantía

- **Red (`ArticlePrice::booted()`):** todo camino Eloquent valida antes de escribir y lanza `OverlappingArticlePriceException`. Solo se comprueban las filas **activas** — una fila inactiva es histórico deshabilitado y no puede violar la invariante —, lo que de paso cubre la **reactivación** (`is_active` false→true), un camino de solape tan real como un alta.
- **Garantía (`ArticlePriceService::setPrice()`):** transacción + `lockForUpdate` **sobre la fila padre de `articles`**, y solo entonces validar y escribir.

**Por qué el lock va en el artículo y no en los precios:** `FOR UPDATE` solo bloquea filas que casen con el `WHERE`. El primer precio de una frecuencia casa con **cero** filas, así que bloquear «los precios de esta (artículo, frecuencia)» no serializa dos altas concurrentes en absoluto. La fila de `articles` siempre existe: bloquearla serializa toda escritura de precio de ese artículo y evita los *gap locks* sobre rangos vacíos que fueron la fuente de deadlocks en AID-390/AID-570.

**Sin reintento dentro, por diseño:** el llamador típico envuelve esto en su propia `DB::transaction`, donde un `attempts` interior es un *savepoint* y no compra nada (lección AID-570). El lock se sostiene hasta que commitea la transacción **exterior**; el reintento ante deadlock pertenece a quien la abre.

### D4 — Rechazar, no reemplazar (YAGNI)

No se ofrece un modo «reemplaza el precio vigente». El historial de precios es expresable con dos escrituras explícitas (cerrar el rango del vigente, abrir el nuevo), y adivinar cuál de dos precios debe morir es decidir dinero por el consumidor.

### D5 — Lectura determinista para las bases de datos que ya tienen duplicados

`getPriceFor()` ordena ahora de forma explícita: **inicio más reciente gana, `id` desempata**. `NULL` ordena más bajo en MySQL y SQLite, así que una fila con fecha vence a una legacy de inicio abierto.

Busca **predictibilidad, no acertar la intención** del operador: el arreglo real es eliminar el duplicado. Es la única pieza que protege a quien todavía no ha limpiado sus datos.

### D6 — El comentario del índice se queda como está

El comentario de la migración `2025_01_20_000004_create_article_prices_table` sigue prometiendo una garantía que el índice no da, y **no se corrige**.

La migración está publicada y registrada en `release-migration-manifest.json` con su `sha256`; `ShippedMigrationImmutabilityTest` (AID-398/AID-412) exige identidad byte a byte. La inmutabilidad de lo ya entregado pesa más que la exactitud de un comentario.

**Trampa a dejar registrada:** verificar ese cambio con `MigrationOrderConsistencyTest` da **falso verde** — ese test compara `.php` contra `.php.stub`, y editar ambos a la vez lo deja contento mientras `ShippedMigrationImmutabilityTest` se pone en rojo contra el manifiesto. Quien vuelva sobre esto debe correr el segundo, no el primero.

La verdad sobre la garantía vive aquí y en el código que la implementa.

### D7 — Comando de diagnóstico como puerta de pre-upgrade

`larabill:diagnose-price-overlaps` lista todo par de precios activos que coincidan en el tiempo, es **read-only** y sale con código 1 cuando existe alguno, de modo que un consumidor pueda cablearlo como puerta antes de actualizar.

No repara nada, deliberadamente (ver D4). La excepción de escritura apunta a él.

## Consecuencias

- **Cambio de comportamiento:** escrituras que antes se aceptaban en silencio ahora fallan con `OverlappingArticlePriceException`. El historial legítimo (rangos disjuntos) y las filas inactivas no se ven afectados.
- **Coste declarado:** una consulta extra por cada `save` de un precio activo. Como `is_active` es `true` por defecto, un seeder masivo la paga por precio. Aceptado.
- **Límite declarado:** el hook **solo** no garantiza nada bajo concurrencia. Medido: con seis procesos simultáneos y el lock retirado, escribieron cinco o seis de seis. La garantía es el servicio.
- **Sin cambio de esquema.** Ninguna migración se toca; el manifiesto no se mueve.
- **Consumidores existentes con duplicados** no rompen al actualizar (la lectura es determinista), pero su primera escritura sobre una `(artículo, frecuencia)` en conflicto fallará hasta que resuelvan el solape.

## Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Índice funcional sobre `COALESCE(valid_from, sentinel)` | Solo cubre los NULL duplicados, no el solape de rangos con fechas — resuelve el síntoma reportado, no el defecto |
| Sentinel de fecha (p. ej. `1970-01-01`) en `valid_from` | Cambia el esquema y la semántica de datos ya persistidos, con migración de backfill, para seguir sin cubrir el solape general |
| `EXCLUDE USING gist` (PostgreSQL) | El motor soportado es MySQL; no es portable |
| Modo «reemplazar el precio vigente» | Decide dinero por el consumidor y no hay caso real que lo pida (D4) |
| Corregir el comentario del índice | Rompe la inmutabilidad de una migración publicada (D6) |

## Criterios de reapertura

- Un consumidor real que pida **reemplazo automático** del precio vigente, con su semántica escrita (qué pasa con el rango del anterior).
- Soporte de PostgreSQL como motor de primera clase — ahí la invariante **sí** es expresable como restricción de tabla, y convendría moverla al esquema.
- Evidencia de que el coste de la consulta extra por `save` duele en un alta masiva real (no en teoría).
