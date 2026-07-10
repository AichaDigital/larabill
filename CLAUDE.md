# CLAUDE.md — larabill

Entry point para Claude Code en este paquete. Hereda del paraguas `~/development/packages/aichadigital/CLAUDE.md` (no duplicar reglas de bot de dependencias, webhooks Packagist, branch protection, lecciones aprendidas — están allí).

## Qué es este paquete

Larabill es el **núcleo de facturación** del ecosistema Larafactu (AichaDigital):

- Facturas inmutables con UUID v7 (string char 36, no binario — ADR-006 consolida el estándar)
- Cálculo fiscal (España, UE, mundial); la verificación VAT/NIF intracomunitaria la posee `lararoi` — larabill la consume vía un puente fino opcional (`Actions\VerifyVatNumber`, delega en `VatVerificationServiceInterface`), NO acoplado a la emisión (reverse-charge decide por `is_roi_taxed`) (AID-309)
- Datos fiscales temporales (emisor `CompanyFiscalConfig` + receptor `UserTaxProfile`)
- Pricing por frecuencia (`Article` + `ArticlePrice`, ADR-004)
- Cumplimiento VeriFACTU (vía `lara-verifactu`)

**Stack:** PHP 8.3+, Laravel 12 ó 13 (`^12.0||^13.0`), Pest. Framework-agnóstico (sin acoplamiento a Filament). License AGPL-3.0-or-later.

## Lectura obligatoria antes de tocar código

1. **`.claude/CRITICAL_RULES.md`** — inyectado por SessionStart hook, son las reglas duras
2. **`.claude/project.md`** — contexto exhaustivo de arquitectura, modelos, ADRs
3. **`SCHEMA_REQUIREMENTS.md`** — qué exige al `users` de la app consumidora
4. **`CONTRIBUTING.md`** — patrón de migraciones (.php + .stub + `$migrationOrder`)
5. **`docs/ADR-*.md`** — decisiones arquitectónicas vigentes (ficheros reales): 003 (unificación user/customer), 004 (precios por frecuencia), 006 (UUID-first), 007 (`.php.stub` derivado del `.php`), 008 (puerto de retención legal — `Invoice`/`UserTaxProfile` implementan `LegallyRetainable`; larabill NO ejecuta privacidad operativa), 009 (tipo de dinero `FixedDecimal`), 010 (mantener el publishing de migraciones; rechazar «0 stubs» — cierra AID-302). Las decisiones previas 001/002/005 quedaron consolidadas en estas.
6. **`docs/2026-05-09-fresh-install-agnostic-mysql.md`** — histórico: describía un contrato agnóstico `int`/`uuid`/`ulid` **superado por ADR-006**. El contrato vigente es **UUID-first** (ver ADR-006), demostrado en `tests/Integration/Mysql/`.
7. **`docs/2026-05-09-blocker-upgrade-test-customer-id-bigint-to-uuid.md`** — SUPERSEDED, conservado como rastro histórico de una premisa rota (no había producción, dev-main no promete upgrade).

## Reglas inviolables (resumen — detalle en CRITICAL_RULES.md)

- **Migraciones:** toda tabla del paquete tiene `.php` (timestamped, auto-load en dev) **Y** `.php.stub` (publicado por `larabill:install`). Solo 2 stubs son consumer-only (modifican el `users` del consumidor): `add_user_relationships_to_users_table.php.stub` y `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`.
- **El `.php.stub` es artefacto derivado del `.php` (ADR-007):** byte-idéntico, regenerado con `bin/sync-migration-stubs`. NUNCA editar un `.stub` a mano — editar el `.php`, correr el script, commitear ambos.
- **`$migrationOrder`** en `LarabillInstallCommand` debe coincidir 1:1 con los stubs reales (32 entradas hoy). Un test de consistencia (`tests/Unit/Console/MigrationOrderConsistencyTest.php`) valida 1:1 + byte-identidad `.php`↔`.stub` en CI.
- **FK a users:** SIEMPRE `MigrationHelper::userIdColumn($table, 'col')`. Nunca `$table->foreignId()` directo. Emite UUID v7 char(36) exclusivamente (ADR-006); `int`/`ulid`/`larabill.user_id_type` retirados en v0.8.0.
- **Dinero:** SIEMPRE Base-100 entero (`12,34 € → 1234`) con cast `FixedDecimalCast:2` de `lara100` (value object `FixedDecimal`, AID-237). NUNCA float/decimal. La columna sigue `integer`; el cast materializa el value object.
- **Numeración fiscal: SIEMPRE `InvoiceNumberingService::generateNumber()`** (dueño único, AID-390). `fiscal_number`, `prefix`, `series_number` y `fiscal_year` salen del MISMO `InvoiceNumber`. El scope del contador es el EMISOR (null → sentinel `GLOBAL_SCOPE`), nunca el cliente facturado (`billable_user_id`, ADR-003).
- **Facturas emitidas son inmutables.** No editar nada con status ≠ `draft`.
- **Tests agnósticos:** usar `config('larabill.user_model')` para aserciones de tipo, no hardcodear `User::class`.

## Comandos

Ejecutar dentro de `larabill/`:

```bash
composer prepare              # testbench package:discover (post-install/update)
composer test                 # pest
composer test-parallel        # pest --parallel
composer test-coverage        # pest --coverage
composer pint                 # formato
composer phpstan              # análisis estático (memory-limit=1G)
composer phpinsights          # insights
composer quality              # pint + phpstan + test-coverage
composer precommit            # pint + phpstan + test-parallel
```

Filtros típicos: `vendor/bin/pest --filter='nombre del test'`, `vendor/bin/pest tests/Unit/Foo/BarTest.php`.

## Nota sobre PHP 8.4 local

Hay un bug conocido "table already exists" con SQLite in-memory en PHP 8.4 local. Usar PHP 8.3 localmente o confiar en CI (matriz PHP 8.3+8.4 × L12+L13).

## Anti-patterns frecuentes

- Crear `.php.stub` sin su `.php` → no se auto-carga en tests/dev
- Añadir entrada a `$migrationOrder` sin el stub correspondiente → install falla
- Usar `hasMigration()` de Spatie → exige `vendor:publish` manual, rompe el flujo del install command
- Asumir `Auth::user()->id` como int → larabill es UUID-first (ADR-006); romper esa asunción en helpers, DTOs, casts o tests
- Reintroducir `larabill.user_id_type`, `LARABILL_USER_ID_TYPE`, soporte `int`/`ulid`, o el flag CLI `--user-id-type=` → eliminados en v0.8.0 (ADR-006). Solo se reabre con caso de cliente concreto (ver ADR-006 §"Criterios de reapertura").
- Reintroducir `Customer`/`CustomerFiscalData` → deprecados desde ADR-003 (usar `User` con `parent_user_id` + `UserTaxProfile`)
- Reintroducir UUID binario → descartado; el estándar es UUID v7 char(36) (ADR-006). Incompatibilidad histórica con Filament, dependencia ya eliminada del paquete.
- Una migración que transforma datos SIN test de su camino de upgrade → desde AID-398 la promesa de upgrade SÍ se asume (versión estable = respeto por datos): sembrar el estado legacy → correr la migración → verificar (patrón: test del backfill AID-390). La regla histórica «dev-main no promete upgrades» quedó obsoleta con la 1.0.
- Reintroducir un generador de numeración propio en un servicio (`rand()`, contador en cache, MAX+1) → la numeración fiscal tiene dueño único `InvoiceNumberingService` (AID-390); delegar siempre
- Sembrar `invoice_series_control` con `user_id => null` en fixtures → el scope global es el sentinel `InvoiceSeriesControl::GLOBAL_SCOPE`; NULL quedó retirado (no colisiona en unique)
- Asumir que `composer test` (suite SQLite) demuestra el contrato real → SQLite no preserva índices compuestos ni longitudes char(36) reales. El contrato se demuestra en `tests/Integration/Mysql/` contra MySQL 8.
- Hardcodear `'user_id' => 1` (o cualquier int) en fixtures de test → usar las constantes `TestCase::USER_UUID_1/2/3`.

## Estado actual (2026-07-10)

- Tag actual: **v4.1.0** (Packagist); `main` limpio; `[Unreleased]` lleva la entrada docs de AID-398. **PHPStan level 8** (máximo). Cadena reciente:
  - **v4.1.0** (2026-07-10) — **AID-390**: numeración fiscal endurecida y consolidada (ver bullet propio). Precedida por la auditoría de antipatrones del 2026-07-10 (2 Critical: `rand()` y contador en cache).
  - **v4.0.1/v4.0.2** (2026-07-04) — **AID-324/325**: `UPGRADE-4.0.md` empaquetado en el dist + preflight double-proof de lararoi v1.0.4 para la tabla legacy `vat_verifications`.
  - **v4.0.0** (2026-07-03) — **AID-309, BREAKING**: retirada la capa VAT/ROI propia; el dominio lo posee `lararoi ^1.0` vía puente fino (`Actions\VerifyVatNumber`).
  - **v3.x / v2.x / v1.0 (jun-2026, condensado):** v3.1.x = PHPStan L5→L8 (AID-277/279/281) + grouped payments (AID-30/272/273) + `AeatInvoiceValidator` muerto fuera (AID-280); v2/v3 cierran «nada decimal/float en BD» (AID-240/242/246); v1.0.0 = migración completa a `FixedDecimal` de lara100 v2 (AID-237 — query builder devuelve int, atributo Eloquent devuelve `FixedDecimal`).
- **Numeración fiscal: dueño único `InvoiceNumberingService` (AID-390, v4.1.0).** Scope de EMISOR con sentinel `InvoiceSeriesControl::GLOBAL_SCOPE` (nil-UUID; NULL retirado — no colisiona en índices unique de MySQL/SQLite), creación de primer uso idempotente (catch de unique race + relectura con lock; `DB::transaction(..., 3)` ante deadlocks de gap-lock), semilla de continuidad desde `MAX(series_number)` por (serie, ejercicio). `InvoiceService`, `BillingService` y `RecurringBillingService` delegan — los generadores legacy (`rand()`, contador en cache, MAX+1) NO existen ya. `BillingService` entero está `@deprecated` (retirada en la major de AID-307). Test fork-based en `tests/Concurrency/InvoiceNumberingConcurrencyTest.php` (gate `RUN_CONCURRENCY_IT=1` + MySQL), con sensibilidad demostrada.
- **Política de upgrade (AID-398, «versión estable = respeto por datos»):** todo cambio de esquema/semántica de datos lleva su programa de actualización en el MISMO PR — regla dura en el CLAUDE.md del paraguas. El README documenta el camino real: `composer update` + re-run idempotente de `larabill:install` (publica solo migraciones nuevas por nombre; `vendor:publish` sin `--force` no machaca el config del consumidor) + `migrate`. NUNCA recomendar `migrate:fresh` sobre datos reales.
- **Pendiente mayor: AID-307** (serie fiscal real configurable: separar tipo de serie, uniqueness de `invoices` por serie real, `VerifactuAdapter` enviando la serie real, y las retiradas breaking de `BillingService` + shims `user_id` de `UserTaxProfile`). Bloquea AID-289 del consumidor `clientes`. Limpieza post-auditoría restante: AID-391.
- **Deuda "nada decimal en BD": CERRADA** (AID-240/242/246 — AID-286 documenta el cierre). Cero columnas `decimal`/`float` en migraciones; todos los modelos monetarios/tasa usan `FixedDecimalCast:2`. Único superviviente con cast `integer` **por decisión deliberada**: `tax_rates.rate` (núcleo fiscal vía `VatCalculationStrategy`, base-100 del %, `/10000` → fracción; mayor riesgo, fuera de scope salvo caso concreto).
- **Contrato de retención legal (AID-221):** `Invoice` y `UserTaxProfile` implementan `AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable` (único método `retainedUntil(): ?DateTimeInterface`). El contrato lo **posee el core** (`lara-privacy-core`); larabill es un adapter. El gate `isUnderRetention` lo resuelve `CheckLegalHold` del core — **sin** `RetentionBasis`/`legalHold()`/`isUnderRetention()` en los modelos. `Invoice`: fiscal → fin de ejercicio de `invoice_date` + `larabill.retention.fiscal_years` (env `LARABILL_RETENTION_FISCAL_YEARS`, default 6); PROFORMA/sin fecha → null. `UserTaxProfile`: MAX sobre sus facturas (computa aun soft-deleted; huérfano → null). Calcular, no materializar.
- **Dependencias notables:** `lara-verifactu ^1.0` (estable), `lara-privacy-core ^1.0`, `lara100 ^2.0` (FixedDecimal — AID-237), `lararoi ^1.0.4` (puente fino de verificación VAT/NIF — AID-309; el `.4` garantiza el preflight double-proof de AID-325), `dompdf/dompdf ^3.1`.
- Bot de dependencias: **Renovate self-hosted** (`renovate.tabratino.com`). Ver paraguas para el protocolo de transición.
- **Contrato vigente:** UUID-first total (ADR-006). `users.id` debe ser UUID v7 char(36); el `larabill:install` aborta con mensaje accionable si no lo es. La superficie agnóstica (`int`/`ulid`, `larabill.user_id_type`, `LARABILL_USER_ID_TYPE`, flag CLI `--user-id-type=`) fue retirada en v0.8.0.
- **Migraciones (ADR-007 + ADR-010):** `.php` = fuente de verdad, `.php.stub` = artefacto derivado byte-exacto (`bin/sync-migration-stubs`); `$migrationOrder` 1:1 con los stubs, validado por `MigrationOrderConsistencyTest`. Constantes de fixture: `tests/TestCase::USER_UUID_1/2/3`.
- **Publishing de stubs: se MANTIENE (AID-302 → ADR-010, 2026-07-02).** El refactor a «0 stubs» (esquema package-managed, como laratickets AID-290) queda **RECHAZADO/aplazado**: sería breaking para consumidores ya instalados sin beneficio correctivo (la deuda técnica ya estaba cerrada). Reconsiderar solo en una versión mayor breaking con plan de migración explícito — criterios de reapertura en `docs/ADR-010-keep-migration-stub-publishing.md`. AID-302 describía el estado v0.8.3 (6 divergencias) ya superado por ADR-007.
- **Deuda de divergencia `.php`↔`.stub`: ENTERAMENTE SALDADA.** Los 3 follow-ups de ADR-007 cerrados: (1) test de instalación productiva en MySQL (`tests/Integration/InstallMysql/InstallCommandSchemaTest.php`, AID-287) valida `larabill:install → stubs → migrate` con FKs íntegras; (2) auditoría del resto del stack (barrido umbrella AID-298…303); (3) el histórico fillable huérfano `tax_group_id` en `InvoiceItem` verificado **inexistente** en v3.1.3 — no se persiste en el item por diseño (snapshot inmutable en `taxes_applied`/`total_tax_amount`; `tax_group_id` es columna solo en `articles` + input de `TaxCalculationService`).
- **Tests:** SQLite (`composer test`) + MySQL Integration (`tests/Integration/Mysql/`). CI matriz PHP 8.3+8.4 × L12+L13 + job MySQL 8. PHP 8.4 local: bug "table already exists" → usar PHP 8.3 o confiar en CI.
- **Onboarding consumidor:** `docs/setup-uuid.md` es la guía canónica.
