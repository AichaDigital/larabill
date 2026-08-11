# Diseño AID-929: la calificación fiscal de cabecera (`is_roi_taxed`) sobrevive a la emisión

> **Status**: **rev 4 — TODAS las decisiones cerradas; aprobado para TDD** (D8 = corregir a `!== 0`, D9 = añadir la clave al generador canónico; ambas resueltas por el propietario el 2026-08-11). La rev 1 recibió lectura del propietario (3 veredictos + 2 correcciones bloqueantes + 1 hallazgo incidental, adjudicados en §8). La rev 2 recibió ronda adversarial de Codex (3 P1 + 5 P2, GATE FAIL), adjudicados en §8bis contra código; dos de sus P1 obligaron a medir datos reales y tres hallazgos corrigen afirmaciones falsas que la rev 2 heredaba del ticket.
> **Date**: 2026-08-11
> **Issue**: AID-929 (a petición de `clientes`; destapado por el gate adversarial de Codex sobre AID-916 y acotado en AID-928)
> **Relates**: AID-559/D10 (`service_date` verbatim), AID-556/557 (clonado verbatim de línea), AID-555 (`proforma_id` canónico), AID-576 (serie per-call), AID-836 (emisión recurrente canónica), AID-309 (el consumidor declara la calificación), AID-328 (dos generadores de snapshot con claves divergentes — se repite aquí, §D9), AID-413 (`InvoiceService` es `@api`), ADR-001 (snapshots congelados), `STABILITY.md`.
> **Versión objetivo**: **`v6.11.0` (MINOR)** — fundamentado en medición, no en doctrina (D5). **Cero migraciones**.

## 1. El defecto: confirmado, con el impacto corregido

`InvoiceService::convertProformaToInvoice()` construye `$invoiceData` clonando de la proforma (`src/Services/InvoiceService.php:509-552`): `billable_user_id`, `user_id`, `proforma_id`, `items` (verbatim), `type`, `status`, `series`, `service_date`, `due_date`, `payment_terms`, `template_name`. `is_roi_taxed` no está.

La columna existe (`create_invoices_table`: `boolean('is_roi_taxed')->default(false)`) y es `fillable` (`src/Models/Invoice.php:163`). Una proforma congelada con el flag en `true` produce una factura con `false`.

**Impacto real, verificado en la ronda Codex (la rev 2 lo declaraba mal).** El ticket afirma —y la rev 2 repetía— que con el flag caído «la venta no entra por la rama N2». **Es falso**: `VerifactuAdapter:187` entra en N2 por `$crossBorderEu && $customerHasVat` (países del snapshot + VAT del receptor), **sin consultar el flag**. Lo que el flag decide de verdad:

- **`VerifactuAdapter:227`** — rechazo fail-loud de un reverse-charge declarado con receptor intracomunitario incompleto. Con el flag caído ese control no se ejerce.
- **`VerifactuAdapter:89`** — el flag viaja a la AEAT dentro de `metadata`.
- **`EuSalesThresholdService:32`, `:75`, `:236`** — una venta con inversión del sujeto pasivo suma hacia el umbral de ventas a distancia del que debe quedar fuera.
- **`DomPDFService:252`** — elige la plantilla reverse-charge, con sus menciones fiscales obligatorias.

El defecto sigue siendo real y fiscalmente relevante; su alcance AEAT es menor del que el ticket declara, y ese matiz pesó en D5.

## 2. El fix que propone el ticket NO funciona

El ticket propone «añadir `'is_roi_taxed' => $proforma->is_roi_taxed` a `$invoiceData`». **Esa línea sola no cambia nada y el test de cierre seguiría rojo.**

La conversión no persiste: delega en `createInvoice()`, que construye el documento con una **lista explícita de 15 claves** (`:134-153`) donde `is_roi_taxed` no aparece, y tampoco está en el array shape documentado (`:73-85`). Una clave desconocida en `$invoiceData` se ignora en silencio.

**Corolario:** hoy **ningún camino canónico puede emitir con `is_roi_taxed = true`** — los tres pasan por ese `createInvoice()`. Esto explica AID-928 en `clientes`: el paquete no ofrece dónde declararlo.

**Precisión (corrección de la rev 2, hallazgo C7).** La rev 2 decía «no hay salida por detrás: `Invoice::update()` lanza `Cannot update an immutable invoice`». Es falso como afirmación absoluta: el override cubre `update()`, pero **`save()` lo evade**, y el propio código lo documenta como bypass deliberado en `markAsPaidViaGroupedPayment()` (`:247-253`: «It goes through save() deliberately, bypassing the update() immutability guard»). Lo cierto y suficiente: **la API canónica no acepta el flag**; que sea imposible fijarlo por detrás, no.

## 3. Decisiones

### D1 — El fix vive en el contrato de entrada, no solo en la conversión

`createInvoice()` acepta `is_roi_taxed?: bool` en su array shape y lo persiste; la conversión lo arrastra verbatim desde la proforma.

- **Descartado — parchear solo la conversión:** no persiste, delega.
- **Descartado — `update()`/`save()` posterior:** rompe que el documento nazca completo (ADR-001) y se apoyaría en un bypass documentado como excepción para estado de cobro, no para contenido fiscal.

### D2 — Default `false`, comportamiento actual intacto

`'is_roi_taxed' => $invoiceData['is_roi_taxed'] ?? false`. En la conversión, `(bool) $proforma->is_roi_taxed` — verbatim, nunca inferido ni recalculado contra VIES (doctrina AID-309).

### D3 — `notes` FUERA de AID-929 → AID-930 ✅ *(veredicto del propietario)*

Heredarlo sería un defecto fiscal: `DomPDFService::getInvoiceNotes()` (`:569-580`) da precedencia a `$invoice->notes` **por delante** de las notas de la plantilla de destino, así que propagar la nota de la proforma taparía la nota fiscal de la plantilla reverse-charge — la que este ticket hace alcanzable. Y viaja a la AEAT como `description` (`VerifactuAdapter:86`). Que un campo sea `fillable` no demuestra que deba heredarse entre documentos.

### D4 — Fail-loud AHORA ✅ *(veredicto del propietario)*

La rev 1 decía que «en la conversión el riesgo es nulo». **Falso**: las líneas se clonan congeladas pero se calcularon con `TaxCalculationService`, que **nunca lee `is_roi_taxed`**. Una proforma puede llevar el flag y cuota a la vez; propagar ambos produce una factura incoherente **y sellada**. El fix de D1 no hereda ese riesgo: **lo crea**.

Contrato del guard:

- **No** se modifican impuestos ni `TaxCalculationService`. El consumidor declara la calificación y aporta líneas coherentes.
- Corre en `createInvoice()` **tras crear líneas y calcular totales (`:157-164`) y ANTES de snapshots y sellado (`:167`)**, dentro de la transacción.
- **Excepción tipada `@api`: `ReverseChargeWithTaxException`** en `src/Exceptions/`, `final class ... extends RuntimeException`. **Firma fijada** (hallazgo C8): `public static function forInvoice(Invoice $invoice): self`. El mensaje nombra el importe de cuota detectado y la corrección (declarar líneas sin cuota o retirar el flag).
- **Aplica también a proformas** (`createProforma()` delega): es donde el consumidor corrige sin coste fiscal, y así la conversión nunca ve el caso.
- **Garantía, en su formulación exacta** (corrección C6): **no quedan filas en la base de datos** — ni factura, ni líneas, ni avance del contador. La numeración se revierte: Codex verificó que `generateNumber()` abre transacción anidada (savepoint en L13, `PDO::commit()` solo en nivel 1) y que `attempts: 3` **no** reintenta una `RuntimeException` (solo errores de concurrencia). Lo que la transacción NO deshace son efectos externos que un observer del consumidor haya lanzado en `creating`/`created` (jobs, correos, integraciones); el docblock lo dirá y recomendará `afterCommit`.

Por qué no basta el control que ya existe: `VerifactuAdapter:196` rechaza un N2 con IVA (regla 1237), pero eso ocurre en el registro fiscal, con el documento ya nacido y sellado. Y solo alcanza a las operaciones que entran en la rama N2 (§1), que no son todas las que llevarían el flag.

### D5 — MINOR, `v6.11.0` — fundamentado en medición ✅ *(cerrado con el propietario)*

Codex marcó P1 sosteniendo que un rechazo nuevo exige major, citando `STABILITY.md:24`: *«anything that can lose data, **reject existing rows** or change persisted semantics is a major»*. Dos razones lo cierran como MINOR:

1. **Contexto de la norma:** esa frase abre con *«Minor releases may ship schema changes only when they are additive or provably data-safe»* — gobierna releases **con cambios de esquema**. Este release no trae migraciones.
2. **Medición, no doctrina** (2026-08-11, entorno del propietario):

   | Base | Proformas | Facturas | Con el flag | Conversiones |
   |---|---|---|---|---|
   | `larafactu` (dev) | **0** | 579 | **0** | **0** |
   | `larafactu_int_test` | **0** | — | — | — |
   | `larabill_test` | sin tabla `invoices` | — | — | — |

   Las 579 facturas se insertaron entre las 21:26:43 y las 21:26:46 del 2026-07-03 (import de histórico, `fiscal_year` 2024-2026), no emisión viva. **Staging** (`castris.larafactu.com`) declarado vacío por el propietario. **Packagist: 0 dependents, 0 suggesters** — ningún paquete público depende de larabill.

   El universo de filas que el guard rechazaría es **vacío**. Un fail-loud sobre un input que nunca se ha producido no rompe a ningún consumidor.

Entrega: entrada de CHANGELOG bajo **Fixed** con old/new behaviour explícito (regla 5 de `STABILITY.md`) y la consulta de auditoría (§5) publicada para cualquier consumidor futuro.

### D6 — `createProforma()` también amplía su shape ✅

Tiene array shape público propio de 7 claves (`:413-424`) sin el flag, y delega en `createInvoice()`. Sin ampliarlo, una proforma no puede nacer con `is_roi_taxed = true` por la API canónica y el test tendría que fabricarla manipulando el modelo — el falso verde que el criterio de cierre («sin fijarlo a mano») pretende evitar.

### D7 — Alcance: emisión directa + proforma + conversión ✅

`RecurringBillingService::createInvoiceForService()` (`:422-437`) construye el input internamente con 4 claves y el hook de v6.10.0 recibe la factura ya sellada: ampliar el shape no le da al consumidor dónde declarar la calificación. Sale a **AID-931**. El CHANGELOG dirá explícitamente que la recurrente sigue emitiendo con `is_roi_taxed = false`.

### D8 — Semántica de «cuota real»: se corrige a `!== 0` ✅ *(veredicto del propietario, opción A)*

La definición no se duplica: `VerifactuAdapter::invoiceHasRealTax()` (`:394-409`, `private static`) se extrae a **`Invoice::hasRealTax(): bool`** como fuente única y el adaptador delega.

Codex encontró un defecto **en la definición heredada** (P1): comprueba `total_tax_amount > 0`, no `!== 0`. Una línea con cuota **negativa** y `taxes_applied` vacío pasa el filtro — el bucle sobre `taxes_applied` sí usa `!== 0`, pero solo se alcanza si hay desglose. Con importes negativos (abonos, rectificativas) el guard sería ciego.

- **Opción A — corregir a `!== 0` (recomendada).** El guard cumple lo que promete. **Coste:** cambia también el comportamiento del **adaptador**, que hoy acepta un N2 con cuota negativa y pasaría a rechazarlo con la regla 1237. Es un segundo cambio de comportamiento en el mismo release, y va al CHANGELOG bajo **Fixed** con su propia línea old/new.
- **Opción B — preservar `> 0` en la extracción** y abrir ticket propio para la corrección del adaptador. Mantiene este release en un solo cambio de comportamiento, pero deja el guard con un agujero conocido desde el día uno — documentado, que es peor que arreglado.

**Decidido: A.** Un guard fiscal con un hueco documentado es deuda que esta casa no acepta, y el caso que destapa (N2 con cuota negativa) es tan inválido ante la AEAT como el positivo. El cambio en el adaptador viaja en este release con su propia línea old/new bajo **Fixed**.

### D9 — Congelar la calificación en el `fiscal_snapshot` canónico ✅ *(veredicto del propietario, opción A)*

Codex destapó que hay **dos generadores de snapshot fiscal con esquemas divergentes** — la lección de AID-328, repetida:

- `InvoiceService::generateFiscalSnapshot()` (`:244-256`) — **11 claves, sin la calificación**. Es el que usa el camino canónico.
- `Invoice::generateFiscalContextSnapshot()` (`:810-828`) — **16 claves, e incluye `is_roi_applied`**, más `is_intra_community`, `issuer_is_oss`, `customer_is_vat_reg`.

Resultado: el documento emitido por el camino canónico congela un contexto fiscal que **no conserva la calificación** que decide PDF, umbral OSS y validación Verifactu. ADR-001 dice que cada documento congela su propio estado; hoy no lo cumple para este dato.

- **Opción A — añadir `is_roi_applied` a `generateFiscalSnapshot()` (recomendada).** Conserva el nombre de clave del otro generador (no inventa un tercer vocabulario), con aserción sobre el snapshot descifrado. Es aditivo dentro de un blob cifrado: no toca esquema.
- **Opción B — dejarlo fuera** y abrir ticket para reconciliar los dos generadores enteros (las 5 claves de diferencia, no solo esta).

**Decidido: A** — se añade `is_roi_applied` a `InvoiceService::generateFiscalSnapshot()`, reutilizando el nombre de clave del otro generador (no se inventa un tercer vocabulario), con aserción sobre el snapshot descifrado. Aditivo dentro de un blob cifrado: no toca esquema. La reconciliación de las otras cuatro claves divergentes va a ticket propio — AID-929 no las necesita y mezclarlas ampliaría el release sin caso que lo pida.

## 4. Barrido de campos de cabecera (veredicto por campo)

Las 35 claves `fillable` de `Invoice` contra lo que la conversión arrastra:

| Campo | Veredicto |
| -- | -- |
| `fiscal_number`, `prefix`, `serie`, `series_number`, `fiscal_year` | **Correcto no arrastrar.** La factura tiene su propia numeración correlativa (AID-390). |
| `invoice_date`, `issued_at` | **Correcto no arrastrar.** Fecha de expedición de la FACTURA (RD 1619/2012 art. 6.1.b). |
| `service_date` | **Arrastrado** ✓ (AID-559/D10). |
| `due_date`, `payment_terms`, `template_name` | **Arrastrados** ✓. |
| `paid_at` | **Correcto no arrastrar.** Un cobro sobre proforma no es el cobro de la factura. |
| `status` | **Correcto no arrastrar** (fijo `pending`); la proforma queda `CONVERTED`. |
| `user_id`, `billable_user_id` | **Arrastrados** ✓. |
| `company_fiscal_config_id`, `user_tax_profile_id` | **Correcto recalcular.** Config activa y perfil vigente al emitir; `FiscalChangeDetector` avisa o bloquea si divergen (ADR-001). |
| `proforma_id` | **Arrastrado** ✓ (AID-555). `rectifies_invoice_id` no aplica. |
| `issuer_snapshot`, `customer_snapshot` | **Correcto regenerar.** Cada documento congela su propio estado (ADR-001). |
| `fiscal_snapshot` | **Correcto regenerar, PERO incompleto**: el generador del camino canónico omite la calificación fiscal → D9. |
| `fiscal_verification_*` (5 campos) | **Correcto no arrastrar.** Registro fiscal del propio documento. |
| `taxable_amount`, `total_tax_amount`, `total_amount` | **Correcto recalcular**, y equivalen: suma de líneas clonadas con importes congelados. |
| `converted_invoice_id`, `converted_at` | **Correcto no arrastrar.** Enlace de la proforma hacia su factura. |
| `is_immutable`, `immutable_at` | **Correcto no arrastrar.** La factura se sella en su propia emisión. |
| **`is_roi_taxed`** | **PERDIDO — el defecto de este ticket** (D1/D2). |
| **`notes`** | **PERDIDO, y NO se arregla aquí** → AID-930 (D3). |

## 5. Behavior changes

- Una factura emitida por conversión desde una proforma con `is_roi_taxed = true` nace con `true`: ejerce el control de `VerifactuAdapter:227`, viaja en `metadata` a la AEAT, deja de sumar al umbral OSS y selecciona la plantilla reverse-charge. **Sin backfill**: las facturas históricas emitidas con el flag caído no se corrigen retroactivamente — son documentos sellados y su corrección es una rectificativa del consumidor.
- `createInvoice()` y `createProforma()` aceptan una clave nueva opcional. Quien no la use no cambia.
- **Nuevo rechazo (D4):** emitir factura **o proforma** con `is_roi_taxed = true` y cuota real distinta de cero lanza `ReverseChargeWithTaxException` sin dejar filas.
- **`VerifactuAdapter` pasa a rechazar también un N2 con cuota negativa** (D8): hoy la acepta, porque `invoiceHasRealTax()` comprueba `> 0`. Línea propia bajo **Fixed** con old/new.
- **El `fiscal_snapshot` del camino canónico incorpora `is_roi_applied`** (D9): los documentos emitidos desde esta versión congelan la calificación; los anteriores no la tienen y no se reescriben (son snapshots sellados).
- `Invoice::hasRealTax()` se añade a la superficie pública del modelo → regenera su snapshot de contrato con `bin/sync-contract-snapshots`, **con la entrada de CHANGELOG escrita antes** (el gate solo exige `[Unreleased]` no vacío, y un bullet ajeno le basta — lección 2026-07-22).

**Auditoría pre-upgrade, ejecutable ANTES de actualizar** (lección 2026-07-22). Corrige el P1-3 de Codex: la versión de la rev 2 solo miraba `total_tax_amount` y habría declarado limpio a un consumidor cuya cuota vive en el JSON `taxes_applied`. **Validada contra el esquema real** (MySQL 8.0.46; `taxes_applied` es `json` nullable):

```sql
SELECT i.id, i.fiscal_number, i.total_tax_amount
FROM invoices i
WHERE i.serie = 0                    -- InvoiceSerieType::PROFORMA
  AND i.is_roi_taxed = 1
  AND i.converted_invoice_id IS NULL
  AND ( i.total_tax_amount <> 0
     OR EXISTS (SELECT 1 FROM invoice_items it
                WHERE it.invoice_id = i.id AND it.total_tax_amount <> 0)
     OR EXISTS (SELECT 1 FROM invoice_items it2,
                JSON_TABLE(it2.taxes_applied, '$[*]' COLUMNS (amount INT PATH '$.amount')) jt
                WHERE it2.invoice_id = i.id AND jt.amount <> 0) );
```

Cada fila es una proforma que dejará de convertirse hasta que su consumidor decida si lo erróneo es la calificación o la cuota.

## 6. Plan de tests (TDD, rojo primero)

1. **Conversión propaga el flag** (criterio de cierre): proforma creada **por `createProforma(['is_roi_taxed' => true, ...])`** con líneas sin cuota → conversión → factura con `true`, sin fijarlo a mano en el lado de la factura.
2. **Conversión propaga el `false`**.
3. **`createProforma()` acepta el input** (D6), sin tocar el modelo.
4. **`createInvoice()` acepta el input** en emisión directa.
5. **Default preservado**: sin la clave → `false` en ambos métodos.
6. **Fail-loud en factura** (D4): con `true` y una línea con cuota → `ReverseChargeWithTaxException`, y **nada persistido**: ni `invoices`, ni `invoice_items`, ni avance del contador de `invoice_series_control` (assert explícito sobre el contador — es la garantía de que el rollback cubre la numeración).
7. **Fail-loud en proforma** (D4), mismo caso por `createProforma()`.
8. **Cuota real por `taxes_applied`**: `total_tax_amount = 0` con `taxes_applied[].amount != 0` → rechaza.
9. **Cuota negativa** (D8): línea con `total_tax_amount < 0` y `taxes_applied` vacío → rechaza.
10. **`Invoice::hasRealTax()` y el adaptador coinciden**: el adaptador delega, no duplica. Más un test del adaptador que fije el cambio de D8: un N2 con cuota negativa pasa a ser rechazado por la regla 1237.
11. **`fiscal_snapshot` congela la calificación** (D9): descifrar el snapshot del camino canónico y assertar `is_roi_applied`, en ambos valores.
12. **Sensibilidad medida** (lección 2026-07-31): retirar por separado la línea del `Invoice::create()` y el guard, confirmando que se ponen rojos exactamente los tests nuevos y ninguno más. Restauración desde copia previa, **nunca `git checkout`** sobre trabajo sin commitear (lección 2026-08-03).
13. **Harness gateados** antes del push (`tests/Concurrency` + los tres `Integration/`): el cambio no retira defaults de negocio, pero toca `createInvoice()`, por donde pasan los cuatro.

## 7. No objetivos

- **`notes` en la conversión** → AID-930 (D3).
- **Calificación fiscal en emisión recurrente** → AID-931 (D7).
- **Reconciliar por completo los dos generadores de snapshot fiscal** (las otras 4 claves divergentes) → ticket propio si D9 = A.
- **Backfill de facturas ya emitidas**: documentos sellados; la corrección es una rectificativa del consumidor.
- **Derivar el flag desde VIES o del perfil fiscal** (doctrina AID-309).
- **Endurecer `save()` contra escritura de contenido fiscal en documentos sellados** (§2): es un agujero real y preexistente, ajeno a este ticket.
- **Tocar `TaxCalculationService`, plantillas PDF o la lógica de cálculo.** El guard rechaza; no corrige importes.

## 8. Adjudicación de la lectura del propietario (rev 1 → rev 2)

| # | Hallazgo | Verificación | Decisión | Dónde aterriza |
| -- | -- | -- | -- | -- |
| H1 | `notes` fuera: prevalece sobre las notas de plantilla y viaja a la AEAT | **Confirmado.** `DomPDFService:569-580`; `VerifactuAdapter:86` | Aceptado (opción B) | D3, §4, AID-930 |
| H2 | «Riesgo nulo en conversión» es incorrecto | **Confirmado, la rev 1 estaba mal.** `TaxCalculationService` no lee el flag | Aceptado — fail-loud ahora | D4, §5, §6 |
| H3 | El rechazo del adaptador llega tarde | **Confirmado.** `VerifactuAdapter:196`, con el documento ya sellado | Aceptado | D4 |
| H4 | `createProforma()` tiene shape propio | **Confirmado.** `:413-424` | Aceptado | D6 |
| H5 | La recurrente no se arregla ampliando `createInvoice()` | **Confirmado.** `RecurringBillingService:422-437` | Aceptado — alcance limitado | D7, AID-931 |
| H6 | `Larabill::version()` devuelve `1.0.0` | **Confirmado.** `src/Larabill.php:19-21` | Fuera de AID-929 | AID-932 |

## 8bis. Adjudicación de la ronda Codex (rev 2 → rev 3)

Gate: **FAIL (3 P1)**. Los ocho hallazgos verificados contra código antes de aceptarse. Codex además **cerró a favor del diseño** los dos puntos más delicados del guard: el contador de `invoice_series_control` sí se revierte (transacción anidada = savepoint; `PDO::commit()` solo en nivel 1) y `attempts: 3` no reintenta una `RuntimeException`.

| # | Hallazgo | Verificación | Decisión | Dónde aterriza |
| -- | -- | -- | -- | -- |
| C1 | [P1] El rechazo nuevo exige major | **Norma citada correcta** (`STABILITY.md:24`), pero su párrafo gobierna releases **con esquema**. Medición: 0 proformas en las 3 bases, staging vacío, 0 dependents | **Rechazado con fundamento** — MINOR | D5 (con la tabla de medición) |
| C2 | [P1] `hasRealTax()` deja pasar cuota negativa | **Confirmado.** `VerifactuAdapter:396` usa `> 0`, no `!== 0` | Aceptado; **cómo** corregirlo queda abierto porque cambia el adaptador | **D8 (abierta)**, §6 test 9 |
| C3 | [P1] La auditoría SQL no cubre `taxes_applied` | **Confirmado**, y contradecía el test 8 del propio plan | Aceptado — consulta reescrita con `JSON_TABLE` y **validada** contra el esquema real | §5 |
| C4 | [P2] El flag no gobierna la rama N2 | **Confirmado.** `VerifactuAdapter:187` decide por países + VAT; el flag actúa en `:227`, `:89` | Aceptado — hecho falso heredado del ticket, corregido | §1 |
| C5 | [P2] El `fiscal_snapshot` canónico omite la calificación; dos generadores divergentes | **Confirmado.** `InvoiceService:244-256` (11 claves) vs `Invoice:810-828` (16, con `is_roi_applied`) | Aceptado; alcance abierto | **D9 (abierta)**, §4, §6 test 11 |
| C6 | [P2] El rollback no garantiza ausencia de efectos externos | **Correcto.** La transacción revierte SQL, no jobs ni correos de observers del consumidor | Aceptado — garantía reformulada a «no quedan filas» | D4 |
| C7 | [P2] `save()` evade el guard de `update()` | **Confirmado con evidencia explícita.** `Invoice:247-253` lo documenta como bypass deliberado | Aceptado — afirmación corregida | §2, §7 |
| C8 | [P2] La API de la excepción sin especificar | **Correcto.** `docs/api-surface.md:25` clasifica toda excepción como `@api` | Aceptado — firma fijada | D4 |

## 9. Tickets derivados

1. **AID-930** — `notes` en la conversión: qué prevalece, la nota congelada de la proforma o la nota fiscal de la plantilla de destino.
2. **AID-931** — calificación fiscal en la emisión recurrente.
3. **AID-932** — `Larabill::version()` devuelve `1.0.0` con el paquete en 6.10.0.
4. **Reconciliar los dos generadores de snapshot fiscal** (las 4 claves divergentes restantes: `is_intra_community`, `issuer_is_oss`, `customer_is_vat_reg`, `company_fiscal_config_id`/`user_tax_profile_id`) — derivado firme de D9.

## 10. Estado

**Todas las decisiones cerradas** (D1-D9). Aprobado para TDD el 2026-08-11.

Orden de ejecución: `composer update` (`php83`, binario del lock de este paquete) en commit aparte → rama desde `main` → TDD con los 13 tests de §6, rojo primero y sensibilidad medida.
