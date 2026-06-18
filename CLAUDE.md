# CLAUDE.md — larabill

Entry point para Claude Code en este paquete. Hereda del paraguas `~/development/packages/aichadigital/CLAUDE.md` (no duplicar reglas de bot de dependencias, webhooks Packagist, branch protection, lecciones aprendidas — están allí).

## Qué es este paquete

Larabill es el **núcleo de facturación** del ecosistema Larafactu (AichaDigital):

- Facturas inmutables con UUID v7 (string char 36, no binario — ADR-006 consolida el estándar)
- Cálculo fiscal (España, UE, mundial) y verificación VAT vía `lararoi`
- Datos fiscales temporales (emisor `CompanyFiscalConfig` + receptor `UserTaxProfile`)
- Pricing por frecuencia (`Article` + `ArticlePrice`, ADR-004)
- Cumplimiento VeriFACTU (vía `lara-verifactu`)

**Stack:** PHP 8.3+, Laravel 12 ó 13 (`^12.0||^13.0`), Pest. Framework-agnóstico (sin acoplamiento a Filament). License AGPL-3.0-or-later.

## Lectura obligatoria antes de tocar código

1. **`.claude/CRITICAL_RULES.md`** — inyectado por SessionStart hook, son las reglas duras
2. **`.claude/project.md`** — contexto exhaustivo de arquitectura, modelos, ADRs
3. **`SCHEMA_REQUIREMENTS.md`** — qué exige al `users` de la app consumidora
4. **`CONTRIBUTING.md`** — patrón de migraciones (.php + .stub + `$migrationOrder`)
5. **`docs/ADR-*.md`** — decisiones arquitectónicas vigentes (ficheros reales): 003 (unificación user/customer), 004 (precios por frecuencia), 006 (UUID-first), 007 (`.php.stub` derivado del `.php`). Las decisiones previas 001/002/005 quedaron consolidadas en estas.
6. **`docs/2026-05-09-fresh-install-agnostic-mysql.md`** — histórico: describía un contrato agnóstico `int`/`uuid`/`ulid` **superado por ADR-006**. El contrato vigente es **UUID-first** (ver ADR-006), demostrado en `tests/Integration/Mysql/`.
7. **`docs/2026-05-09-blocker-upgrade-test-customer-id-bigint-to-uuid.md`** — SUPERSEDED, conservado como rastro histórico de una premisa rota (no había producción, dev-main no promete upgrade).

## Reglas inviolables (resumen — detalle en CRITICAL_RULES.md)

- **Migraciones:** toda tabla del paquete tiene `.php` (timestamped, auto-load en dev) **Y** `.php.stub` (publicado por `larabill:install`). Solo 2 stubs son consumer-only (modifican el `users` del consumidor): `add_user_relationships_to_users_table.php.stub` y `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`.
- **El `.php.stub` es artefacto derivado del `.php` (ADR-007):** byte-idéntico, regenerado con `bin/sync-migration-stubs`. NUNCA editar un `.stub` a mano — editar el `.php`, correr el script, commitear ambos.
- **`$migrationOrder`** en `LarabillInstallCommand` debe coincidir 1:1 con los stubs reales (32 entradas hoy). Un test de consistencia (`tests/Unit/Console/MigrationOrderConsistencyTest.php`) valida 1:1 + byte-identidad `.php`↔`.stub` en CI.
- **FK a users:** SIEMPRE `MigrationHelper::userIdColumn($table, 'col')`. Nunca `$table->foreignId()` directo. Emite UUID v7 char(36) exclusivamente (ADR-006); `int`/`ulid`/`larabill.user_id_type` retirados en v0.8.0.
- **Dinero:** SIEMPRE Base-100 entero (`12,34 € → 1234`) con cast `Base100Int` de `lara100`. NUNCA float/decimal.
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
- Mantener migraciones `repair_*` que cambian tipo de columna sin tests que demuestren su contrato → comunican una promesa de upgrade que dev-main NO asume. Si aparece una en una PR, justificarla por escrito o eliminarla.
- Asumir que `composer test` (suite SQLite) demuestra el contrato real → SQLite no preserva índices compuestos ni longitudes char(36) reales. El contrato se demuestra en `tests/Integration/Mysql/` contra MySQL 8.
- Hardcodear `'user_id' => 1` (o cualquier int) en fixtures de test → usar las constantes `TestCase::USER_UUID_1/2/3`.

## Estado actual (2026-06-18)

- Tag actual: **v0.11.1** — re-tag de higiene desde `main`. `v0.11.0` se taggeó sobre un commit previo al bump de CI (#33) y quedó fuera de la historia de `main`; `v0.11.1` realinea el release. El payload distribuido por Composer es idéntico a v0.11.0 (el delta era solo SHA-pins de actions de CI, que no entran en el dist).
- **Foco reciente: integración VeriFACTU/AEAT** vía `lara-verifactu` (Linear AID-129/135/138):
  - **v0.9.2-0.9.4** — fixes de cálculo fiscal: `TaxCalculationService::calculateForInvoiceItem()` era un stub que devolvía 0 IVA; clasificación F1/F2 ahora recipient-driven (AEAT rechaza F2 con bloque `Destinatarios`); `taxes_applied` como entero base-100.
  - **v0.10.0** — facturas rectificativas: constraint `lara-verifactu ^0.10`, `ClaveTipoRectificativaType 'I'`, bloque `FacturasRectificadas`.
  - **v0.11.0** — verificación AEAT asíncrona sincronizada de vuelta a la factura (nº de registro, QR, hash, timestamp); QR VeriFACTU en PDF (SVG/PNG); las facturas inmutables solo admiten actualizar campos de verificación fiscal post-emisión.
- Bot de dependencias: **Renovate self-hosted** (`renovate.tabratino.com`). Ver paraguas para el protocolo de transición.
- **Contrato vigente:** UUID-first total (ADR-006). `users.id` debe ser UUID v7 char(36); el `larabill:install` aborta con mensaje accionable si no lo es. La superficie agnóstica (`int`/`ulid`, `larabill.user_id_type`, `LARABILL_USER_ID_TYPE`, flag CLI `--user-id-type=`) fue retirada en v0.8.0.
- **Migraciones (ADR-007):** `.php` = fuente de verdad, `.php.stub` = artefacto derivado byte-exacto (`bin/sync-migration-stubs`); `$migrationOrder` 1:1 con los stubs, validado por `MigrationOrderConsistencyTest`. Constantes de fixture: `tests/TestCase::USER_UUID_1/2/3`.
- **Tests:** SQLite (`composer test`) + MySQL Integration (`tests/Integration/Mysql/`). CI matriz PHP 8.3+8.4 × L12+L13 + job MySQL 8. PHP 8.4 local: bug "table already exists" → usar PHP 8.3 o confiar en CI.
- **Onboarding consumidor:** `docs/setup-uuid.md` es la guía canónica.
