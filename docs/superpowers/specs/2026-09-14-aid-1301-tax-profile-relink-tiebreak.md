# Diseño AID-1301: el hook `created` de `UserTaxProfile` relinka a todos los usuarios del perfil cerrado, no al «anterior» que gane un empate

- **Ticket:** AID-1301 (Medium) — hallazgo de la ronda 8 del gate adversarial de AID-1246 (`clientes`). Hermano de AID-967 (mismo hook, defecto distinto, **fuera de este MR**).
- **Base:** `main` @ `e0bce2b` (v6.13.0 publicada + AID-974 mergeado y **sin publicar**: `[Unreleased]` ya lleva su entrada, así que la próxima release es **MINOR** con independencia de este ticket).
- **Estado:** **rev 4** — decisiones D1, D3, D4 y D6 cerradas por el propietario el 2026-09-14; rondas 1 y 2 del gate adversarial de implementación adjudicadas en §8. Commitear este spec lo deja como diseño revisable: **no aprueba la implementación ni la publicación**, que siguen sujetas a sus gates (§7).
- **Relacionado:** ADR-003 (unificación user/customer), el patrón de perfiles compartidos por `owner_user_id` + `users.current_tax_profile_id` (`SCHEMA_REQUIREMENTS.md`, sección ADR-004), `STABILITY.md` regla 5.

## 1. El defecto, medido

`UserTaxProfile::boot()` encadena dos hooks (`src/Models/UserTaxProfile.php:127-138`): en `creating`, si el perfil nace activo, `closeActiveForOwner()` (`:225-234`) cierra el activo anterior del owner poniéndole `valid_until = valid_from − 1 día` e `is_active = false`; en `created`, `updateLinkedUsersToNewProfile()` (`:374-391`) busca «el perfil anterior» y relinka a los usuarios que apuntan a él:

```php
$previousProfile = static::where('owner_user_id', $this->owner_user_id)
    ->where('id', '!=', $this->id)
    ->whereNotNull('valid_until')
    ->orderBy('valid_until', 'desc')
    ->first();
```

`valid_until` es columna **`date`** (`database/migrations/2025_01_27_000001_create_user_tax_profiles_table.php:51`). Dos perfiles creados el mismo día cierran ambos con el **mismo** `valid_until`; el `orderBy` no desempata y `first()` devuelve el que el motor recorra primero. El hook no ha cambiado de lógica desde `dd98b25` (ADR-004).

**Reproducido en la suite del paquete el 2026-09-14, sin Livewire, por motor.** Owner y delegado apuntan a P0; `createForOwner()` dos veces el mismo día:

```text
PROBE_SAME_DAY driver=sqlite  p0=1 p1=2 p2=3 closed=[1@2026-09-13,2@2026-09-13] delegate_after_first=2 delegate_after_second=2
PROBE_SAME_DAY driver=mysql   server=11.4.13-MariaDB   (idéntico)
PROBE_SAME_DAY driver=mariadb server=11.4.13-MariaDB   (idéntico)
```

Los tres motores locales eligen P0 como «anterior» ante el empate: el hook relinka a quien apunte a P0 (nadie, ya están en P1) y el delegado se queda en **P1, cerrado**. Coincide con la sonda del consumidor (`delegate=2 delegate_profile_active=no`). MySQL 9 no está accesible en local hoy; lo cubre el job `db-integration` de CI. *(Corrección 2026-09-15, AID-1319: ese job corría contra `mysql:9`, fuera de la frenada del operador —MySQL solo 8.4—; pasa a `mysql:8.4`, y el harness completo quedó en verde contra MySQL 8.4.10 en local.)*

**Consecuencia en el consumidor:** el cliente se repara porque `CustomerEdit` escribe su puntero a mano; sus delegados quedan facturando con un perfil fiscal cerrado hasta la siguiente edición que, por azar del motor, los alcance.

### La semántica ya publicada, que el código incumple

- PHPDoc de la clase, que es superficie `@api` (`UserTaxProfile.php:28-30`): *«When fiscal data changes, a new profile is created and **all linked users** are updated to point to the new profile.»*
- `SCHEMA_REQUIREMENTS.md:82-84`: `current_tax_profile_id` es *«which profile a user is **currently** using»* y *«Only **one** active profile per owner with `valid_until = null`»*.

Un usuario apuntando a un perfil cerrado del owner contradice las dos frases a la vez. Es la misma clase de caso que ADR-013 (AID-956): código que contradice una semántica ya publicada.

## 2. Límites del encargo

- **Sin migraciones, sin backfill, sin cambios de esquema.** `users.current_tax_profile_id` es columna del **consumidor** (`SCHEMA_REQUIREMENTS.md:44`); el paquete la escribe en runtime por el hook, no por migración.
- **Ninguna firma cambia.** El método es `protected`, fuera del snapshot de contrato (`tests/Contract/snapshots/UserTaxProfile.json` no lo lista).
- **`closeActiveForOwner()` no se toca.** No es parte del defecto.
- **AID-967 (primer enlace del owner) no entra** — D6.
- **Nada de concurrencia ni de atomicidad de la creación completa** — §3 D7.

## 3. Decisiones

### D1 — El hook relinka a todo usuario que apunte a **cualquier** perfil cerrado del owner *(cerrada: A)*

`updateLinkedUsersToNewProfile()` deja de buscar «el anterior» y pasa a una sola sentencia Eloquent:

```php
$closedProfileIds = static::query()
    ->where('owner_user_id', $this->owner_user_id)
    ->whereNotNull('valid_until')
    ->pluck('id');

if ($closedProfileIds->isEmpty()) {
    return;
}

$userModel::whereIn('current_tax_profile_id', $closedProfileIds)
    ->update(['current_tax_profile_id' => $this->id]);
```

**Los ids se leen primero y se pasan como valores, no como subconsulta** *(corrección de la ronda 1 del gate, §8)*. Perfiles fiscales y usuarios del consumidor son dos modelos que pueden no compartir conexión; el código anterior hacía dos consultas independientes y nunca lo asumió. Una subconsulta incrustada en el `UPDATE` de usuarios lo habría asumido. Sigue siendo **un solo `UPDATE`** sobre usuarios.

**Techo declarado** *(ronda 2 del gate, §8)*: cada id cerrado es un parámetro enlazado, así que el relink necesita tantos parámetros como perfiles cerrados tenga el owner, más dos. Los motores limitan los parámetros por sentencia (32.766 medidos a través del hook en SQLite 3.45; 65.535 en el código de sentencias preparadas de MySQL 8.4 y MariaDB 11.4, leído en sus fuentes y no ejecutado). Por encima, crear el siguiente perfil del owner falla con error de base de datos. Un owner acumula un perfil cerrado por cada cambio de datos fiscales, así que los recuentos reales están en las unidades o decenas. Se declara en el código y en el CHANGELOG en vez de partir la escritura: trocearla añadiría código y un segundo modo de escritura para un caso que no existe.

**La salida temprana es obligatoria, no una optimización** *(añadido en implementación, ver §6.1)*. El código anterior retornaba sin tocar `users` cuando no había perfil cerrado. Sin ese retorno, la sentencia exige que exista `users.current_tax_profile_id` también en el primer perfil de cada owner: una instalación que no tuviera la columna y hasta hoy creaba primeros perfiles sin problema empezaría a fallar. `SCHEMA_REQUIREMENTS.md` la declara requerida, pero introducir esa dependencia dura dentro de un PATCH habiendo una solución compatible de dos líneas contradice `CONSTITUTION.md` §8.

Modelo de usuario configurado (`ModelMappingService::getModelClass('user')`, como hoy), lectura de ids por el modelo fiscal y actualización masiva. **Sin SQL crudo**, y conservando los scopes del modelo fiscal: la lectura de ids pasa por el scope global de `SoftDeletes`, igual que hoy lo hace `first()`.

**Se conserva la actualización masiva a propósito.** `update()` sobre el builder no dispara eventos individuales del modelo de usuario, exactamente como el código actual. Convertirlo en un bucle de `save()` haría que el consumidor empezara a recibir `saving`/`updating`/`updated` de su propio `User` desde un hook del paquete: sería un cambio de comportamiento que este ticket no pide.

Se elige frente a la alternativa **B** —capturar en `creating` los ids que `closeActiveForOwner()` acaba de cerrar y relinkar solo a esos en `created`— por tres razones:

- **Es la semántica publicada, literal.** «All linked users» y «only one active»: ningún usuario del owner puede estar apuntando legítimamente a un perfil cerrado, porque cerrado significa «ya no es el que se usa». No hay reapertura en el paquete: `closeActiveForOwner()` es el **único** escritor de `valid_until` en `src/` fuera de factories (verificado por `grep`).
- **No depende del motor ni de estado entre hooks.** La alternativa B exige que `closeActiveForOwner()` devuelva ids (hoy `void`) y transportarlos de un evento al siguiente en la instancia; un desempate por `id` es determinista pero sigue eligiendo **uno** y dejando fuera a quien apunte al otro.
- **Repara el estado que el defecto ya dejó.** Un delegado hoy parado en un perfil cerrado pasa al activo en la siguiente creación del owner, sin backfill (D4).

**Alcance declarado, y es más ancho que el empate:** el hook pasa a tocar también a usuarios que apunten a perfiles cerrados **antiguos** del owner, no solo al cerrado por esta creación. Va al CHANGELOG con viejo y nuevo (§5).

**Límite que se conserva tal cual:** un usuario apuntando a un perfil cerrado **y soft-deleted** no se relinka. Ese estado lo crea el consumidor al borrar y no es objeto de este ticket. Se declara, no se disfraza.

**Se mantiene igual:** el hook relinka a quien apunte al perfil sea de quien sea (hoy ya lo hace: `where('current_tax_profile_id', $previousProfile->id)` no filtra por owner del usuario). El nuevo perfil no entra en la subconsulta porque nace con `valid_until = null`.

### D2 — El `created` sigue siendo el único punto de relink; sin servicio nuevo

No se añade superficie: ni un `@api` nuevo, ni comando, ni evento. El hook ya existe y es el camino que todo consumidor atraviesa al llamar `createForOwner()`. Añadir un servicio de escritura (patrón ADR-012/AID-974) sería sobreingeniería para un `UPDATE` de una sentencia sin invariante que serializar.

### D3 — Clasificación: **PATCH**, documentado bajo `Fixed` *(cerrada)*

Las dos cláusulas en tensión, citadas ambas:

- `docs/api-surface.md:7`: *«removed surface or changed signatures/**semantics** → major»*.
- `STABILITY.md` regla 5 (`:34`) y «What this means in practice» (`:38`): un defecto se corrige en **patch o minor**; los fixes que cambian comportamiento observable se documentan bajo **Fixed** con viejo y nuevo.

Se resuelve así: **no cambia la semántica declarada — cambia el código que la incumplía.** La promesa «all linked users are updated» está escrita en la clase `@api` desde ADR-004; el hook la cumplía solo cuando no había empate. Es el razonamiento de ADR-013 (v6.13.0, AID-956) y de AID-974 D7. A diferencia de esos dos, aquí **no hay adiciones `@api`**, y por eso es PATCH.

**Versión y sección del CHANGELOG son decisiones distintas.** La clasificación de AID-1301 es PATCH; la release que lo lleve es MINOR por AID-974; y la entrada va bajo `### Fixed` en cualquiera de los dos casos, porque es la corrección de un defecto. Clasificarlo MINOR tampoco habría obligado a sacarlo de `Fixed`.

**Condición bajo la que la clasificación decae:** si la implementación necesitara tocar una firma (no debería: método `protected`) o añadir superficie `@api`. Entonces se vuelve a decidir, no se fuerza el PATCH.

### D4 — Sin backfill; diagnóstico y reparación **opcional** documentados en Eloquent *(cerrada)*

No hay migración de reparación porque (a) la columna es del consumidor, (b) la propia D1 repara en la siguiente creación del owner, y (c) una migración de datos exige test de upgrade-path y manifest (AID-398) para una decisión que corresponde al consumidor.

El CHANGELOG publica una **receta**, y publicarla no implica ejecutarla. Dos reglas la gobiernan:

- **No se afirma que la reparación sea segura sin condiciones.** Un owner puede tener **cero** perfiles activos (todos cerrados, o el activo soft-deleted) o **varios** (datos sembrados saltándose `createForOwner()`, o escrituras concurrentes — §3 D7). En esos casos no hay un destino inequívoco y la receta **no toca nada**: los reporta como anomalía para que el consumidor decida.
- **La escritura es condicional al estado diagnosticado, en origen y en destino.** Antes de escribir se vuelve a comprobar que el único activo del owner **sigue siendo** el diagnosticado; si no, se salta. Y cada puntero se actualiza solo si **sigue siendo** el cerrado que se diagnosticó (compare-and-set sobre `current_tax_profile_id`). *(La revisión del destino es corrección de la ronda 1 del gate, §8.)*
- **Las comprobaciones estrechan la ventana, no la cierran.** Revisión y escritura son dos sentencias; un cambio de perfil que caiga justo entre ambas no se ve. El CHANGELOG lo dice y recomienda ejecutar la reparación sin edición fiscal concurrente o dentro de una transacción propia del consumidor.

Receta, respetando el modelo de usuario configurable (la misma resolución que el paquete: `larabill.models.user` si apunta a una clase existente, si no `larabill.user_model`):

```php
use AichaDigital\Larabill\Models\UserTaxProfile;

$userModel = config('larabill.models.user');
$userModel = is_string($userModel) && class_exists($userModel)
    ? $userModel
    : config('larabill.user_model');

$repairable = [];
$anomalies  = [];

$userModel::query()
    ->whereNotNull('current_tax_profile_id')
    ->lazyById()
    ->each(function ($user) use (&$repairable, &$anomalies): void {
        $closedProfile = UserTaxProfile::query()
            ->whereKey($user->current_tax_profile_id)
            ->whereNotNull('valid_until')
            ->first();

        if ($closedProfile === null) {
            return;
        }

        $active = UserTaxProfile::query()
            ->active()
            ->forOwner($closedProfile->owner_user_id)
            ->limit(2)
            ->pluck('id');

        $row = [$user->getKey(), $closedProfile->getKey(), $closedProfile->owner_user_id, $active->all()];

        if ($active->count() === 1) {
            $repairable[] = $row;
        } else {
            $anomalies[] = $row;
        }
    });

// Diagnosis: inspect $repairable and $anomalies before deciding anything.

// Optional repair — unambiguous destinations only, re-checked at write time,
// and only if the pointer is still the one diagnosed. Anomalies are never touched.
foreach ($repairable as [$userId, $closedId, $ownerId, [$activeId]]) {
    $active = UserTaxProfile::query()
        ->active()
        ->forOwner($ownerId)
        ->limit(2)
        ->pluck('id');

    if ($active->all() !== [$activeId]) {
        continue;
    }

    $userModel::query()
        ->whereKey($userId)
        ->where('current_tax_profile_id', $closedId)
        ->update(['current_tax_profile_id' => $activeId]);
}
```

**Límites de la receta, declarados:** lee usuarios por el modelo configurado y perfiles fiscales por `UserTaxProfile`, **nunca en la misma sentencia**, así que funciona con conexiones distintas y ya no presupone el trait `HasUserRelationships` *(ronda 2 del gate, §8)*; a cambio hace una consulta de perfil por usuario con puntero, aceptable en un mantenimiento puntual. Los perfiles cerrados **y** soft-deleted quedan fuera del diagnóstico, igual que del hook (D1), y la reparación, como el hook, no dispara eventos del modelo de usuario.

**Obligación de verificación antes de publicarla:** la receta se ejecuta en un test (§6, test 9) contra el esquema del paquete, con un caso reparable, un owner sin activo y un owner con dos activos. Una receta de CHANGELOG que nadie ha corrido es una afirmación, no una receta.

### D5 — Verificación por motor, con sensibilidad medida

Quién gana el empate lo decide el motor, así que el test de regresión vive **en dos sitios**: la suite SQLite (rápida, rojo verificado hoy) y `tests/Integration/Mysql/` (MySQL 9 + MariaDB 11.4 × 2 drivers en CI; *corregido en AID-1319: MySQL 8.4*). La sensibilidad se prueba **revirtiendo el fix** y comprobando el rojo en cada motor accesible — SQLite y MariaDB 11.4 × 2 drivers en local; MySQL 8.4 de Herd (`3384`) solo si está arrancado y con usuario dedicado, y en ese caso se comprueba el recuento de omitidos antes de dar el verde por bueno; MySQL 9 en CI. No se extrapola de un motor a otro (lección AID-836).

### D6 — AID-967 va en un MR separado *(cerrada: no)*

Compartir método no justifica ampliar este MR. El primer enlace del owner exige decidir qué hacer si el owner **ya apunta a otro perfil**, incluido un perfil compartido de **otro** owner, y esa semántica no está definida aquí. AID-967 sigue en Backlog con ese matiz; los dos MRs pueden entrar secuencialmente en la misma release. El que vaya segundo se rebasa sobre el primero (mismo fichero, lección 2026-07-30).

### D7 — La relink es atómica; la creación del perfil **no lo es**, y se declara sin arreglarla

La sentencia de D1 es **una** `UPDATE`: o relinka a todos los afectados o a ninguno. Eso **no** convierte en atómica la creación del perfil. `createForOwner()` (`:269-277`) llama a `static::create()` sin abrir transacción (verificado: no hay `DB::transaction` ni `beginTransaction` en `UserTaxProfile.php`), así que son tres escrituras separadas:

1. `creating` → `closeActiveForOwner()` cierra el activo anterior (`UPDATE`).
2. La inserción del perfil nuevo (`INSERT`).
3. `created` → la relink de D1 (`UPDATE`).

Un fallo entre 1 y 2 deja al owner **sin perfil activo**; entre 2 y 3, con el perfil nuevo activo y los usuarios aún en el cerrado. Y dos `createForOwner()` concurrentes del mismo owner pueden cerrar cada uno lo que el otro todavía no ha insertado y acabar con **dos activos**.

**Es una limitación preexistente, no introducida ni empeorada por este cambio**, y queda **fuera** del encargo: envolverla en transacción cambia el comportamiento transaccional que ven los consumidores que ya llaman a `createForOwner()` dentro de la suya (lección AID-570: el `attempts` de una transacción anidada es un savepoint), y la concurrencia es materia de AID-1246. Se declara aquí y en la entrada del CHANGELOG para que nadie lea «atómico» en D1 como una garantía de la operación completa. Es además la razón por la que D4 no puede suponer un único activo por owner.

## 4. No objetivos

- No se toca `closeActiveForOwner()` ni la política `valid_until = valid_from − 1 día`.
- No se toca `CompanyFiscalConfig`, que tiene el mismo patrón temporal (`:125`) pero **no** relinka a nadie — no hay defecto equivalente.
- No se hace atómica la creación del perfil ni se modela su concurrencia (D7).
- No se añade índice ni unique: la subconsulta cae en `idx_utp_owner_validity_active` (`owner_user_id, valid_from, valid_until, is_active`), suficiente para las cardinalidades reales.
- No se escribe ADR: no hay decisión de arquitectura nueva, hay un hook que deja de incumplir la que ya está escrita.
- No se toca `MysqlIntegrationTestCase::createUsersTable()`, aunque crea `users` sin `current_tax_profile_id` (§6 test 8).

## 5. Behavior changes

- **Viejo:** al crear un perfil activo, el hook relinka solo a los usuarios que apuntan a **un** perfil cerrado del owner — el de mayor `valid_until`, y ante empate el que devuelva el motor. **Nuevo:** relinka a los usuarios que apuntan a **cualquier** perfil cerrado (no soft-deleted) del owner. Con un solo perfil cerrado, viejo y nuevo son idénticos.
- **Efecto colateral deliberado:** un usuario que quedó apuntando a un perfil cerrado por el defecto pasa al activo en la **siguiente** creación del owner, sin intervención del consumidor.
- **Sin cambio** cuando el owner no tiene perfiles cerrados (primer perfil): el hook sigue sin tocar a nadie — eso es AID-967.
- **Sin cambio** en eventos: la relink sigue siendo masiva y no dispara eventos del modelo de usuario.
- **Sin cambio** en `CompanyFiscalConfig`, en `closeActiveForOwner()`, en scopes, en la (no) atomicidad de `createForOwner()` ni en ninguna firma.

## 6. Plan de tests (TDD, rojo primero)

**Unit — SQLite (`tests/Unit/Models/UserTaxProfileRelinkTest.php`, nuevo, para no engordar el fichero de 400 líneas del modelo):**

1. **Regresión del ticket:** owner y delegado en P0; `createForOwner()` dos veces el mismo día → ambos acaban en P2. ⚠️ Rojo hoy, medido (§1).
2. **Camino que ya funcionaba:** un solo sucesor (P0 → P1, días distintos) relinka a todos los que apuntaban a P0. Verde hoy; protege el caso simple.
3. **Aislamiento por owner:** un usuario que apunta a un perfil cerrado de **otro** owner no se toca.
4. **Reparación (D1, alcance declarado):** un usuario parado en un perfil cerrado **antiguo** del owner (sembrado directamente, simulando el estado que dejó el defecto) pasa al activo en la siguiente creación.
5. **Primer perfil:** sin perfiles cerrados, nadie cambia de puntero —incluido el owner con puntero `null` (AID-967 fuera de alcance)— y **no se consulta la tabla de usuarios** (§6.1).
6. **Perfil cerrado y soft-deleted:** un usuario que apunta a él **no** se relinka. Pinea el límite declarado de D1.
7. **El hook no dispara** para perfiles creados inactivos o con `valid_until` (comportamiento actual, `:135`).
7bis. **Sin eventos del modelo de usuario:** la relink no dispara `updating`/`updated` en el modelo configurado. Pinea la decisión de conservar la actualización masiva (D1).
9. **La receta de D4 funciona tal como se publica, con los usuarios en otra conexión:** un usuario reparable acaba en el activo; un owner sin activo y un owner con dos activos quedan en `$anomalies` **sin modificar**; un puntero cambiado entre diagnóstico y reparación se salta (compare-and-set); y un destino **borrado, sustituido o acompañado de un segundo activo** entre diagnóstico y reparación también se salta.
9bis. **El hook relinka con el modelo de usuario en otra conexión** que no tiene las tablas fiscales. *(Tests 9 ampliado y 9bis nuevo: ronda 2 del gate, §8.)*
10. **Receta publicada = receta ejecutada:** un test extrae el bloque del CHANGELOG y lo compara línea a línea con el código que ejecuta el test 9, ignorando solo comentarios, líneas vacías e indentación; cada `use` publicado tiene que existir literalmente entre los imports del fichero de test (ronda 2). *(Tests 9 ampliado y 10 nuevo: ronda 1 del gate, §8.)*

**Integration — motor real (`tests/Integration/Mysql/UserTaxProfileRelinkTest.php`, nuevo):**

8. El test 1 contra MySQL/MariaDB. El harness crea `users` **sin** `current_tax_profile_id`; el test añade la columna con `Schema::table()` — es contrato del consumidor (`SCHEMA_REQUIREMENTS.md:44`) y así queda escrito en el propio test.

**Prueba de sensibilidad, por mutante:**

| Mutante | Debe poner rojos |
|---|---|
| Revertir D1 (volver a `first()` sobre `valid_until desc`) | 1, 4, 8 — en cada motor accesible, por separado |
| Quitar `where('owner_user_id', …)` de la subconsulta | 3 |
| Quitar el scope de `SoftDeletes` (`withTrashed()`) | 6 |
| Sustituir el `update()` masivo por un bucle de `save()` | 7bis |
| Quitar el `where('current_tax_profile_id', $closedId)` de la receta | 9 (rama compare-and-set) |
| Aceptar cualquier `count() >= 1` en la receta | 9 (rama de dos activos) |

Un mutante que no ponga rojo ningún test se registra como **no discriminado** y se decide si falta un test; no se inventa un rojo. Ningún mutante debe tocar los tests 2 ni 7.

### 6.1 Resultado medido (implementación, 2026-09-14)

Rojo antes del fix: tests 1 y 4 en SQLite; test 8 en MariaDB 11.4 con driver `mysql` y con driver `mariadb`. Verde tras el fix en los tres. Cada mutante se aplicó sobre una copia de seguridad, se verificó que la edición **aplicó** (recuento del símbolo mutado) antes de leer el resultado, y se restauró desde la copia (comprobado byte a byte, nunca con `git checkout`):

| Mutante | Resultado |
|---|---|
| Revertir D1 (cuerpo previo al fix, desde `HEAD`) | rojos 1 y 4 (SQLite); 8 rojo en MariaDB × 2 antes del fix |
| Quitar la salida temprana | rojo 5 |
| Quitar el filtro por owner | rojo 3 |
| `withTrashed()` en la subconsulta | rojo 6 |
| Bucle de `save()` en vez de `update()` masivo | rojo 7bis |
| Quitar `whereNotNull('valid_until')` | rojo 5 (el perfil recién creado entra en la subconsulta, `exists()` es cierto y se consulta `users`) |
| Quitar el compare-and-set de la receta | rojo 9 |
| `count() >= 1` en la receta | rojo 9 |

**Tabla re-medida tras la ronda 1 del gate** (código con `pluck`, receta con revisión del destino, tests 6, 7bis y 9 reforzados, test 10 nuevo). Once mutantes, cada uno comprobado como aplicado y restaurado byte a byte:

| Mutante | Rojos |
|---|---|
| Cuerpo previo al fix (`cc00b24`) | 1, 4 |
| Quitar la salida temprana | 5 |
| Quitar el filtro por owner | 3 |
| `withTrashed()` en la lectura de ids | 6 |
| Bucle de `save()` | 7bis |
| Bucle de `saveQuietly()` (F3 de Codex) | 7bis |
| Quitar `whereNotNull('valid_until')` | 5 |
| Quitar el compare-and-set solo en la copia del test | 9, 10 |
| Quitar el compare-and-set solo en el CHANGELOG (F4 de Codex) | 10 |
| `count() >= 1` en el diagnóstico, en ambos | 9 |
| Quitar la revisión del destino, en ambos | 9 |

**Corrección de la ronda 2:** la frase anterior de este spec decía que la independencia de conexión «no es medible en local». Era falsa: con dos conexiones SQLite `:memory:` se mide, y lo midió el revisor. Ahora la cubren los tests 9 y 9bis.

**Tabla re-medida tras la ronda 2** (hook sin cambios de código; receta sin subconsulta ni relación; tests 7bis, 9 y 10 reforzados; 9bis nuevo). Cada mutante comprobado como aplicado (recuento o hash) y restaurado byte a byte:

| Mutante | Rojos |
|---|---|
| Cuerpo previo al fix (`cc00b24`) | 1, 4, 9bis |
| Quitar la salida temprana | 5 |
| Quitar el filtro por owner | 3 |
| `withTrashed()` en la lectura de ids | 6 |
| Bucle de `saveQuietly()` | 7bis |
| Segunda escritura oculta tras un comentario SQL inicial (bien formada) | 7bis (2 `UPDATE` en vez de 1) |
| Quitar `whereNotNull('valid_until')` | 5 |
| Subconsulta incrustada en el `UPDATE` de usuarios (hook de la ronda 0) | 9, 9bis (`no such table`) |
| Quitar el CAS solo en la copia del test | 9, 10 |
| Quitar el CAS solo en el CHANGELOG | 10 |
| Import con alias (`CompanyFiscalConfig as UserTaxProfile`) solo en el CHANGELOG | 10 |
| `count() >= 1` en el diagnóstico, en ambos | 9 |
| Quitar la revisión del destino, en ambos | 9 |
| Revisión del destino reducida a `count() !== 1`, en ambos | 9 (destino sustituido) |
| Revisión del destino reducida a `isEmpty()`, en ambos | 9 (sustituido y acompañado) |
| Receta de la ronda 1 (subconsulta + relación), en la copia del test | 9 (`no such table: user_tax_profiles` en la conexión de usuarios) |

Una primera versión del mutante de la escritura oculta estaba mal formada y fallaba por su propia SQL en ocho tests: no contó como medición y se repitió bien formada.

**Regresión cazada por el harness, y corregida antes de commitear.** La primera implementación (la sentencia de D1 sin salida temprana) daba 1296 verdes en SQLite y los 9 tests nuevos en verde, pero el harness gateado sobre MariaDB 11.4 falló con los dos drivers en `tests/Concurrency/RecurringBillingConcurrencyTest` («same service»): `Unknown column 'current_tax_profile_id' in 'SET'`. El harness crea `users` sin esa columna y el hook, que antes retornaba en el primer perfil, ahora lanzaba el `UPDATE` igualmente. La suite SQLite no lo veía porque `test_users` sí tiene la columna. Corregido con la salida temprana de D1, y el test 5 pasa a afirmar que **no se consulta `users`** cuando no hay perfil cerrado, lo que discrimina tanto ese mutante como el de `whereNotNull`, que en la primera versión quedaba no discriminado.

## 7. Entregables

- `UserTaxProfile::updateLinkedUsersToNewProfile()` reescrito según D1, con el PHPDoc del método actualizado (el de la clase ya dice lo correcto).
- Tests de §6 (9 unit + 1 integración).
- Entrada de `CHANGELOG.md [Unreleased]` bajo `### Fixed`: viejo/nuevo (§5), la limitación de D7, y la receta de D4 con sus límites. Sin cambio en «Ships migrations»: la cabecera de `[Unreleased]` ya dice `no`.
- Sin cambios en `docs/api-surface.md` ni en snapshots de contrato.
- Bloque `governance:` del MR: `change_classification: patch`, `public_api: no`, `schema: no`, `persisted_semantics: yes` (el puntero del consumidor cambia de valor), `install: no`.
- **Gates, todos antes de su punto de no retorno:** `pint`, `phpstan` nivel 8, `pest`, harness gateado en local (MariaDB × 2 drivers) y los tres `db-integration` en CI; gate adversarial de implementación antes del merge; gate de conformidad de `clientes` contra el commit candidato antes del tag.

## 8. Adjudicación de la ronda adversarial

### Ronda 1 — gate de implementación sobre `6b4c683` (2026-09-14)

Codex (`codex exec`, sandbox `read-only`, esfuerzo `high`, sesión `01a0a136-94a2-70f2-b380-40eb65ae76bc`, 158.537 tokens). Ejecutó la suite en una copia temporal (1299 ✓ / 15 omitidos) y sus mutaciones allí; el checkout quedó limpio. **Juicio de método: real** — sustituir la elección de un perfil por la pertenencia al conjunto de cerrados corrige la causa. **Petición de cambios** por la receta. Cada hallazgo se midió contra el árbol antes de aceptarse.

| # | Hallazgo (ubicación citada) | Medición propia | Decisión y dónde aterriza |
|---|---|---|---|
| F1 · P2 | La reparación escribe hacia un destino que deja de ser válido tras el diagnóstico: el CAS protege el puntero, no el destino (`CHANGELOG.md:148`, contradice `:106`) | **Confirmado.** Test 9 ampliado con un owner cuyo único activo se borra entre diagnóstico y reparación: rojo con la receta de `6b4c683` (el puntero pasó a `9`, el perfil borrado) | **Aceptado.** La receta revisa el destino al escribir; D4 y CHANGELOG declaran que la ventana se estrecha y no se cierra. Test 9 |
| F2 · P2 cobertura | El test de perfiles borrados termina en el retorno temprano y no prueba el scope del `UPDATE` (`UserTaxProfileRelinkTest.php:139`); mutación `whereIn(..., $closedProfiles->withTrashed())` pasa los 9 tests | **Confirmado** con esa mutación exacta: 9/9 verdes antes del refuerzo | **Aceptado.** Test 6 con un cerrado borrado **y** otro visible; la mutación pasa a rojo |
| F3 · P2 cobertura | «Sin eventos» no prueba «una escritura»: un bucle de `saveQuietly()` pasa (`:165`) | **Confirmado** con esa mutación exacta | **Aceptado.** Test 7bis cuenta los `UPDATE` sobre usuarios: exactamente 1 con dos usuarios. La mutación pasa a rojo |
| F4 · P3 cobertura | El test de la receta protege su copia, no el texto publicado: quitar el CAS solo del CHANGELOG deja todo verde (`:222`) | **Confirmado** con esa mutación exacta | **Aceptado.** Test 10 nuevo: compara el bloque del CHANGELOG con el código ejecutado. La mutación pasa a rojo |
| S1 · sospecha | La subconsulta incrustada ata el `UPDATE` de usuarios a la conexión del modelo fiscal; el código anterior eran dos consultas independientes (`vendor/.../Query/Builder.php:447`) | Verificado por lectura: `src/` no tiene ninguna consulta que cruce usuarios y tablas fiscales en una sola sentencia (solo relaciones, que hacen consultas separadas). No medible con una sola conexión | **Aceptado como regresión evitable.** `pluck` de ids + `whereIn` con valores; sigue siendo un solo `UPDATE`. D1 |
| S2 · sospecha | La prosa generaliza («consumer screens usually…») y «mismo día» debe ser la fecha efectiva (`CHANGELOG.md:39`, `UserTaxProfile.php:225`) | Confirmado: el cierre depende de `valid_from`, no de `created_at` | **Aceptado.** CHANGELOG habla de `valid_from` en la misma fecha y cita solo el caso medido del consumidor de referencia |
| V6–V8 · verificados sin defecto | `UPDATE` con subconsulta sobre otra tabla válido en MySQL 8.4/9 y MariaDB; tipos bigint; `updated_at`, scopes y SoftDeletes del modelo de usuario conservados; `lazyById()` con UUID correcto; resolución de modelo con paridad | — | Sin cambio. Los límites del modelo de usuario (usuario soft-deleted no relinkado; tabla sin `updated_at` con timestamps activos falla) **ya existían** en `cc00b24` y siguen igual |

**El veredicto caduca con el artefacto.** Estas correcciones cambian código de producción (`pluck`) y la receta; la ronda 1 es evidencia sobre `6b4c683`, no sobre la revisión corregida.

### Ronda 2 — auditoría de las correcciones, sobre `a632cd9` (2026-09-15)

Misma sesión de Codex retomada con `sandbox_mode="read-only"` fijado de nuevo. Ejecutó la suite en copia temporal (1300 ✓ / 15 omitidos), 18 mutaciones con hash de aplicación y 8 sondas; el checkout quedó limpio. **Juicio de método: las correcciones atacan causas reales**, con cambios solicitados. Cada hallazgo medido contra el árbol:

| # | Hallazgo (ubicación citada) | Medición propia | Decisión y dónde aterriza |
|---|---|---|---|
| R2-1 · P2 nuevo | `pluck` + `whereIn` enlaza un parámetro por id cerrado: con suficientes perfiles cerrados, crear el siguiente perfil falla (`Query/Builder.php:1496`); medido en SQLite: 32.764 cerrados pasan, 32.765 dan `too many SQL variables` | Aceptado sin repetir la sonda de 32.765 filas: la aritmética es directa (N + 2 parámetros) y el revisor la ejecutó a través del hook real. Los límites de MySQL/MariaDB proceden de sus fuentes, no de ejecución | **Aceptado como techo declarado, no partido en trozos.** D1 (justificación), docblock del hook y CHANGELOG. Motivo: el recuento real por owner está en unidades |
| R2-2 · P2 | El comparador ignoraba toda línea `use`: un import con alias en el CHANGELOG pasaba y la receta publicada consultaba otro modelo (`test:328`) | **Confirmado** con esa mutación exacta | **Aceptado.** Cada `use` publicado debe existir literalmente entre los imports del test. Test 10 |
| R2-3 · S1 pendiente | La receta seguía incrustando la tabla fiscal en la consulta de usuarios y dependía de `currentTaxProfile()`; con dos conexiones SQLite falla con `no such table` (`CHANGELOG.md:120`). El spec afirmaba que no era medible en local | **Confirmado** (mutante M15): mismo error en la conexión de usuarios | **Aceptado.** Receta sin subconsulta ni relación; tests 9 y 9bis con usuarios en otra conexión; retirada la frase falsa del spec |
| R2-4 · P3 | La explicación de fechas seguía mal: el cierre depende del `valid_from` del **sucesor**, no del propio (`UserTaxProfile.php:225`); contraejemplo ejecutado | Confirmado por lectura de `closeActiveForOwner()` | **Aceptado.** CHANGELOG, docblock del hook y cabecera del test hablan de creaciones sucesivas que toman efecto en la misma fecha |
| R2-5 · cobertura | F1: una revisión del destino reducida a `count() !== 1` o a `isEmpty()` pasaba los tests | **Confirmado** con ambas variantes | **Aceptado.** Test 9 añade destino sustituido y destino acompañado. Mutantes M13 y M14 en rojo |
| R2-6 · cobertura | F3: una segunda escritura precedida de un comentario SQL escapaba al regex anclado en `^update` (`test:187`) | **Confirmado** con variante bien formada (M5b) | **Aceptado.** Regex sin anclar, contando todas las coincidencias. Test 7bis |
| R2-7 · menor | El comparador exigía una versión posterior en el CHANGELOG para cerrar la sección | Confirmado por lectura | **Aceptado.** Cierre por fin de fichero si no hay entrada siguiente. Test 10 |
| Descartado | Comparación estricta de ids `int`/`string` que haría saltar todas las reparaciones en un motor real | El revisor lo descartó forzando `ATTR_STRINGIFY_FETCHES`: Eloquent castea la clave incremental a `int` (`HasAttributes.php:1713`) | Sin cambio |

**Convergencia.** La ronda 1 encontró un defecto real en la receta y huecos de cobertura. La ronda 2 no encontró defectos en el hook: un techo que había que declarar, una dependencia de conexión que quedaba en la receta y huecos de cobertura en las pruebas de las propias correcciones. El código de producción del hook no cambia en esta ronda, solo su docblock.
