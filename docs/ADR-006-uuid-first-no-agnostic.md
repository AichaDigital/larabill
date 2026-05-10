# ADR-006: UUID-first, retirada del agnosticismo de tipo de PK

> **Status**: Accepted
> **Date**: 2026-05-09
> **Supersedes**: La pretensión agnostic `int|uuid|ulid` en `larabill.user_id_type`. ADR-002 (UUID v7 string char 36) sigue vigente y se refuerza.

## Contexto

Hasta v0.7.4 larabill ofrecía soporte público para tres tipos de PK en el `users` de la app consumidora: `int` (bigInt Laravel default), `uuid` (char 36, recomendado) y `ulid` (char 26). El mecanismo: `MigrationHelper::userIdColumn()` + config `larabill.user_id_type` + tests de integración MySQL que demostraban el contrato para los tres.

Esa pretensión nunca se exigió a sí misma una justificación de negocio. Se asumió que "ser agnóstico es mejor". Tras 2-3 meses de uso real (un único consumidor, `~/SitesLR12/clientes`, que usa UUID v7) y una revisión adversarial cruzada (Claude Opus 4.7 + OpenAI Codex), la conclusión fue que el agnosticismo:

- No tiene cliente real que lo justifique. El único consumidor es UUID v7.
- Cuesta superficie de config, tests, docs, CI y disciplina cross-package.
- Genera riesgo de drift entre paquetes del umbrella AichaDigital (cada paquete con su `user_id_type`, mismatch silencioso entre instalaciones).
- Se usa para vender una promesa ("funciona con cualquier `users.id`") que nunca se ejercita en producción.
- Dispersa el mensaje de producto: "facturación fiscal con UUID v7" es más claro que "facturación fiscal agnóstica al tipo de PK".

larabill no es un paquete de utilidades genérico tipo Spatie. Es un núcleo de facturación fiscal serio con cumplimiento VeriFACTU para un ecosistema concreto (AichaDigital). Aquí ser opinionated es ventaja, no defecto.

## Decisión

**larabill adopta UUID v7 string char(36) como contrato único y público para el tipo de PK del `users` de la app consumidora.** Pre-v1.0.

### Cambios concretos

1. **Config:** `larabill.user_id_type` se elimina del config publicado. La opción ENV `LARABILL_USER_ID_TYPE` deja de leerse.
2. **Helper:** `MigrationHelper::userIdColumn()` se simplifica a emisión UUID exclusiva. Ramas `int`, `ulid` y `auto-detect` desaparecen del código de producción.
3. **Install command:** `larabill:install` ejecuta un preflight check sobre `users.id`. Si la columna no es `char(36)` compatible UUID, aborta con mensaje accionable apuntando a `docs/setup-uuid.md`. No se ofrece flag `--user-id-type=`.
4. **Tests:** la suite `tests/Integration/Mysql/FreshInstallUserIdTypeTest` se reduce a un único caso UUID. Se elimina la matriz `->with(['int','uuid','ulid'])`.
5. **Docs:** README, SCHEMA_REQUIREMENTS, CONTRIBUTING y CRITICAL_RULES eliminan toda mención a `int`/`ulid`/`agnostic`. El mensaje pasa a ser **"UUID-first billing package"**.
6. **CHANGELOG:** entrada explícita en próxima release marcando el cambio como **breaking** respecto a la promesa pública anterior. Dado que `dev-main` no garantiza upgrade, no se ofrece migración automática.

### Lo que NO cambia

- ADR-002 (UUID v7 string char 36, no binario) sigue siendo el estándar y se refuerza.
- `larabill.user_model` sigue siendo configurable. El consumidor puede tener su propio modelo `User`, solo debe garantizar `id` UUID.
- Inmutabilidad de facturas, snapshots fiscales, ADR-003 (unificación user/customer) y ADR-004/005 (precios) no se ven afectados.
- El paquete sigue requiriendo MySQL 8+ como base demostrada (la suite SQLite es complementaria, no contrato).

## No-objetivos explícitos

Para no caer en interpretaciones futuras erróneas, dejamos por escrito qué decisiones quedan **fuera de scope** con esta ADR:

- **Soporte bigint**: fuera de scope. No es feature pendiente. No se mantiene rama latente en código "por si acaso".
- **Soporte ULID**: fuera de scope. ULID era una opción de paridad técnica con UUID, sin demanda real.
- **Migración entre tipos de PK post-instalación**: explícitamente no soportada. Una app instala con UUID y se queda con UUID.
- **Polimorfismo de relación** (morphs en `customer_id`/`owner_user_id`): no se introduce. El receptor de una factura es siempre un User. ADR-003 lo cierra desde el modelo de dominio.

## Criterios de reapertura

Esta decisión se revisa **únicamente** si concurren los tres siguientes:

1. Aparece un cliente concreto con presupuesto que requiere bigint o ULID, no una hipótesis.
2. El caso de uso justifica el coste (test matrix, helper, config, docs, CI, mantenimiento permanente del path adicional).
3. La reintroducción se planifica como **major nueva** del paquete, con ADR propio que supersede esta.

No se acepta reapertura por:

- "Sería bonito tener compatibility broader."
- "Otro paquete del ecosistema lo reintrodujo."
- "Un evaluador potencial dijo que sería mejor."
- Refactor interno ad-hoc sin ADR.

## Alcance umbrella

Esta decisión codifica el estándar **AichaDigital UUID-first** que aplica al ecosistema entero, no solo a larabill. Ver `~/development/packages/aichadigital/STANDARDS.md` (working note no versionado) y los ADRs cortos de los paquetes hermanos (`laratickets`, `lara-content`, eventualmente `lara-verifactu` si tras inspección requiere FK directa a User).

larabill es la implementación de referencia. Los demás paquetes referencian este ADR como rationale canónico, sin duplicarlo.

## Consecuencias

### Positivas

- **Mensaje de producto claro:** "facturación fiscal serio sobre UUID v7".
- **Eliminación de superficie:** menos config, menos branches en helper, menos docs, menos tests irrelevantes.
- **Cero drift cross-package:** todos los paquetes del umbrella asumen UUID, no pueden desincronizarse.
- **Selección natural de mercado:** quien evalúa larabill descubre el requisito en `composer require` + preflight, no tras 2h depurando un FK constraint failure.
- **CI más rápido:** la matriz `int|uuid|ulid` deja de correr.
- **Disciplina interna:** no hay tentación de "preservar opcionalidad por si acaso" que codex correctamente identificó como liability sin cliente.

### Negativas

- **Pérdida de mercado teórico:** apps Laravel default con bigint quedan fuera. En 2-3 meses de paquete vivo, ese mercado nunca se materializó.
- **Onboarding requiere lectura de doc:** una app fresh debe seguir `docs/setup-uuid.md` antes de instalar. Mitigado con preflight check + post-install message.
- **Reintroducir bigint cuesta más en el futuro** que mantenerlo hoy: hay que reconstruir tests, redescubrir edge cases. Aceptado como tradeoff consciente.

### Neutras

- `dev-main` no prometía upgrade desde versión previa, así que el paquete no asume deuda de migración. El cambio aterriza limpio.
- El consumidor actual (`clientes`) no se ve afectado: ya usa UUID.
- `lararoi` (verificación VAT, sin FK a User) y `lara100` (cast money, sin FK) no necesitan ADR equivalente.

## Validación

- Demostrado en suite `tests/Integration/Mysql/` reducida a UUID, contra MySQL 8 real.
- Preflight check probado contra `users` con bigint (aborta), char(36) (procede) y char(26) ULID (aborta con mensaje claro).
- README actualizado refleja el contrato.
- Entry en CHANGELOG marca breaking change.

## Referencias

- ADR-002: UUID v7 string (eliminación de uuid_binary) — sigue vigente.
- ADR-003: Unificación de Users y Customers — cierra polimorfismo de receptor.
- `~/development/packages/aichadigital/STANDARDS.md` (working note umbrella).
- `docs/setup-uuid.md` — guía de setup para apps consumidoras.
- Sesión adversarial Claude Opus 4.7 + Codex (2026-05-09) — registro de la revisión que motivó esta ADR.
