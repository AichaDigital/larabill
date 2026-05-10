# Contrato fresh install agnóstico sobre MySQL

> **⚠️ SUPERSEDED (2026-05-10)**: este documento describe el contrato `int|uuid|ulid` que estuvo vigente entre v0.7.4 y v0.7.x. A partir de **v0.8.0** larabill adopta UUID-first como contrato único — ver [`ADR-006`](ADR-006-uuid-first-no-agnostic.md) y [`setup-uuid.md`](setup-uuid.md). Se conserva como rastro histórico del reframe del 2026-05-09.

**Fecha:** 2026-05-09
**Autor:** Abdelkarim Mateos
**Versión afectada:** `dev-main` v0.7.4 (estado intermedio entre el blocker SUPERSEDED y la decisión UUID-first de v0.8.0)
**Estado:** SUPERSEDED por ADR-006
**Sustituye a:** `2026-05-09-blocker-upgrade-test-customer-id-bigint-to-uuid.md` (SUPERSEDED)
**Sustituido por:** `ADR-006-uuid-first-no-agnostic.md` (2026-05-09 decisión, 2026-05-10 implementación)

## Premisa correcta

Larabill `dev-main` no tiene instalaciones en producción ni bases de datos no descartables. El paquete está en fase pre-v1.0 (ver banner del README). En consecuencia:

- **NO** prometemos un contrato de upgrade entre versiones `dev-main`. Para consumidores internos: `migrate:fresh` o recreación de tablas. Esto se documenta en CHANGELOG y CONTRIBUTING.
- **SÍ** prometemos que el paquete instala limpio y agnóstico sobre cada uno de los tres tipos de id soportados (`int`, `uuid`, `ulid`) contra MySQL real. Ese es el contrato que importa para un paquete que se publica como dependencia y aspira a v1.0 estable.

Cualquier coste en mantener migraciones de reparación (`repair_*`) sin tests reales que las demuestren introduce más riesgo que beneficio: comunica una promesa de upgrade que no queremos asumir, y la deuda de testearlas crecería con cada bump. Por eso la migración `2026_05_08_000001_repair_article_customer_id_columns.php` fue eliminada en este mismo reframe.

## Contrato demostrado por tests

Para cada `larabill.user_id_type ∈ {int, uuid, ulid}`:

1. **Las migraciones del paquete corren limpio contra MySQL 8** vía `artisan migrate`, partiendo de un `users` consumidor cuyo `id` ya es del tipo objetivo. Sin advertencias, sin fallos de FK.
2. **Las columnas agnósticas reflejan el tipo configurado** en información representativa del schema:
   - `article_overrides.customer_id` y `article_service_status.customer_id` (`MigrationHelper::agnosticIdColumn`)
   - `invoices.user_id`, `user_tax_profiles.owner_user_id`, `roi_queries.user_id` (`MigrationHelper::userIdColumn`)
   - Verificación de `DATA_TYPE` y `CHARACTER_MAXIMUM_LENGTH` vía `information_schema.COLUMNS`.
3. **Los índices UNIQUE compuestos** (`customer_article_override_unique` con `[customer_id, article_id, valid_from]`; `customer_article_instance_unique` con `[customer_id, article_id, instance_identifier]`) existen y mantienen `customer_id` en posición 0. Verificado vía `information_schema.STATISTICS`.
4. **Smoke de escritura y enforcement**: insert válido con un valor del tipo correcto pasa; un duplicado del mismo composite key falla con `QueryException` (SQLSTATE 23000). Demuestra que tras instalar el paquete, las restricciones de unicidad están activas a nivel motor.

Los tres datasets se ejecutan a través de un único `it()` con `->with(['int', 'uuid', 'ulid'])` en `tests/Integration/Mysql/FreshInstallUserIdTypeTest.php`.

## Infraestructura de tests

`tests/Integration/Mysql/MysqlIntegrationTestCase.php`:

- Extiende `Orchestra\Testbench\TestCase` directamente (NO `tests/TestCase.php`, que fuerza SQLite y siembra usuarios numéricos — incompatible con datasets multi-tipo).
- **Opt-in por env**: requiere `LARABILL_TEST_MYSQL_HOST`, `LARABILL_TEST_MYSQL_PORT`, `LARABILL_TEST_MYSQL_DATABASE`, `LARABILL_TEST_MYSQL_USERNAME`, `LARABILL_TEST_MYSQL_PASSWORD`. Si falta cualquiera → `markTestSkipped` con mensaje explícito antes de `parent::setUp()`.
- **`bootstrapForUserIdType(string $idType)`**: setea `larabill.user_id_type`, crea el `users` con el `id` del tipo correcto, y ejecuta `artisan migrate` contra MySQL.
- **Limpieza robusta**: `dropAllTables()` usa `SET FOREIGN_KEY_CHECKS=0` + barrido de `information_schema.TABLES` + `SET FOREIGN_KEY_CHECKS=1`. Independiente del orden de FKs.
- **Helpers de inspección**: `getMysqlColumnType`, `getMysqlColumnLength`, `getUniqueIndexColumns` — todos vía `information_schema`.

`tests/Pest.php` bindea explícitamente `MysqlIntegrationTestCase` al subdirectorio `Integration/Mysql/` ANTES del binding global `TestCase + RefreshDatabase`, que se restringe a paths concretos para no recapturar `Integration/Mysql/`.

## Ejecución local

```bash
# Levantar MySQL 8 en Docker (puerto 33106 para no chocar con un MySQL local en 3306).
docker run -d --rm --name larabill-mysql-test \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=larabill_test \
  -p 33106:3306 \
  mysql:8

# Esperar a que esté listo.
until docker exec larabill-mysql-test mysqladmin ping -h 127.0.0.1 -uroot -proot --silent; do sleep 2; done

# Ejecutar la suite de integración.
LARABILL_TEST_MYSQL_HOST=127.0.0.1 \
LARABILL_TEST_MYSQL_PORT=33106 \
LARABILL_TEST_MYSQL_DATABASE=larabill_test \
LARABILL_TEST_MYSQL_USERNAME=root \
LARABILL_TEST_MYSQL_PASSWORD=root \
vendor/bin/pest tests/Integration/Mysql/

# Limpiar.
docker stop larabill-mysql-test
```

Sin estas env vars, la suite SQLite del paquete (`composer test`) ejecuta los tests MySQL como `skipped` con mensaje informativo — no se rompe el flujo de desarrollo.

## CI

Job `mysql-integration` en `.github/workflows/tests.yml`:

- Service `mysql:8` con healthcheck.
- Combinación única conservadora: PHP 8.3 + Laravel 12 (orchestra/testbench 10.*).
- Solo ejecuta `vendor/bin/pest tests/Integration/Mysql/` — la matriz SQLite original (4 jobs PHP 8.3+8.4 × L12+L13) queda intacta.

Justificación de la combinación única: el contrato a demostrar es la generación correcta del schema agnóstico, no la compatibilidad cruzada PHP×Laravel. Para esto último ya está la matriz SQLite. Si en el futuro Laravel 13 introduce cambios en `Schema::change()` o en el grammar MySQL que afecten a las migraciones del paquete, sería razonable ampliar a una segunda combinación (PHP 8.4 + L13) — no antes.

## Qué pasa después de v1.0

Cuando haya instalaciones en producción y datos no descartables, este contrato seguirá siendo el mínimo, pero se añadirá un contrato de upgrade explícito: tests dedicados que reproduzcan estados de schema heredados y demuestren conversiones cubiertas. Hasta entonces, ese trabajo es deuda preventiva y queda fuera de alcance.
