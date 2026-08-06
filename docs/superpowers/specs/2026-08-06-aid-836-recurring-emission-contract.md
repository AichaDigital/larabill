# Diseño AID-836: el flujo recurrente emite por el contrato canónico de emisión

> **Status**: **rev 3 — aprobado para implementación** (2026-08-06). La rev 1 recibió lectura adversarial del propietario (4 P1 + 3 P2, adjudicados en §8) y la rev 2 una ronda Codex (3 P1 + 4 P2, adjudicados en §8bis; todos endurecimientos verificados contra código, sin discrepancia con las decisiones del propietario). Las 3 preguntas abiertas están cerradas en §7.
> **Date**: 2026-08-06
> **Issue**: AID-836 (bloquea AID-656 — OSS en `clientes`)
> **Relates**: ADR-001 (snapshots congelados de emisor y receptor), ADR-003 (`user_id` = emisor, `billable_user_id` = receptor), AID-390 (dueño único de numeración), AID-554 (lock de fila en conversión), AID-570 (el retry pertenece a la transacción MÁS exterior), AID-589 (la serie fiscal la decide el consumidor), AID-352 (su CHANGELOG ya asumía facturas recurrentes inmutables), lección 2026-07-30 de lara-verifactu (ninguna llamada de red dentro de `DB::transaction`), `STABILITY.md`.
> **Versión objetivo**: `v6.10.0` (MINOR — superficie añadida: un contrato, dos excepciones, una clave de config). **Cero migraciones**: el upgrade manifest no se toca.

## 1. Problema

`RecurringBillingService::createInvoiceForService()` (`src/Services/RecurringBillingService.php:173-259`) emite facturas saltándose todo el aparato fiscal del paquete. Construye el `Invoice::create([...])` con 11 campos y estas ausencias, verificadas contra `v6.9.1`:

- **`user_id` = `$customer->id`** (línea 215): semántica invertida respecto a ADR-003. En el contrato canónico `user_id` es el EMISOR (`parent_user_id ?? id`, `InvoiceService.php:122`) y `billable_user_id` el receptor. Esto contamina `EuSalesThresholdService::thresholdOwner()` (atribuye importes OSS a `user_id`) — el defecto que destapó AID-656.
- **Sin `billable_user_id`** → el hook `creating` de `Invoice` (`src/Models/Invoice.php:715`) no genera `customer_snapshot` ni resuelve `user_tax_profile_id`, y `FiscalIntegrityChecker::assertCanInvoiceUser()` nunca se ejecuta. ADR-001 roto por omisión: el emisor se congela, el receptor no.
- **Sin impuestos**: la línea se crea con `InvoiceItem::create()` directo, sin `TaxCalculationService`; `taxable_amount == total_amount` y `total_tax_amount` queda en el default 0 de la columna.
- **Sin sellar**: `is_immutable = false` con `status = SENT`. El CHANGELOG de AID-352 ya afirmaba que las líneas de facturas recurrentes emitidas son inmutables (ADR-001) — el código nunca lo cumplió.
- **Sin registro fiscal posible**: sin `customer_snapshot`, `VerifactuAdapter::isSimplifiedInvoice()` clasificaría toda recurrente como **F2 simplificada en silencio**, y `validateForVerifactu()` la rechaza por tres motivos (no inmutable, sin `userTaxProfile`, sin `billableUser`). Activar el flujo hoy emitiría facturas con número fiscal consumido que nunca llegan a la AEAT.
- **Estado partido**: `updateNextBillingDate()` (línea 79) y `RecurringInvoiceGenerated::dispatch()` (línea 82) corren en `processRecurringBilling()` FUERA de la transacción de creación. Un fallo entre medias deja factura persistida con número consumido y servicio sin avanzar (re-facturación) o contabiliza `failed` una emisión que sí ocurrió.

El flujo está INACTIVO en `clientes` (cero callsites): no hay incidente en curso, es un candado pre-activación.

## 2. Contrato objetivo (los 6 puntos del ticket)

1. La factura recurrente nace con el receptor fijado (`billable_user_id`), no inferido después.
2. Snapshot fiscal completo (emisor + receptor + contexto) congelado en la creación, como el resto de caminos.
3. Frontera de emisión atómica: creación, numeración, sellado y registro en una frontera transaccional; nada persistido si el conjunto no es fiscalmente apto.
4. Sellado y registro Verifactu: el flujo recurrente deja de ser el único que emite sin sellar y sin vía de registro.
5. Punto de integración del consumidor DENTRO de la emisión (un listener sobre `RecurringInvoiceGenerated` llega tarde: número consumido, servicio avanzado).
6. `next_billing_date` avanza solo tras emisión correcta.

## 3. Decisiones

### D1 — Delegar en `InvoiceService::createInvoice()`, no replicar

`createInvoiceForService()` deja de hacer `Invoice::create()` directo y delega en el camino canónico (`src/Services/InvoiceService.php:91-185`), exactamente como ya hace `convertProformaToInvoice()`. Eso da de una vez: receptor (`billable_user_id => $service->customer_id`), emisor correcto, `CompanyFiscalConfig` obligatoria (fail-loud con `RuntimeException` si no hay activa), `UserTaxProfile` del receptor, serie vía `InvoiceSeriesResolver`, numeración AID-390, impuestos vía `TaxCalculationService`, los 3 snapshots cifrados y el sellado.

- **Descartado — replicar la lógica en el servicio recurrente:** duplicaría al dueño del contrato de emisión; es exactamente la clase de duplicación intra-paquete que costó AID-390 (cuatro generadores de numeración).

### D2 — Sellado SIEMPRE (`make_immutable => true`, sin opción)

Las recurrentes nacen `SENT`: son facturas finales emitidas automáticamente, no borradores. Se sellan incondicionalmente, como la conversión de proforma. El status se pasa como `InvoiceStatus::SENT->value` (int, passthrough ya soportado por `mapStatusToEnum()` — sin tocar ese método, ver §8/H7).

- **Descartado — exponer la opción al llamante:** una recurrente mutable con status SENT es el estado inválido que el guard de `InvoiceItem::update()` (AID-558) existe para impedir; y AID-352 ya lo declaró comportamiento contractual en el CHANGELOG.

### D3 — Punto de integración: contrato bindeable invocado DENTRO de la frontera; el paquete NO auto-registra

Nuevo contrato `@api` `src/Contracts/Services/RecurringEmissionHookContract.php`:

```php
public function afterEmission(Invoice $invoice, ArticleServiceStatus $service): void;
```

Se invoca DENTRO de la frontera transaccional, DESPUÉS del sellado (el registro Verifactu exige `is_immutable = true`) y después de avanzar `next_billing_date`. Si lanza → rollback total: ni factura, ni número consumido, ni avance de fecha. El consumidor (`clientes`) lo bindea para registrar Verifactu (`InvoiceVerifactuService::registerInvoice()`, que es DB-only — la sumisión AEAT real es asíncrona en lara-verifactu) y acumular OSS (AID-656).

Contrato documentado en el docblock, las cinco cláusulas:

- Solo escrituras de base de datos **por la MISMA conexión que abre la frontera** (la conexión por defecto de larabill). El «rollback total» solo cubre la transacción ambiente: un modelo con `$connection` propia u otra conexión commitea por su cuenta y sobrevive al rollback. Prohibido abrir transacciones/commits independientes dentro del hook.
- Nada de efectos externamente observables: ni red, ni mail, ni jobs sin `afterCommit` (la frontera lleva retry). El logging se TOLERA, con la advertencia de que bajo retry/rollback puede duplicar líneas o describir trabajo revertido (p.ej. los `Log::info` de `InvoiceVerifactuService::registerInvoice()`).
- Puede re-ejecutarse entero si la frontera reintenta por deadlock (`attempts: 3` solo reintenta ante error de concurrencia).
- Sobre la factura sellada solo son legales los campos `fiscal_verification_*` (ventana del guard de `Invoice::update()`); todo lo demás va a tablas propias del consumidor.
- Lanzar = rechazar la emisión completa.

Alternativas descartadas:

- **Listener sobre `RecurringInvoiceGenerated`:** post-commit — llega con el número consumido y el servicio avanzado; es la inviabilidad que motivó el ticket.
- **Auto-registro del paquete (flag de config que llame a `InvoiceVerifactuService`):** ningún otro camino de emisión registra automáticamente (el registro siempre lo invoca el consumidor); acoplaría el flujo recurrente a España y duplicaría mecanismos (el consumidor seguiría necesitando el hook para OSS). El patrón de la casa es «el paquete expone servicio `@api`, el consumidor lo envuelve» (AID-289).
- **Outbox + `FiscalSubmissionHandler` (spec v7 §6.7):** el diseño completo correcto a largo plazo, pero v7 está CANCELADO y exigiría major con registro central de gobierno. Este ticket entrega el candado en la línea 6.x.

### D4 — Gate fail-loud opt-in: `larabill.recurring_billing.require_emission_hook`

Clave nueva, default `false`, **leída en el punto de uso** (lección mustache 2026-07-31: el default vive donde se lee, no en el fichero publicado). Si `true` y no hay hook bindeado → `MissingRecurringEmissionHookException` (nueva, `@api`, mensaje accionable) ANTES del bucle de servicios: nada emitido, ningún número consumido. Cumple el espíritu del spec v7 §6.3 («no handler → typed failure BEFORE issuing») sin esperar al major.

- **El gate NO aplica en `dryRun`** (ronda Codex): la simulación no emite ni ejecuta el hook, y debe servir precisamente para diagnosticar una instalación aún sin configurar.

- **Descartado — reutilizar `compliance.requires_tax_verification`:** esa clave ya tiene semántica propia consumida por `RegionalContext`; reutilizarla haría que consumidores que hoy la tienen a `true` sin hook pasaran de funcionar a lanzar — un breaking disfrazado de minor.

### D5 — Frontera atómica con relectura bloqueante Y re-verificación del periodo seleccionado

Por servicio elegible (seleccionado y filtrado por `shouldGenerateInvoice()` como hoy):

```php
$expectedPeriodStart = $service->next_billing_date;                                      // (0) periodo que ESTA ejecución seleccionó

$result = DB::transaction(function () use ($service, $expectedPeriodStart, $date) {
    $svc = ArticleServiceStatus::query()->lockForUpdate()->findOrFail($service->id);     // (a)
    if (! $svc->next_billing_date?->isSameDay($expectedPeriodStart)                      // (b) otro run ya procesó el periodo
        || ! $svc->isEligibleForRecurringBilling()) {                                    // (b') criterios de selección revalidados
        return ['invoice' => null, 'created' => false, 'skipped' => true, 'service' => $svc];
    }
    $emission = $this->createInvoiceForService($svc, $date);                             // (c) crea + sella (savepoint interior)
    $this->updateNextBillingDate($svc);                                                  // (d)
    if ($emission['created'] && $this->emissionHook !== null) {
        $this->emissionHook->afterEmission($emission['invoice'], $svc);                  // (e)
    }
    return $emission + ['service' => $svc];
}, 3);                                                                                    // (f) attempts en la frontera EXTERIOR
```

- **(0)+(b) Captura del periodo esperado ANTES del lock y comparación tras releer.** Hallazgo P1 de la lectura adversarial: sin (b), un segundo run solapado que espera el lock relee el servicio con `next_billing_date` YA avanzado por el primero y emitiría el periodo SIGUIENTE por anticipado (enero y febrero en el mismo tick, doble hook, fecha en marzo) — el check de idempotencia no lo frena porque filtra por `service_date_from` del periodo, que es distinto. Con (b), el segundo run detecta que el periodo que seleccionó ya no es el vigente y se retira (`skipped`, no `failed`).
- **(b') Los criterios de selección se revalidan bajo el lock** (ronda Codex, P1): el periodo puede coincidir y aun así el servicio haber dejado de ser facturable — una suspensión/cancelación concurrente conserva `next_billing_date`. Bajo el lock se re-verifica que el servicio sigue cumpliendo los criterios del query de selección (status activo, `next_billing_date` presente); si no → `skipped`. La forma concreta (método helper en el modelo o condiciones inline idénticas al scope `active()`) se decide en implementación, con fuente ÚNICA compartida con la selección para que no deriven.
- **(a) `lockForUpdate()` sobre la fila del servicio** — además de habilitar (b): bajo retry, el `update()` del primer intento deja el modelo EN MEMORIA con la fecha avanzada aunque la BD haya revertido; releer dentro del closure lo hace resume-safe. Mismo patrón que el lock de proforma de AID-554.
- **(f) `attempts: 3` SOLO aquí** (AID-570): el `attempts` interior de `createInvoice` pasa a ser savepoint y no salva nada; el retry solo protege en la transacción más exterior. `createInvoiceForService()` pierde su `DB::transaction` propio. El rollback devuelve el número consumido (estado de BD de la misma transacción).
- **El closure es side-effect-free fuera de la BD** (contrato de pureza de `InvoiceService.php:93-97`): ningún dispatch dentro.
- `catch (\Exception)` del bucle pasa a **`catch (\Throwable)`**: con código del consumidor dentro de la frontera, un `TypeError` del hook abortaría el run entero sin `RecurringBillingCompleted`. `RecurringBillingFailed` ya tipa `Throwable`.
- **Criterio de aceptación (no follow-up):** fork test en `tests/Concurrency/RecurringBillingConcurrencyTest.php` — N procesos facturando el mismo servicio/periodo → exactamente 1 factura, fecha avanzada exactamente un periodo, hook ejecutado exactamente 1 vez. Calibrado con la metodología ad-concurrency-gate: suelo medido, techo declarado (`(N-1) × coste < innodb_lock_wait_timeout`), prueba de sensibilidad (lock y check (b) desactivados → el test detecta el duplicado), gate `RUN_CONCURRENCY_IT=1` + MySQL como los 4 existentes.

### D6 — Camino idempotente: flag `created`, el hook no se repite, y el check se acota a emisiones recurrentes REALES

`createInvoiceForService()` pasa a devolver la forma discriminada `array{invoice: ?Invoice, created: bool, skipped: bool}` (`invoice` null SOLO cuando `skipped`; el bucle estrecha antes de tocar `invoice->id` — PHPStan L8):

- **El check idempotente se endurece** (ronda Codex, P1): hoy acepta CUALQUIER `InvoiceItem` cuyo metadata traiga el `service_status_id` y cuyo `service_date_from` coincida. Tras D10 el camino canónico también persiste metadata, así que una PROFORMA creada por el consumidor con esos metadatos sería tomada como emisión existente (fecha avanzada, factura y hook omitidos). El check pasa a exigir además `source_reference.type = 'article_service'` y que la factura vinculada NO sea proforma (`serie !== PROFORMA`).

- **`created === false` con factura existente** (el check por metadata la encuentra): NO se re-invoca el hook (duplicaría registro Verifactu y acumulación OSS) y NO se re-despacha `RecurringInvoiceGenerated`. `updateNextBillingDate()` **SÍ corre**: es el camino de reparación tras un crash entre el commit de la factura y el avance de la fecha (el test de idempotencia existente simula exactamente eso y depende de ello).
- **Limitación documentada (CHANGELOG):** facturas legacy pre-v6.10 encontradas por el check idempotente (sin sellar, sin registro) no se re-procesan por el hook — sellarlas o registrarlas retroactivamente es backfill del consumidor, no de este flujo.

### D7 — El receptor debe tener perfil fiscal vigente: sin `UserTaxProfile` NO se emite

Hallazgo P1: delegar no garantiza aptitud Verifactu — el canónico tolera `UserTaxProfile` null (`user_tax_profile_id` nullable), el `FiscalIntegrityChecker` solo rechaza DUPLICADOS (no ausencia, `FiscalIntegrityChecker.php:122`), y `registerInvoice()` no invoca `validateForVerifactu()`. Resultado sin esta decisión: snapshot con `tax_id` null → el adapter clasifica **F2 simplificada en silencio** — la misma degradación que este ticket viene a cerrar, por otra puerta.

**Decisión:** la emisión recurrente EXIGE `UserTaxProfile` vigente del receptor a fecha de emisión **con identidad fiscal no vacía (`tax_id`)**. La existencia del perfil no basta (ronda Codex, P1): `tax_id` es nullable en `user_tax_profiles` y `getValidForOwnerAt()` solo comprueba vigencia temporal — un perfil vigente con `tax_id` null pasaría el gate y llegaría igualmente a F2. `createInvoiceForService()` verifica ambas cosas DENTRO de la frontera (antes de delegar); ausencia de perfil O perfil sin `tax_id` → `MissingUserTaxProfileException` (nueva, `@api`, nombra servicio, receptor y qué falta) → rollback, el servicio cuenta como `failed`, la fecha no avanza, ningún número consumido. Es el mismo principio de AID-589: un dato fiscal del negocio no se degrada en silencio, se falla loud.

- **Descartado — permitir F2 (simplificada) para receptores sin perfil:** la recurrente es emisión desatendida — nadie está delante para notar la clasificación errónea; una F2 recurrente B2B es exactamente el defecto silencioso de origen. Si algún día un consumidor real factura recurrente B2C anónimo legítimo (F2 deliberada), se reabre con decisión y opt-in explícito propios — no como default especulativo.

### D8 — `$date` es fecha de ELEGIBILIDAD; la fecha de expedición es la real de emisión

Hallazgo P1: la rev 1 proponía una clave `invoice_date` en el canónico para pasar `$date`. Eso fabricaba un documento con ejes temporales contradictorios en ejecuciones históricas: `invoice_date` = 2024 con `issued_at = now()` (`InvoiceService.php:141`), ejercicio del contador calculado con la fecha ACTUAL (`InvoiceNumberingService`), config/perfil fiscal vigentes HOY (`InvoiceService.php:103,110`), y Verifactu priorizando `issued_at` (`VerifactuAdapter.php:70`: `issued_at ?? invoice_date`).

**Decisión:** NO se añade override de `invoice_date`. `$date` (el parámetro de `processRecurringBilling()`) decide únicamente QUÉ servicios son elegibles (`shouldGenerateInvoice()`); la factura lleva `invoice_date = now()` (el hardcode canónico), coherente con `issued_at`, el ejercicio del contador y los snapshots — todos anclados al instante real de emisión. El PERIODO facturado vive donde siempre: `service_date_from/to` de la línea. `due_date` se calcula desde `now()` + `payment_terms_days`. El devengo (art. 75 LIVA) se resuelve en AID-656 sobre `service_date_from/to` — el propio ticket declara `invoice_date` NO aprobado como proxy del devengo.

- **Behavior change** (en negrita en CHANGELOG): hoy el recurrente escribe `invoice_date = $date`; en un run atrasado o manual retro-datado eso producía la contradicción descrita. Si en el futuro se exige backdating real, exige propagar UN único instante fiscal por numeración, snapshots, impuestos e `issued_at` — decisión propia, no una clave suelta.

### D9 — `RecurringInvoiceGenerated`: notificación best-effort, at-most-once, aislada del resultado fiscal

Hallazgo P1: con la estructura actual, un listener síncrono que lance DESPUÉS del commit caería en el `catch` y contabilizaría como `failed` una factura ya emitida — el mismo estado partido que la spec denuncia. Y un crash entre commit y dispatch pierde el evento para siempre (D6 prohíbe reemitirlo).

**Decisión:** los eventos del flujo son **notificación best-effort con entrega at-most-once**; el punto FIABLE de integración es el hook (D3), que viaja en la transacción. En consecuencia:

- El dispatch de `RecurringInvoiceGenerated` se hace FUERA del `try` principal del bucle, en `try/catch (\Throwable)` propio que hace `Log::error` y nada más: un listener que lanza NO altera `processed`/`failed` ni el estado fiscal.
- **Lo mismo aplica a `RecurringBillingFailed` y `RecurringBillingCompleted`** (ronda Codex, P2): `RecurringBillingFailed::dispatch()` vive dentro del manejo del fallo — un listener síncrono del consumidor que lance ahí abortaría el run entero pese al `catch (\Throwable)`. Los tres dispatches se aíslan con el mismo patrón log-only.
- Se despacha con el **modelo fresco** (`$result['service']`, la instancia bloqueada y actualizada) — no con el `$service` pre-lock, que llevaría `next_billing_date` obsoleto (hallazgo P2).
- La ventana de pérdida (crash entre commit y dispatch) queda documentada en el docblock del evento: quien necesite entrega durable necesita outbox — diseño v7, major, fuera de esta línea.

### D10 — Piezas previas en `InvoiceService` (solo la carga-portante)

**La rama calculada de `createInvoiceItem()` (líneas 376-395) persiste lo que el tipo ya promete:** `item_type`, `internal_code`, `unit_measure_id`, `service_date_from`, `service_date_to`, `metadata`. Hoy los descarta en silencio pese a que el phpstan-type `InvoiceItemData` los declara (líneas 29-46). **Carga-portante para este ticket:** la idempotencia del recurrente filtra por `json_extract(metadata, '$.source_reference.service_status_id')` + `service_date_from`; sin esta pieza, la delegación duplicaría facturas con número consumido. Se implementa y prueba ANTES de delegar.

- **Retirado de este ticket (scope creep, hallazgo P2):** el endurecimiento de `mapStatusToEnum()` (mapear `'sent'`/`'overdue'`/`'converted'`, default fail-loud). El recurrente pasa int y no lo necesita; cambiar el comportamiento observable de una API `@api` para todos los consumidores exige justificación y tests propios → ticket follow-up.
- **Retirado de este ticket:** la clave `invoice_date` (sustituida por D8).

### D11 — Compatibilidad del constructor y superficie deprecada

- Constructor de `RecurringBillingService`: se añaden AL FINAL `?InvoiceService $invoiceService = null` (fallback `app()`) y `?RecurringEmissionHookContract $emissionHook = null` (fallback `app()->bound(...) ? app(...) : null` — el binding del consumidor gana aunque el servicio se construya con `new`). `new RecurringBillingService(new PricingService)` de los tests existentes sigue funcionando.
- `$invoiceNumbering` y `$seriesResolver` quedan sin uso interno tras delegar: se CONSERVAN como parámetros (superficie `@api`) marcados `@deprecated` (retirada en el próximo major, per `STABILITY.md`). `generateInvoiceNumber()` (protected, clase `final`) se elimina — no es superficie alcanzable.

## 4. Behavior changes (irán en negrita en el CHANGELOG de v6.10.0)

- La factura recurrente nace con `user_id` = emisor y `billable_user_id` = receptor (antes: `user_id` = cliente, `billable_user_id` null).
- La factura recurrente calcula impuestos (antes: `total_tax_amount = 0`, total sin IVA).
- La factura recurrente nace sellada (`is_immutable = true`, `immutable_at`, `issued_at` poblados).
- El flujo recurrente exige `CompanyFiscalConfig` activa, serie fiscal configurada Y `UserTaxProfile` vigente del receptor (fail-loud; antes emitía sin nada de eso).
- `invoice_date` de la recurrente es la fecha REAL de emisión (`now()`), no el `$date` de proceso; `$date` solo decide elegibilidad. `due_date` se calcula desde la emisión.
- `RecurringInvoiceGenerated` se despacha solo post-commit, solo cuando la factura se CREÓ (antes se re-despachaba en el camino idempotente), con el servicio actualizado, y es best-effort: un listener que lanza no altera el resultado del run.
- Facturas recurrentes legacy (pre-v6.10) no se re-procesan por el hook: backfill del consumidor.

## 5. Plan de tests (TDD — rojo primero en cada pieza)

Fichero nuevo `tests/Unit/Services/RecurringBillingEmissionTest.php` + tests directos sobre `InvoiceService` + fork test:

- **Pieza D10:** la rama calculada persiste `item_type`/`internal_code`/`unit_measure_id`/fechas de servicio/`metadata` (test directo sobre `createInvoice`).
- **Receptor/snapshots (contrato 1-2):** `billable_user_id` = cliente, `user_id` = `parent ?? self`; 3 snapshots not null; `user_tax_profile_id` set; sin `CompanyFiscalConfig` → `failed`, cero facturas, número virgen, fecha intacta.
- **Perfil obligatorio (D7):** receptor sin `UserTaxProfile` vigente → `MissingUserTaxProfileException` capturada como `failed`, rollback completo probado (cero facturas, número virgen, fecha intacta, hook no invocado). Segundo caso: perfil VIGENTE pero con `tax_id` null → mismo rechazo (la vigencia no basta).
- **Impuestos/sellado (contrato 2-4):** línea con tasa 21% → `total_tax_amount > 0`, `taxes_applied` poblado, totales cuadran; `is_immutable`, `immutable_at`, `issued_at`, status SENT; `invoice_date` = fecha de emisión real aunque `$date` sea pasado (D8).
- **Atomicidad (el test estrella, contrato 3/5/6):** hook que lanza → cero facturas/items, número NO consumido (una emisión sana posterior obtiene `series_number === 1`), `next_billing_date` intacto, evento no despachado, `failed === 1`.
- **Periodo re-verificado (D5.b):** servicio cuyo `next_billing_date` cambia entre la selección y el lock (simulado alterando la fila antes de procesar) → `skipped`, sin factura, sin hook.
- **Elegibilidad re-verificada (D5.b'):** servicio SUSPENDIDO entre la selección y el lock (mismo periodo) → `skipped`, sin factura, sin hook.
- **Idempotencia acotada (D6):** una PROFORMA con metadata `source_reference` del servicio y mismo `service_date_from` NO satisface el check — el run emite la factura fiscal real; y una línea con `source_reference.type` distinto tampoco.
- **Gate en dry-run (D4):** `require_emission_hook = true` sin binding + `dryRun = true` → la simulación corre sin lanzar.
- **Eventos aislados (D9):** listener de `RecurringBillingFailed` que lanza → el run continúa con el resto de servicios y `RecurringBillingCompleted` se despacha.
- **Hook:** recibe la factura SELLADA y el servicio correcto; NO se re-invoca en el camino idempotente (contador === 1 en dos runs con reset de fecha); evento despachado exactamente 1 vez y con `next_billing_date` avanzado en el payload (D9).
- **Evento aislado (D9):** listener síncrono que lanza → la factura queda emitida, `processed === 1`, `failed === 0`, error logueado.
- **Gate D4:** `require_emission_hook = true` sin binding → `MissingRecurringEmissionHookException` antes de emitir nada.
- **Robustez D5:** hook lanza `\Error` en el servicio 1, servicio 2 sano → `processed 1 / failed 1` y `RecurringBillingCompleted` despachado.
- **Concurrencia (criterio de aceptación, D5):** `tests/Concurrency/RecurringBillingConcurrencyTest.php` — fork de N procesos sobre el mismo servicio/periodo → 1 factura, 1 avance, 1 hook; con prueba de sensibilidad (mecanismo desactivado → detecta el duplicado) y calibración ad-concurrency-gate.

Fallout en tests existentes (fixtures, no aserciones): `RecurringBillingServiceTest`, `ProcessRecurringBillingTest` y `QuantityBase100ScaleTest` necesitan sembrar `CompanyFiscalConfig::factory()` y `UserTaxProfile` vigente del cliente (exigencias nuevas D1+D7); las aserciones actuales de periodos/metadata/idempotencia/numeración sobreviven gracias a D10. El test existente que asertaba `invoice_date = $date` (si existe) se ajusta a D8.

Verificación de calidad: suite completa (`php83`), PHPStan L8, Pint, mutación (`pest --mutate`, gate 68 — el literal `attempts: 3` se trata con la misma estrategia que en AID-570), y los harness MySQL gateados en local antes de empujar (CRITICAL_RULES §A — este cambio endurece requisitos del camino de emisión), incluido el fork test nuevo.

## 6. Documentación que cambia en el mismo PR

- **`docs/api-surface.md`:** contadores y listados — contrato nuevo (`RecurringEmissionHookContract`), dos excepciones nuevas, deprecations del constructor.
- **`docs/QUEUE_AND_RECURRING_BILLING.md`:** binding del hook (ejemplo), gate `require_emission_hook`, semántica de la frontera atómica, requisitos de emisión (config fiscal, serie, perfil del receptor), semántica best-effort del evento.
- **`config/larabill.php`:** clave nueva documentada in situ.
- **CHANGELOG `[Unreleased]`** con los behavior changes de §4 en negrita.

## 7. Preguntas cerradas (planteadas por la lectura adversarial)

1. **¿`$date` es fecha de evaluación del scheduler o fecha legal de expedición?** De evaluación/elegibilidad exclusivamente (D8). La expedición es el instante real de emisión (`now()`), coherente con `issued_at`, contador y snapshots.
2. **¿Una recurrente sin `UserTaxProfile` puede ser F2 o debe rechazarse?** Se rechaza fail-loud (D7). F2 recurrente solo se reabrirá con un consumidor real B2C que la pida, como opt-in explícito.
3. **¿`RecurringInvoiceGenerated` es best-effort o entrega durable?** Best-effort, at-most-once, aislado del resultado fiscal (D9). El canal fiable es el hook; durabilidad real = outbox = diseño v7 (major).

## 8. Adjudicación de la lectura adversarial (rev 1 → rev 2)

- **H1 (P1, lock no impide segunda factura anticipada):** ACEPTADO → D5.(0)+(b) captura y re-verificación del periodo; fork test pasa de follow-up a criterio de aceptación.
- **H2 (P1, `invoice_date` retroactiva contradictoria):** ACEPTADO → D8, se retira el override; `$date` = elegibilidad.
- **H3 (P1, delegar no garantiza aptitud Verifactu / F2 silenciosa sin perfil):** ACEPTADO → D7, perfil obligatorio con excepción tipada y rollback probado.
- **H4 (P1, semántica de fallo del evento post-commit):** ACEPTADO → D9, best-effort at-most-once aislado del resultado.
- **H5 (P2, evento con modelo obsoleto):** ACEPTADO → D9, se despacha el modelo bloqueado/actualizado.
- **H6 (P2, «rollback total» solo con la misma conexión):** ACEPTADO → cláusula primera del contrato del hook (D3).
- **H7 (P2, `mapStatusToEnum()` es scope creep):** ACEPTADO → retirado de este ticket (D10); follow-up propio para el default silencioso.
- **Adicional (docs):** ACEPTADO → §6.

## 8bis. Adjudicación de la ronda Codex (rev 2 → rev 3)

Verificados contra código antes de aceptar; ninguno discrepa de las decisiones del propietario — son endurecimientos de las mismas:

- **C1 (P1, perfil vigente con `tax_id` null pasa el gate y llega a F2):** ACEPTADO (verificado: `tax_id` nullable en la migración; `getValidForOwnerAt()` solo comprueba vigencia) → D7 exige identidad fiscal no vacía.
- **C2 (P1, el lock no revalida que el servicio siga facturable — suspensión concurrente):** ACEPTADO → D5.(b') revalida los criterios de selección bajo el lock.
- **C3 (P1, la idempotencia acepta cualquier línea con `service_status_id`+periodo, incluidas proformas post-D10):** ACEPTADO → D6 acota por `source_reference.type` + factura no-proforma.
- **C4 (P2, `registerInvoice()` loguea — no es estrictamente «DB-only»):** ACEPTADO → cláusula del contrato reescrita: se prohíben efectos externamente observables; el logging se tolera con advertencia de duplicado/rollback.
- **C5 (P2, un listener de `RecurringBillingFailed` que lanza aborta el run pese al `\Throwable`):** ACEPTADO → D9 aísla los TRES dispatches del flujo.
- **C6 (P2, el gate rompería `dryRun`):** ACEPTADO → D4 no aplica en dry-run.
- **C7 (P2, shape de retorno contradictorio para PHPStan — `invoice` null en `skipped`):** ACEPTADO → D6 declara la forma discriminada `array{invoice: ?Invoice, created: bool, skipped: bool}` con narrowing en el bucle.

## 9. No-objetivos y follow-ups

- **Fuera de alcance (pasos 3-4 del ticket):** actualizar `clientes` y retomar AID-656 (binding del hook, acumulador OSS, devengo art. 75 LIVA sobre `service_date_from/to`) viven en el repo `clientes`.
- **Follow-up 1:** `mapStatusToEnum()` — default silencioso (string desconocido → DRAFT) a fail-loud, con tests de todos los valores; ticket propio (H7).
- **Follow-up 2:** backdating real de emisión (si alguna vez se exige): un único instante fiscal propagado por numeración, snapshots, impuestos e `issued_at` (D8).
- **Registro de conformidad:** gate de consumidores contra el commit candidato exacto (`larabill` → `clientes`) antes de taguear v6.10.0.
