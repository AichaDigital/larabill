> **⚠️ SUPERSEDED (2026-05-09)** — premisa rota detectada en la misma sesión: larabill está en `dev-main` pre-v1.0 sin instalaciones en producción ni datos a preservar. El paquete NO promete contrato de upgrade en esta fase; lo que sí promete es **fresh install agnóstico** sobre `int`/`uuid`/`ulid`. Ese contrato pasa a ser la prioridad y se documenta y cubre con tests reales en:
>
> 👉 **`docs/2026-05-09-fresh-install-agnostic-mysql.md`**
>
> Acciones tomadas como parte del reframe:
> - Eliminada `database/migrations/2026_05_08_000001_repair_article_customer_id_columns.php` y su `.stub`.
> - Retirada la entrada `'032'` de `$migrationOrder` en `LarabillInstallCommand`.
> - Añadidos `tests/Integration/Mysql/MysqlIntegrationTestCase.php` y `tests/Integration/Mysql/FreshInstallUserIdTypeTest.php` cubriendo los 3 tipos.
> - Añadido job CI `mysql-integration` (PHP 8.3 + L12 + MySQL 8).
>
> Este documento se conserva como rastro de la decisión equivocada corregida — leído como histórico, no como guía operativa.

---

# Blocker estructural — Falta cobertura de upgrade `customer_id bigint → uuid` con índices compuestos

**Fecha:** 2026-05-09
**Autor:** Abdelkarim Mateos
**Versión afectada:** `dev-main` ref `89720420` (bump del 2026-05-09)
**Migración bajo análisis:** `database/migrations/2026_05_08_000001_repair_article_customer_id_columns.php`
**Estado:** ~~abierto~~ **SUPERSEDED 2026-05-09** — sustituido por contrato fresh-install (ver banner arriba)
**Severidad:** estructural (no rompe esta release, pero el contrato del paquete no está demostrado)

## Contexto

En la app cliente `larafactu/clientes`, tras actualizar `aichadigital/larabill` a `89720420`, queda pendiente la migración `2026_05_08_000001_repair_article_customer_id_columns`. Esta migración debe convertir:

- `article_overrides.customer_id`
- `article_service_status.customer_id`

al tipo configurado en el proyecto consumidor (`int`, `uuid` o `ulid`) cuando vienen instaladas previamente como `bigint unsigned` por una versión anterior del paquete.

## Estado real previo en larafactu (2026-05-09)

- Proyecto declara `LARABILL_USER_ID_TYPE=uuid`.
- `users.id` es `char`.
- Ambas columnas `customer_id` existen como `bigint unsigned NOT NULL`.
- Ambas tablas tienen 0 filas.
- **No hay FKs** sobre `customer_id` (verificado en `information_schema.KEY_COLUMN_USAGE` con `REFERENCED_TABLE_NAME` nulo).
- **Sí hay índices UNIQUE compuestos** que incluyen `customer_id`: `customer_article_override_unique` y `customer_article_instance_unique`.

## Auditoría de la migración contra contrato de upgrade

| # | Item del contrato | Cumple | Evidencia |
|---|---|---|---|
| 1 | `Schema::hasTable` antes de actuar | sí | línea 34 |
| 2 | `Schema::hasColumn` antes de actuar | sí | línea 34 |
| 3 | Detecta tipo real (no asume) | sí | línea 85, `Schema::getColumnType` + `in_array(['integer','bigint'])` |
| 4 | Valida datos existentes antes de convertir | sí | líneas 88-104, cursor + UUID/ULID validation + `RuntimeException` claro |
| 5 | Inspecciona índices/FKs antes del `ALTER` | **no** | delega en `->change()` de Doctrine |
| 6 | Errores de conversión explícitos | sí | líneas 99-101 |
| 7 | Soporte motor-aware (pgsql vs mysql/sqlite) | sí | líneas 64-72, pgsql usa `using {col}::text::uuid` nativo |

## Veredicto

La migración es **razonablemente defensiva**, pero el contrato no está demostrado:

- El gap del item 5 (inspección de índices/FKs) es teóricamente cubierto por Doctrine al reconstruir índices durante `->change()`. **No está demostrado por test.**
- No existe test de upgrade que reproduzca `bigint unsigned → uuid` con índices compuestos preexistentes.
- Los tests actuales del paquete (`tests/Unit/Models/ArticleServiceStatusTest.php:37`) cubren **fresh install**, no upgrade.

**No se marca la migración como rota.** Se marca el paquete como **sin prueba de upgrade para un contrato crítico**.

## Acceptance criteria

Cubrir en `tests/` del paquete los siguientes escenarios. Cada uno con motor MySQL como mínimo (PostgreSQL deseable, dado el path nativo `using ::text::uuid`):

1. **Fresh install** con cada tipo de ID configurado (`int`, `uuid`, `ulid`). El estado final de `article_overrides.customer_id` y `article_service_status.customer_id` debe coincidir con el tipo declarado.
2. **Upgrade sin filas:** preinstalar tablas con `customer_id bigint unsigned NOT NULL` + índices compuestos UNIQUE existentes; cambiar `LARABILL_USER_ID_TYPE` a `uuid`; ejecutar la migración; assert que el tipo cambió, los índices compuestos siguen siendo UNIQUE y siguen incluyendo `customer_id`, y la tabla sigue siendo writable con UUID válidos.
3. **Upgrade con filas UUID válidas:** mismo setup que (2), insertar filas con valores UUID v4/v7 válidos en formato string; ejecutar la migración; assert que las filas sobreviven y siguen siendo legibles.
4. **Upgrade con valores inválidos:** mismo setup que (2), insertar filas con valores que NO son UUID válidos; ejecutar la migración; assert que falla con `RuntimeException` **antes** del `ALTER` (sin tocar el schema), y el mensaje incluye nombre de tabla, valor ofensor y tipo target.
5. Si alguno de los tests anteriores falla por tema de índices/FKs (item 5 del contrato), **endurecer la migración** para inspeccionar y, si procede, dropear/recrear índices o FKs explícitamente como parte del `up()` y `down()`.

## Notas operativas

- Sesión dedicada en este repo (`~/development/packages/aichadigital/larabill/`), no parchear desde el cliente.
- Después de cubrir los criterios, bumpar versión y la app cliente consumirá vía `composer update aichadigital/larabill`.
- Mientras tanto, la app cliente `larafactu/clientes` resuelve C.1 con `migrate:fresh --seed` en local (datos descartables, fase develop). Esto **no demuestra** el contrato; solo evita el riesgo en una BD vacía.

## Referencias

- Bump que introdujo la migración: commit `c6969f8 fix: make article customer ids agnostic (#17)`.
- Doc de fix asociado: `docs/2026-05-08-user-id-agnostic-repair.md`.
- Migración auditada: `database/migrations/2026_05_08_000001_repair_article_customer_id_columns.php`.
- Helper: `src/Support/MigrationHelper.php` (`getUserIdType()`).
