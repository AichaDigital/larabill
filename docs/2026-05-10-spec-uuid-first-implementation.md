# SPEC: Implementación UUID-first en larabill

> **Tipo:** Implementation specification
> **Fecha:** 2026-05-10
> **Status:** Pending implementation in dedicated session
> **Sesión recomendada:** sesión propia, focada únicamente en este paquete
> **Estimación:** 4.5 - 6.5 horas de trabajo continuo

## 0. Lectura obligatoria antes de empezar (en orden)

1. **Umbrella standard:** `~/development/packages/aichadigital/STANDARDS.md` STD-001 — el porqué a nivel ecosistema.
2. **ADR canonical:** `larabill/docs/ADR-006-uuid-first-no-agnostic.md` — la decisión arquitectónica.
3. **Esta SPEC:** plan concreto de ejecución.
4. **Setup guide consumidores:** `larabill/docs/setup-uuid.md` — qué entrega el paquete tras la implementación.
5. **CLAUDE.md local del paquete:** `larabill/CLAUDE.md` — reglas del paquete.
6. **CRITICAL_RULES:** `larabill/.claude/CRITICAL_RULES.md` — reglas inviolables (se actualizarán en esta SPEC).
7. **Optional historic context:** `docs/2026-05-09-fresh-install-agnostic-mysql.md` — contrato anterior que esta SPEC supersede; sirve para entender el v0.7.4 que estamos modificando.

## 1. Contexto del paquete

`larabill` es el núcleo de facturación fiscal del ecosistema AichaDigital. Pre-v1, actualmente en `v0.7.4`, gestionado en `dev-main` (no garantiza upgrade entre versiones). Único consumidor real: `~/SitesLR12/clientes`, que usa UUID v7. La pretensión de soporte agnóstico `int|uuid|ulid` se decidió retirar (ADR-006) tras revisión adversarial Claude Opus 4.7 + OpenAI Codex el 2026-05-09.

Esta SPEC es la implementación de la decisión codificada en ADR-006.

## 2. Inspección frozen (datos que ya tenemos, no re-investigar)

### 2.1 Surface en `src/`

| Archivo | Estado actual | Acción |
|---|---|---|
| `src/Support/MigrationHelper.php` | 6 métodos: `userIdColumn`, `agnosticIdColumn`, `getUserIdType`, `detectUserIdType`, `getIdTypeDescription`, `isSupportedIdType` | Mantener `userIdColumn` y `agnosticIdColumn` (simplificados a UUID). Borrar los otros 4. |
| `src/Console/LarabillInstallCommand.php` | 5 métodos privados de detección (`detectOrAskUserIdType`, `detectUserIdTypeFromTable`, `getColumnDetails`, + 3 helpers per-driver). Flag CLI `--user-id-type=`. | Sustituir todos por un único `verifyUsersTableUuid()` preflight. Retirar flag CLI. |
| `src/Console/DetectUserIdTypeCommand.php` | Comando completo (143 líneas) cuyo propósito era detectar y escribir `LARABILL_USER_ID_TYPE` en `.env` | **BORRAR FICHERO ENTERO.** |
| `src/LarabillServiceProvider.php` | Línea `use AichaDigital\Larabill\Console\DetectUserIdTypeCommand;` y línea `->hasCommand(DetectUserIdTypeCommand::class)` | Borrar ambas. |
| `src/Concerns/HasUserRelation.php` | Comment dice "agnostic to the user ID type - reads from config('larabill.user_id_type')" | Reescribir comment a UUID-first language. |
| `config/larabill.php` | Línea 36: `'user_id_type' => env('LARABILL_USER_ID_TYPE', 'uuid')` y bloque comentario líneas 14-35 | Borrar línea + bloque comentario completo. Mantener `user_model`. |

### 2.2 Surface en `tests/`

| Archivo | Estado | Acción |
|---|---|---|
| `tests/Database/migrations/2019_08_19_000000_create_users_table.php` | `users.id` con `$table->id()` (bigInt) | Cambiar a `$table->uuid('id')->primary()`. El `foreignId('current_tax_profile_id')` también pasa a `foreignUuid` o `uuid('current_tax_profile_id')->nullable()`. |
| `tests/Database/migrations/2025_01_01_000000_create_test_users_table.php` | `test_users.id` con `$table->id()` (bigInt) | Cambiar a `$table->uuid('id')->primary()`. Idem `current_tax_profile_id` si lo tiene. |
| `tests/Models/TestUser.php` | Eloquent estándar (auto-increment int) | Añadir `use HasUuids;`, `protected $keyType = 'string';`, `public $incrementing = false;`. |
| `tests/Database/Factories/TestUserFactory.php` | Factory existente (sin verificar contenido) | Verificar que no fija IDs explícitos. Si lo hace, retirar. |
| `tests/TestCase.php` línea 69 | `$app['config']->set('larabill.user_id_type', 'uuid');` | Borrar línea (config key no existe ya). |
| `tests/TestCase.php` líneas 75-97 | `createTestUsers()` con `'id' => 1, 2, 3` hardcoded | Reescribir con constantes UUID v7 deterministas. |
| `tests/TestCase.php` (top de la clase) | Sin constantes de test users | Añadir `public const USER_UUID_1`, `USER_UUID_2`, `USER_UUID_3`. |

### 2.3 Tests con user_id hardcoded — INVENTARIO COMPLETO

Identificados por grep `'user_id'.*=>.*[0-9'"]`:

| Archivo | Ocurrencias | Patrón actual |
|---|---|---|
| `tests/Unit/Models/InvoiceTest.php` | 3 | `'user_id' => 1` |
| `tests/Unit/Models/UserRoiVerificationTest.php` | 12 | `'user_id' => '1'/'2'/'3'` (string) |
| `tests/Unit/Models/RoiQueryTest.php` | 5 | `'user_id' => 'user-123'` (string arbitrario) |
| `tests/Unit/DataTransferObjects/AuditEntryTest.php` | 1 | `'user_id' => 42` |
| `tests/Unit/Services/PDF/DefaultPDFConnectorTest.php` | 5 | `'user_id' => 1` |
| `tests/Unit/Services/PDF/PDFServiceTest.php` | 1 | `'user_id' => 1` |
| `tests/Unit/Models/UserTaxProfileTest.php` | 1+ | `'owner_user_id' => $ownerId2` (variable, posiblemente también int) |

**Total estimado: ~28 ocurrencias en 7 archivos.** Más posibles indirectos (búsquedas `where('user_id', 1)`, `find(1)`, etc.) que aparecerán al ejecutar.

### 2.4 Tests del MigrationHelper a borrar

| Archivo | Tests obsoletos por borrar |
|---|---|
| `tests/Unit/Support/MigrationHelperTest.php` | Tests de `getUserIdType` (líneas ~100-103), `getIdTypeDescription` (líneas ~86-95), tests matrix con `int`/`ulid` |
| `tests/Unit/Support/MigrationHelperEnhancedTest.php` | Tests de `getIdTypeDescription` (líneas ~64-76), tests matrix `int`/`ulid`/`auto`, todo lo que pruebe métodos eliminados |

Mantener: tests que prueban `userIdColumn(Blueprint, name, nullable)` y `agnosticIdColumn(...)` con UUID.

### 2.5 MySQL Integration

| Archivo | Estado | Acción |
|---|---|---|
| `tests/Integration/Mysql/MysqlIntegrationTestCase.php` | Método `bootstrapForUserIdType($idType)` con match `uuid|ulid|default` | Renombrar a `bootstrap()` parameterless. Hardcodear UUID. Quitar línea `'larabill.user_id_type'` (líneas 89, 104). |
| `tests/Integration/Mysql/FreshInstallUserIdTypeTest.php` | Test único con `->with(['int','uuid','ulid'])` | Renombrar archivo a `FreshInstallTest.php`. Quitar `->with([...])`. Hardcodear UUID `$expectedType = 'char'`, `$expectedLength = 36`. Quitar match para customer_id. |

### 2.6 Docs a actualizar

| Archivo | Sección/línea | Acción |
|---|---|---|
| `README.md` línea 11 | "Larabill is a professional, agnostic billing..." | Cambiar "agnostic" por "UUID-first". |
| `README.md` línea 92 | `LARABILL_USER_ID_TYPE="uuid"` en bloque .env | Borrar línea. |
| `SCHEMA_REQUIREMENTS.md` línea 29 | Tabla con `id | uuid/bigint | NO | Primary key. Type configurable via LARABILL_USER_ID_TYPE` | Cambiar a `id | uuid | NO | UUID v7 char(36) — required`. |
| `SCHEMA_REQUIREMENTS.md` línea 217 | `LARABILL_USER_ID_TYPE=uuid` en .env block | Borrar. |
| `CONTRIBUTING.md` línea 88 | "The default suite runs against SQLite in-memory. The agnostic install..." | Reescribir: la suite SQLite es para velocidad de feedback; el contrato real lo cubre `MysqlIntegrationTestCase`. |
| `CONTRIBUTING.md` línea 124 | Referencia a `2026-05-09-fresh-install-agnostic-mysql.md` | Cambiar a referencia ADR-006 + nueva FreshInstallTest. |
| `.claude/CRITICAL_RULES.md` regla 4 | "Use MigrationHelper::userIdColumn() for ALL user FK columns" | Mantener regla pero clarificar: "emits UUID char(36)". Añadir nueva regla "Package requires users.id UUID v7 — see setup-uuid.md". |
| `CLAUDE.md` (local del paquete) | Sección "Estado actual", "Anti-patterns frecuentes", "Reglas inviolables" | Actualizar a v0.8.0 UUID-first. Anti-pattern añadido: "asumir int en helpers o tests". |
| `docs/2026-05-09-fresh-install-agnostic-mysql.md` | Documento entero | Añadir banner SUPERSEDED arriba apuntando a ADR-006. Conservar contenido como rastro histórico. |
| `docs/AGENT_CONTEXT.md` | Sección "Active Contract" | Reescribir a UUID-first contract. |
| `CHANGELOG.md` | Top | Entrada nueva `[0.8.0] - YYYY-MM-DD` marcando breaking change con detalle (helper simplificado, config retirada, comando borrado, tests UUID). |

## 3. Decisión arquitectónica frozen

### 3.1 Camino B (limpio total) — no Camino A

El commit final de "UUID-first" debe tener tests con UUIDs reales. La suite SQLite con `'user_id' => 1` y `test_users.id` bigInt conserva precisamente la ceguera que ADR-006 elimina. La inconsistencia "fixture vs contrato" es estado intermedio aceptable durante la sesión, no estado commiteable.

### 3.2 Constantes UUID determinísticas

Patrón a usar en `tests/TestCase.php`:

```php
class TestCase extends Orchestra
{
    public const USER_UUID_1 = '0194a000-0000-7000-8000-000000000001';
    public const USER_UUID_2 = '0194a000-0000-7000-8000-000000000002';
    public const USER_UUID_3 = '0194a000-0000-7000-8000-000000000003';
    // ...
}
```

Strings 36-char con formato UUID v7 válido (version=7 en posición 13, variant=8 en posición 17). Pasan `Uuid::isValid()`. Legibles, deterministas, mismo patrón en toda la suite.

En tests Pest dentro de closures: `$this::USER_UUID_1` o `\AichaDigital\Larabill\Tests\TestCase::USER_UUID_1`.

### 3.3 Migración pragmática de `'user_id' => 1`

Patrón:
```php
// Antes
$invoice = Invoice::create(['user_id' => 1, /* ... */]);

// Después
$invoice = Invoice::create(['user_id' => $this::USER_UUID_1, /* ... */]);
```

Para `'user_id' => 'user-123'` (RoiQueryTest), usar también `USER_UUID_1`. La string arbitraria era smell pre-existente.

### 3.4 Polimorfismo descartado

Esta SPEC NO introduce morphs. ADR-006 cierra polimorfismo de relación para `customer_id`/`owner_user_id`/`parent_user_id`. Si se introdujera más adelante (cliente real con presupuesto), sería major nueva con ADR propio.

## 4. Plan de ejecución en 5 fases

Ejecutar en bloque, parar solo en "algo conceptual" (sección 6). No fragmentar entre commits ni sesiones. El paquete no está commiteable hasta que las 5 fases cierren.

### Fase 1 — Código del paquete (orden importa)

| # | Acción | Archivo |
|---|---|---|
| 1.1 | Borrar `use AichaDigital\Larabill\Console\DetectUserIdTypeCommand;` y `->hasCommand(DetectUserIdTypeCommand::class)` | `src/LarabillServiceProvider.php` |
| 1.2 | Borrar fichero entero | `src/Console/DetectUserIdTypeCommand.php` |
| 1.3 | Simplificar `MigrationHelper`: `userIdColumn` usa `$table->uuid($column)`. `agnosticIdColumn` idem. Borrar `getUserIdType`, `detectUserIdType`, `getIdTypeDescription`, `isSupportedIdType`. Actualizar PHPDoc. | `src/Support/MigrationHelper.php` |
| 1.4 | Reemplazar lógica de detección en `LarabillInstallCommand`: borrar `detectOrAskUserIdType`, `detectUserIdTypeFromTable`, `getColumnDetails*`. Añadir `verifyUsersTableUuid()` que ejecuta antes de `validatePrerequisites`. Retirar flag CLI `--user-id-type=`. Actualizar mensajes (`✓ User ID type: uuid` → `✓ users.id verified UUID compatible`). | `src/Console/LarabillInstallCommand.php` |
| 1.5 | Borrar línea `'user_id_type'` y bloque comentario líneas 14-35. Mantener `user_model`. | `config/larabill.php` |
| 1.6 | Actualizar PHPDoc del trait. Quitar mención a "agnostic". | `src/Concerns/HasUserRelation.php` |

### Fase 2 — Tests (Camino B)

| # | Acción | Archivo |
|---|---|---|
| 2.1 | `users.id` → `$table->uuid('id')->primary()`. `foreignId('current_tax_profile_id')` → `uuid('current_tax_profile_id')->nullable()`. | `tests/Database/migrations/2019_08_19_000000_create_users_table.php` |
| 2.2 | Idem en `test_users`. | `tests/Database/migrations/2025_01_01_000000_create_test_users_table.php` |
| 2.3 | `TestUser` añade `use HasUuids;`, `$keyType = 'string'`, `$incrementing = false`. | `tests/Models/TestUser.php` |
| 2.4 | Verificar `TestUserFactory`. Si tiene IDs explícitos, retirar. | `tests/Database/Factories/TestUserFactory.php` |
| 2.5 | Definir 3 constantes públicas (USER_UUID_1/2/3). Reescribir `createTestUsers()` para usarlas. Añadir comentario explicando que son fixtures deterministas. | `tests/TestCase.php` |
| 2.6 | Borrar línea `$app['config']->set('larabill.user_id_type', 'uuid');`. | `tests/TestCase.php` línea 69 |
| 2.7 | Migrar 28 ocurrencias `'user_id' => N` o `'user_id' => 'N'` o `'user_id' => 'user-123'` a `$this::USER_UUID_X`. Lista en sección 2.3. | 7 archivos |
| 2.8 | Borrar tests de métodos eliminados (`getUserIdType`, `detectUserIdType`, `isSupportedIdType`, `getIdTypeDescription`). Reducir tests matrix a UUID. | `tests/Unit/Support/MigrationHelperTest.php`, `MigrationHelperEnhancedTest.php` |
| 2.9 | Renombrar `bootstrapForUserIdType($idType)` → `bootstrap()` parameterless. Hardcodear UUID. Quitar línea de config. | `tests/Integration/Mysql/MysqlIntegrationTestCase.php` |
| 2.10 | Renombrar archivo a `FreshInstallTest.php`. Quitar `->with([...])`. Hardcodear `$expectedType='char'`, `$expectedLength=36`. Hardcodear customer_id como UUID v7 generado. | `tests/Integration/Mysql/FreshInstallUserIdTypeTest.php` → `FreshInstallTest.php` |

### Fase 3 — Verificación obligatoria

| # | Acción | Bloqueante para merge? |
|---|---|---|
| 3.1 | `composer test` (suite SQLite con UUIDs reales). Esperar ~959 - 10 obsoletos = ~949 verdes. | sí |
| 3.2 | `composer phpstan` | sí |
| 3.3 | `composer pint` (auto-fix de formato) | recomendado, no bloqueante de funcionalidad |
| 3.4 | **MySQL Integration** vía `LARABILL_TEST_MYSQL_*` envs locales (`vendor/bin/pest tests/Integration/Mysql/`) o esperando job `mysql-integration` verde en CI antes de merge. | sí (uno de los dos) |

### Fase 4 — Documentación (todas las menciones de tabla 2.6)

Ejecutar todas las acciones documentales de la sección 2.6 en bloque.

### Fase 5 — Cierre

| # | Acción |
|---|---|
| 5.1 | CHANGELOG entry `[0.8.0]` con detalle de breaking + lista exhaustiva de cambios |
| 5.2 | Commit (o serie de commits — recomendado: `refactor: remove user_id_type agnostic helper code`, `test: migrate fixtures to UUID v7 (Camino B)`, `docs: ADR-006 + setup-uuid.md + README/SCHEMA refresh`, `chore: bump v0.8.0 + CHANGELOG`) |
| 5.3 | PR (si aplica) o merge directo + tag `v0.8.0` tras CI verde |
| 5.4 | Actualizar fila larabill en `~/development/packages/aichadigital/STANDARDS.md` (working note umbrella, no commit en repo larabill): columnas Code migrated, Preflight, MySQL test → ✓. Estado global → "migrated". |

## 5. Para una segunda voz adversarial (si se invoca `/codex challenge` durante la sesión)

### Asunciones que esta SPEC hace y que un revisor adversarial puede cuestionar

1. **Asunción:** la suite SQLite debe seguir siendo el feedback rápido y la suite MySQL el contrato real.
   **Cuestionable:** ¿debería SQLite jubilarse y dejar solo MySQL? Coste: lentitud en dev local. Beneficio: un solo código path.
   **Decisión:** mantener ambas. SQLite para velocidad, MySQL para contrato.

2. **Asunción:** las constantes UUID v7 hardcoded en TestCase son aceptables como fixtures deterministas.
   **Cuestionable:** ¿prefieres `Str::createUuidsUsing()` con generador determinista? ¿O factories que generan UUIDs aleatorios pero los tests guardan referencia?
   **Decisión:** constantes hardcoded son las más simples y legibles. Permiten `'user_id' => $this::USER_UUID_1` como reemplazo directo de `'user_id' => 1`.

3. **Asunción:** retirar el flag CLI `--user-id-type=` no rompe nada externo porque dev-main no garantiza upgrade.
   **Cuestionable:** ¿hay scripts de CI/deploy en `clientes` que pasen ese flag?
   **Verificación:** grep en `~/SitesLR12/clientes` por `--user-id-type` antes de retirar.

4. **Asunción:** retirar `LARABILL_USER_ID_TYPE` ENV var no rompe `clientes`.
   **Cuestionable:** ¿está en su `.env`? Si está, su valor pasa a ser ignorado silenciosamente.
   **Mitigación:** preflight check produce mensaje claro. La variable ignorada no causa fallo, solo confusion.
   **Verificación:** grep en `clientes/.env*` por `LARABILL_USER_ID_TYPE`.

5. **Asunción:** los 28 lugares con `'user_id' => 1` no tienen lógica de negocio que dependa del tipo int.
   **Cuestionable:** ¿algún test hace `where('user_id', '<', X)`, `orderBy('user_id')`, sumas? Si sí, romperá al cambiar a UUID.
   **Mitigación:** sección 6 ("algo conceptual") establece este como criterio de parada.

6. **Asunción:** v0.8.0 es bump apropiado (breaking en pre-v1).
   **Cuestionable:** SemVer estricto diría v1.0.0 (breaking implica major). Pero pre-v1 cualquier minor puede ser breaking.
   **Decisión:** v0.8.0. v1.0.0 se reserva para "estable y promete upgrade".

7. **Asunción:** lara-content y laratickets se ejecutan en sesiones separadas, después de larabill, en orden.
   **Cuestionable:** ¿tendría sentido paralelizar? Ventaja: rapidez. Desventaja: si larabill descubre algo conceptual, los otros lo aprovechan.
   **Decisión:** secuencial. larabill primero como referencia probada.

### Alternativas explícitamente descartadas (por si codex las propone)

- **Polimorfismo (`nullableMorphs`):** descartado en ADR-006. Cardinalidad 1, no es polimorfismo lo que falta.
- **Soporte ULID además de UUID:** descartado. Sin demanda real.
- **Mantener `int` como rama latente "por si acaso":** descartado. Bitrot garantizado.
- **Paquete compartido `lara-fk-helpers`:** evaluado en sesión adversarial 2026-05-09, descartado. Coste de extracción supera beneficio una vez el helper se reduce a ~3 líneas.
- **Camino A (fixture bigInt + producción UUID):** descartado por el usuario el 2026-05-10 con razonamiento sólido: documentar UUID y testear int oculta el tipo de ceguera que motivó la decisión.

## 6. Criterio de parada — "algo conceptual rompe"

Detener ejecución y reportar al usuario si aparece alguno de estos durante Fase 2 o 3:

1. **Lógica de negocio que asume IDs incrementales.** Ejemplo: `where('user_id', '<', $other)`, `orderBy('user_id')` con expectativa numérica, sumas/promedios de user_ids, `User::find($invoice->user_id + 1)`.
2. **Validation rules que esperan int** (`'user_id' => 'integer'` en FormRequest, validators custom).
3. **Casts en modelos del paquete** que castan `user_id` a int (en Models bajo `src/Models/`).
4. **Relaciones Eloquent rotas** por type mismatch (un `BelongsTo` que falla con UUID).
5. **Factories que requieren orden de creación** (TestUser::factory()->for(...)->create() con dependencias entre IDs).
6. **Más de 5 tests que rompen por causas no triviales** tras Fase 2.7 (migración de hardcoded user_ids).

En cualquiera de esos casos: parar, anotar el hallazgo en este SPEC bajo nueva sección "Hallazgos durante implementación", proponer al usuario cómo proceder.

NO parar por:
- Tests que rompen por importes/totales (no relacionado a PK).
- Failures en tests que ya estaban marcados `skipped`.
- Mensajes de phpstan que requieren un `@var` adicional.
- Pint complains.
- Nombres de archivos a renombrar.

## 7. Rollback strategy

Toda la sesión vive en una rama de feature (recomendado: `feat/uuid-first-v0.8.0`). Si algo va catastróficamente mal:

```bash
git checkout main && git branch -D feat/uuid-first-v0.8.0
```

Rollback completo, cero impacto. No hay cambios en BD productiva (todo es código + tests). El consumidor `clientes` no se ve afectado mientras la rama no se mergee.

## 8. Estimación honesta

- Fase 1: 30 min (cambios mecánicos, riesgo bajo)
- Fase 2: **3-5 horas** (la migración de 28+ tests es el tiempo principal — leer cada test para no romper expectativas indirectas)
- Fase 3: 30 min (correr tests + fixes triviales). Más si "algo conceptual" aparece.
- Fase 4: 30 min (docs, mecánico)
- Fase 5: 5 min (CHANGELOG + commit)

**Total: 4.5 - 6.5 horas continuas.** No fragmentable sin pérdida significativa de contexto.

## 9. Glosario

- **Camino B**: tests con UUIDs reales (decisión congelada).
- **"Algo conceptual"**: criterio de parada — sección 6.
- **Working note umbrella**: STANDARDS.md, fuera del repo larabill, no se commitea desde aquí.
- **Migrated** (criterio de STANDARDS.md): cumplir los 5 criterios — esta SPEC los cubre todos.

## 10. Referencias cruzadas

- ADR canonical: `larabill/docs/ADR-006-uuid-first-no-agnostic.md`
- Setup guide: `larabill/docs/setup-uuid.md`
- Standard umbrella: `~/development/packages/aichadigital/STANDARDS.md` STD-001
- Sesión adversarial origen: 2026-05-09, Claude Opus 4.7 + OpenAI Codex
- Sesión que produjo este SPEC: 2026-05-10
- Próximas SPECs hermanas (en sus paquetes):
  - `laratickets/docs/2026-05-10-spec-uuid-first-implementation.md`
  - `lara-content/docs/2026-05-10-spec-uuid-first-implementation.md`
