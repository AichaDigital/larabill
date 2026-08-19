# ADR-014 — Artefactos de corrección y liquidación de lo no consumido

- **Estado:** **Accepted — decisión tomada, código PENDIENTE. Ejecuta AID-971.**
- **Fecha:** 2026-08-17
- **Decide:** Abdelkarim Mateos
- **Contexto de origen:** AID-956 (autoridad de `effective_price`), ronda adversarial de Codex y sesión de dominio del 2026-08-17
- **Supersede parcialmente:** la política comercial implícita de `CancellationType` y `ServiceLifecycleService::calculateRefund()`
- **Enmienda 1 (2026-08-18, Abdelkarim Mateos):** la primera redacción describía **un solo** `cancel()` con un default inventado. Son **dos**, con rutas de metadata distintas y comportamientos divergentes, y hay un **tercer** sitio que replica la política (`ArticleServiceStatusFactory`). Corregidos §1 y §5. Origen: relectura completa del código el 2026-08-18 (`docs/reports/2026-08-18-abonos-bajas-rectificativas-estado.md`)

> ⚠️ **Este ADR describe la decisión, NO el código actual.** Hasta que AID-971 se entregue, `CancellationType::requiresRefund()`, los **dos** defaults `?? 30` de `notice_days` y la política implícita de `IMMEDIATE` **siguen vivos en `src/`**. Ninguna sesión debe leer este documento como descripción del comportamiento vigente.
>
> La advertencia es deliberada y tiene precedente caro: ADR-004 llevaba desde 2025-12-09 diciendo que la línea recurrente sale de `effective_price` mientras el motor derivaba del catálogo, y esa divergencia entre decisión publicada y código es justamente lo que abrió AID-956. Un ADR aceptado sin implementar es correcto —la decisión está tomada— pero solo si dice en voz alta que el código aún no ha llegado.

## 1. El problema

Larabill había tomado, sin decidirlo, una política comercial completa sobre bajas y devoluciones, y la había escrito en el núcleo fiscal:

- **`CancellationType` decide si hay devolución.** `requiresRefund()` devuelve `true` únicamente para `NOTICE_PERIOD`. La descripción de `IMMEDIATE` dice literalmente *«cancelled immediately without refund for unused time»*: cancelas el día 10 y pierdes lo pagado. Nadie eligió eso; viene de fábrica y no es configurable ni por instalación, ni por producto, ni por cliente.
- **El plazo de preaviso es un valor inventado, y lo está por duplicado.** Existen **dos** implementaciones de `cancel()` —`ArticleServiceStatus::cancel()` (`:235`) y `ServiceLifecycleService::cancel()` (`:244`)—, ambas superficie pública, ambas con `?? 30`, y **cada una leyendo una ruta de metadata distinta**: `metadata['service']['cancellation_policy']['notice_days']` la del modelo, `metadata['notice_period_days']` la del servicio. Un consumidor que declare su plazo contractual en una de las dos rutas obtiene el plazo correcto por un camino y **treinta días inventados por el otro**; declararlo en ambas es la única forma de no equivocarse, y nada se lo advierte. Un plazo contractual y legal, con default sacado del aire: misma clase de defecto que AID-589 (la serie fiscal `'FAC'` inventada), aplicada a una cláusula de contrato y multiplicada por dos.
- **Y los dos `cancel()` divergen en más que el plazo.** El del modelo pone `next_billing_date` a `null` en `IMMEDIATE`, registra `requested_by` y **no dispara `ServiceCancelled`**; el del servicio no toca `next_billing_date`, no registra quién canceló, sí escribe `effective_date` y `type` en el metadata, y sí dispara el evento. Dos bajas del mismo servicio por caminos distintos dejan la fila en estados distintos, y una de las dos no notifica a nadie.
- **`calculateRefund()` está del revés.** Decide el importe con una fórmula única, se autoriza a sí mismo vía `requiresRefund()`, y **no emite ningún documento**. Hace la parte que no le corresponde y omite la que sí. Además no lo invoca nadie en `src/`: es una utilidad huérfana, lo que explica que llevara meses leyendo el precio equivocado sin que nadie lo notara.

En paralelo, el paquete **ya poseía** la parte que sí le corresponde y la tenía construida: `invoices.rectifies_invoice_id` desde la migración fundacional, relaciones `rectifies()`/`rectificatives()`, serie fiscal RECT con numeración propia, y `VerifactuAdapter` emitiendo `TipoRectificativa` y `FacturasRectificadas` a la AEAT. Lo único ausente era un servicio que creara el documento.

## 2. La frontera

**Larabill emite documentos fiscales. No tiene política comercial ni mueve dinero.**

- **Es de larabill:** dado «corrige/liquida por este importe y con este artefacto», producir el documento fiscalmente correcto — numeración en su serie, enlaces a la factura original cuando procedan, `TipoFactura` correspondiente, cálculo de impuestos, snapshot congelado, inmutabilidad.
- **No es de larabill:** si hay devolución, cuánto, con cuánto preaviso, en qué forma (dinero, saldo, crédito al siguiente tramo), ni por qué canal. Eso es política del consumidor.
- **No es de larabill:** a qué periodo económico pertenece el efecto de un abono. **Eso es contabilidad.** Larabill emite con el impuesto correcto en el momento de emitir; la reconciliación temporal la hace el sistema contable.

## 3. Decisión — dos ejes independientes

Una lista de situaciones comerciales nunca se cierra: siempre aparece la siguiente. Dos ejes ortogonales sí.

### Eje 1 — qué artefacto resulta

| Artefacto | Enlace a factura previa | AEAT | Cuándo |
|---|---|---|---|
| **Ninguno** | — | — | Suspensión con extensión de `next_billing_date`. No hay documento. |
| **Anulación** | — | `RegistroAnulacion` | La factura no debió existir: duplicado, receptor equivocado, emisión por error. |
| **Rectificativa** | `rectifies_invoice_id` | `R1` + `FacturasRectificadas` | Corrige una factura anterior: error, omisión, o causa del art. 80 LIVA que modifica una base ya declarada. |
| **Factura ordinaria con línea de abono** | **ninguno** | `F1` | Operación **nueva** que liquida lo no consumido de un contrato anterior y cobra lo nuevo. No corrige nada. |

**El discriminante es `rectifies_invoice_id`, nunca el signo de los importes.** `VerifactuAdapter::mapInvoiceType()` ya funciona así: con FK → `R1`; sin FK → `F1`, aunque la factura lleve líneas negativas. Una factura con abono **no es** una rectificativa por el hecho de llevar un importe negativo.

### Eje 2 — de dónde sale el importe

- **Total:** la factura o la línea entera.
- **Proporcional al tiempo no consumido** de la línea emitida. **Es la única aritmética que larabill ofrece**, porque es la única derivable de datos que ya posee: la línea, su precio y sus fechas de servicio.
- **Importe arbitrario aportado por el consumidor:** error de facturación, descuento posterior, rappel, crédito por incumplimiento de SLA, gesto comercial. Ni se calcula ni se discute.
- **Deuda pendiente completa**, bajo el procedimiento de modificación de base imponible por impago (art. 80.Cuatro LIVA). Procedimiento y plazos fijados por ley — condiciones exactas a verificar contra la norma vigente, y su cumplimiento es responsabilidad del consumidor.

**Los dos ejes son independientes.** El proporcional alimenta tanto una rectificativa parcial como la línea de abono de una factura nueva. Atar «proporcional» a «rectificativa» fue un error de la primera redacción de esta taxonomía.

### Regla fiscal del abono en factura nueva

**La línea de abono cotiza en la emisión del abono.** Lleva el contexto fiscal de la factura que la contiene —su fecha, su tipo impositivo, su régimen—, no el de la factura que liquida. Si entre ambas cambió el tipo de IVA, o el receptor obtuvo NIF-IVA y pasó a inversión del sujeto pasivo, la línea de abono tributa con el contexto **actual**.

De ahí la asimetría de coste entre los dos artefactos:

- La **factura con línea de abono** no necesita maquinaria fiscal: es una línea negativa gravada con el contexto de su propia factura, que es lo que larabill ya hace con toda línea desde ADR-001.
- La **rectificativa** sí debe alcanzar el contexto fiscal **congelado** de la factura que corrige. Por eso es la que lleva el FK y la que concentra la complejidad.

### Larabill nunca elige el artefacto

Que un cambio de contrato se documente como rectificativa o como factura nueva con abono **depende de cómo esté construido el contrato**, es criterio del consumidor, y el registro que llega a la AEAT cambia materialmente según cuál sea. El paquete debe saber expresar los cuatro artefactos correctamente y **no tener opinión sobre cuál toca**. Mismo principio que AID-589 con la serie fiscal.

## 4. Casos situados

| Caso | Artefacto | Importe |
|---|---|---|
| Desistimiento legal, garantía comercial | Rectificativa | Total |
| Baja con abono de lo no consumido | Rectificativa | Proporcional |
| **Cambio de servicio (alta/baja de plan)** | **Factura ordinaria con línea de abono** | Proporcional (abono) + nuevo cargo |
| Cambio de titularidad a mitad de periodo | Según criterio del consumidor | Proporcional — **ojo: cambia el receptor fiscal**, congelado en el snapshot |
| Error de facturación (precio, cantidad, tipo, datos del receptor) | Rectificativa | Arbitrario |
| Descuento posterior, rappel, crédito por SLA | Rectificativa | Arbitrario |
| Impago / crédito incobrable | Rectificativa | Deuda pendiente (art. 80.Cuatro) |
| Factura duplicada o emitida por error | **Anulación**, no rectificativa | — |
| Suspensión con extensión de fecha | **Ninguno** | — |

El **error de facturación** es, con diferencia, la rectificativa más frecuente de la vida real y no guarda relación alguna con una baja. Que exista demuestra que anclar el diseño en `CancellationType` era incorrecto de raíz.

## 5. Consecuencias

**Se retira:**

- `CancellationType::requiresRefund()`. El paquete deja de autorizarse a sí mismo a decidir si hay abono.
- El default `?? 30` de `notice_days`, **en sus dos sitios** (`ArticleServiceStatus:235` y `ServiceLifecycleService:244`). Sin plazo declarado, se falla loud; no se inventa una cláusula contractual. Retirarlo solo del que cita el ticket dejaría vivo medio defecto con apariencia de arreglo.
- La duplicidad de `cancel()`. Queda **un único camino de baja**, con una sola ruta de metadata, que dispara el evento y deja la fila en un estado definido. Es un bug de consistencia acotado y sin dependencia del cluster de representación: puede entregarse antes y por separado.
- La política implícita de `IMMEDIATE` («sin devolución») y de `NOTICE_PERIOD` («con devolución»). El tipo de baja describe **cuándo termina el servicio**, no qué se abona.

**Se añade:**

- Servicio de emisión de rectificativas y anulaciones (hoy solo existen en factories).
- Soporte **explícito y probado** de líneas negativas. Hoy no hay validación de signo en `InvoiceService`, `InvoiceItem` ni `TaxCalculationService`: permisivo no es soportado. El camino negativo nunca ha pasado por el cálculo de impuestos, por el `FiscalContentValidator` de ADR-011 ni por ninguna de las seis plantillas PDF.
- Procedencia de la línea de abono: qué periodo liquida. En una rectificativa lo da el FK; en una factura nueva **no debe haber FK**, así que la traza vive en la línea.

**Se corrige en fixtures**, que son el tercer sitio donde vive la política y el molde con el que la suite entiende qué es un abono:

- `ArticleServiceStatusFactory:138` hace `'refund_unused' => $type->requiresRefund()`. Al retirar `requiresRefund()` hay **tres** sitios que tocar, no dos — y éste es el que hace que la suite dé por buena la política mientras se la retira.
- `InvoiceFactory::credit()` crea importes negativos **y a la vez** estampa `prefix: 'RECT'` y serie `RECTIFICATIVE`, institucionalizando justamente la confusión que este ADR deshace. **Agravante verificado el 2026-08-18:** tampoco fija `rectifies_invoice_id`, que conserva el `null` de `:64`. Ese objeto, pasado por `VerifactuAdapter`, se declararía a la AEAT como **`F1`** —factura ordinaria completa— con serie RECT e importes negativos: es el ejemplo canónico del documento que este ADR dice que nunca debe existir.

**Queda fuera de este ADR:** cómo el consumidor decide (config, por producto, por cliente) qué artefacto aplica a cada situación. Eso es la cascada de resolución del ticket de política, y se diseña con el cluster AID-949 / AID-952 / AID-953 / AID-958 — porque `cancel()` escribe tipo, fecha efectiva y `refund_unused` en un solo acto derivado del mismo enum, y AID-958 (`END_OF_PERIOD` que sigue facturando) es ya una decisión de política de abono.

## 6. Relación con AID-956

AID-956 **no implementa este ADR**. Se queda con la aritmética, que es invariante bajo todos los artefactos de arriba: la parte no consumida es una proporción de **lo que realmente se facturó**, no del precio vivo del contrato. `ServiceLifecycleService::calculateRefund()` pasa a medir sobre la línea emitida que cubre el periodo.

Ese cambio no prejuzga ninguna decisión de este ADR: devuelvas el importe, una parte, o lo conviertas en abono de una factura nueva, la cantidad de partida es la misma.
