# CLAUDE.md — larabill

Entry point para Claude Code en este paquete. Hereda del paraguas `~/development/packages/aichadigital/CLAUDE.md` (no duplicar reglas de bot de dependencias, webhooks Packagist, branch protection, lecciones aprendidas — están allí).

## Qué es este paquete

Larabill es el **núcleo de facturación** del ecosistema Larafactu (AichaDigital):

- Facturas inmutables con UUID v7 (string char 36, no binario — ver ADR-002)
- Cálculo fiscal (España, UE, mundial) y verificación VAT vía `lararoi`
- Datos fiscales temporales (emisor `CompanyFiscalConfig` + receptor `UserTaxProfile`)
- Pricing por frecuencia (`Article` + `ArticlePrice`, ADR-004/005)
- Recursos Filament 4
- Cumplimiento VeriFACTU (vía `lara-verifactu`)

**Stack:** PHP 8.3+, Laravel 12 ó 13 (`^12.0||^13.0`), Filament 4, Pest. License AGPL-3.0-or-later.

## Lectura obligatoria antes de tocar código

1. **`.claude/CRITICAL_RULES.md`** — inyectado por SessionStart hook, son las reglas duras
2. **`.claude/project.md`** — contexto exhaustivo de arquitectura, modelos, ADRs
3. **`SCHEMA_REQUIREMENTS.md`** — qué exige al `users` de la app consumidora
4. **`CONTRIBUTING.md`** — patrón de migraciones (.php + .stub + `$migrationOrder`)
5. **`docs/ADR-*.md`** — decisiones arquitectónicas (001 fiscal, 002 UUID, 003 user unification, 004 owner_user_id, 005 article pricing)
6. **`docs/2026-05-09-fresh-install-agnostic-mysql.md`** — contrato vigente: fresh install agnóstico (`int`/`uuid`/`ulid`) sobre MySQL real, demostrado por `tests/Integration/Mysql/`. Reemplaza al bloqueador previo de upgrade.
7. **`docs/2026-05-09-blocker-upgrade-test-customer-id-bigint-to-uuid.md`** — SUPERSEDED, conservado como rastro histórico de una premisa rota (no había producción, dev-main no promete upgrade).

## Reglas inviolables (resumen — detalle en CRITICAL_RULES.md)

- **Migraciones:** toda tabla del paquete tiene `.php` (timestamped, auto-load en dev) **Y** `.php.stub` (publicado por `larabill:install`). Solo 2 stubs son consumer-only (modifican el `users` del consumidor): `add_user_relationships_to_users_table.php.stub` y `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`.
- **`$migrationOrder`** en `LarabillInstallCommand` debe coincidir 1:1 con los stubs reales (31 entradas hoy). Editar ambos a la vez.
- **FK a users:** SIEMPRE `MigrationHelper::userIdColumn($table, 'col')`. Nunca `$table->foreignId()` directo. Soporta `uuid` (default) / `int` / `ulid` vía `larabill.user_id_type`.
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
- Usar `hasMigration()` de Spatie → exige `vendor:publish` manual, rompe agnosticismo
- Asumir `Auth::user()->id` como int → rompe instalaciones uuid/ulid
- Reintroducir `Customer`/`CustomerFiscalData` → deprecados desde ADR-003 (usar `User` con `parent_user_id` + `UserTaxProfile`)
- Reintroducir UUID binario → eliminado en ADR-002 por incompatibilidad con Filament 4
- Mantener migraciones `repair_*` que cambian tipo de columna sin tests que demuestren su contrato → comunican una promesa de upgrade que dev-main NO asume. Si aparece una en una PR, justificarla por escrito o eliminarla.
- Asumir que `composer test` (suite SQLite) demuestra contratos de schema agnóstico → SQLite no preserva índices compuestos a través de `Schema::change()`. El contrato real se demuestra en `tests/Integration/Mysql/` contra MySQL 8.

## Estado actual (2026-05-09)

- Tag vigente (post-merge): **v0.7.4** — fresh-install agnostic contract sobre MySQL real cubierto por tests. Tag previo: v0.7.3.
- Bot de dependencias: migrado a **Renovate self-hosted** (`renovate.tabratino.com`). Ver paraguas para el protocolo de transición.
- **Contrato vigente del paquete (post-reframe 2026-05-09):** fresh install agnóstico sobre MySQL real para `int`/`uuid`/`ulid`. Cubierto por `tests/Integration/Mysql/MysqlIntegrationTestCase.php` + `tests/Integration/Mysql/FreshInstallUserIdTypeTest.php` (un único `it()` con `->with(['int','uuid','ulid'])`). Job CI nuevo: `mysql-integration` (PHP 8.3 + L12 + MySQL 8 service). Eliminada `database/migrations/2026_05_08_000001_repair_article_customer_id_columns.php` y su `.stub` — comunicaba una promesa de upgrade que dev-main NO asume. Para correr la suite MySQL local: ver `docs/2026-05-09-fresh-install-agnostic-mysql.md` (env vars `LARABILL_TEST_MYSQL_*` + Docker).
- **Suite SQLite** (`composer test`): 959 passed (2765 assertions), intacta tras el reframe. Los 3 tests MySQL se reportan como `skipped` cuando faltan env vars — no rompen el flujo local.
