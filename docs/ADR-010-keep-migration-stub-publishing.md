# ADR-010: Mantener el publishing de migraciones (`.php` + `.php.stub`); rechazar el refactor a «0 stubs»

> **Status**: Accepted
> **Date**: 2026-07-02
> **Relates**: confirma y cierra la deuda de divergencia `.php`/`.stub` (ADR-007); no supersede ningún ADR. Issue: AID-302. Precedente contrastado: laratickets AID-290.

## Contexto

AID-302 (roca grande del barrido de consistencia de la umbrella, 2026-07-02) pedía un ADR que decidiera el problema de fondo de la dualidad `.php` (auto-cargado en dev/tests vía `loadMigrationsFrom()`) + `.php.stub` (publicado por `larabill:install` en producción), con tres opciones:

- **(a)** retirar el publishing de migraciones → larabill a **0 stubs**, esquema package-managed (lo que hizo laratickets en AID-290). El issue la marcaba «recomendada» por coherencia con laratickets.
- **(b)** reconciliar las 6 divergencias estructurales conocidas, `LARABILL_KNOWN_SCHEMA_DIVERGENCES` a 0.
- **(fondo)** derivar el `.stub` del `.php` en vez de mantener dos copias a mano.

El issue describía el estado de **v0.8.3**: 6 tablas core (`invoices`, `invoice_items`, `tax_rates`, `article_prices`, `company_fiscal_configs`, `company_template_settings`) con el `.php` estructuralmente distinto de su `.php.stub`, la skip-list congelada con esas 6, y ~933 tests validando un esquema que no era el que se instala en producción.

**Ese estado ya no existe.** Entre v0.8.3 y v0.8.4 se ejecutó ADR-007, que:

- reconcilió las 6 divergencias — opción **(b)**;
- convirtió el `.stub` en artefacto derivado del `.php` vía `bin/sync-migration-stubs` — opción **(fondo)**, en dev-time;
- dejó `LARABILL_KNOWN_SCHEMA_DIVERGENCES = []` vacío y un guardrail **byte-exacto** que impide que la divergencia reaparezca.

Es decir: la **deuda técnica** que motivó AID-302 se cerró hace varias versiones (el paquete va por v3.1.3). Lo único que AID-302 reabre de forma genuina es la **opción (a)** — una decisión arquitectónica que ADR-007 ya había evaluado y **descartado explícitamente** («Eliminar la dualidad `.php`/`.stub`… descartado ahora», ADR-007 §No-objetivos). AID-302 se creó el 2026-07-02, **después** de ADR-007, y recomienda lo contrario apoyándose en el precedente de laratickets.

## Estado verificado (2026-07-02)

- **Barrido de auditoría `.php` ↔ `.stub`: 0 divergencias.** Los 2 stubs consumer-only (`add_user_relationships_to_users_table`, `rename_user_id_to_owner_user_id_in_user_tax_profiles`) no tienen `.php` por diseño (modifican la tabla `users` del consumidor).
- **`LARABILL_KNOWN_SCHEMA_DIVERGENCES = []`** — vacío, no una lista congelada de excepciones toleradas.
- **`tests/Unit/Console/MigrationOrderConsistencyTest.php`**: verde. Exige un `.php.stub` dedicado por cada entrada de `$migrationOrder` y byte-identidad `.php` ↔ `.stub`.
- **`tests/Integration/InstallMysql/InstallCommandSchemaTest.php` (AID-287)**: valida el **path real de producción** en MySQL — `larabill:install` publica los stubs en orden de `$migrationOrder`, `migrate` los corre, y verifica que todas las tablas `create_*` y sus FKs quedan íntegras. Esto **cierra el follow-up** que ADR-007 había dejado abierto («test que publique y migre los stubs reales en MySQL, no por transitividad»).

La cadena de garantía es ahora completa: `stub ≡ php` (guardrail byte-exacto) **+** `php` validado en MySQL (`FreshInstallTest`) **+** `install → stubs → migrate` validado en MySQL (`InstallCommandSchemaTest`).

## Decisión

1. **AID-302 no describe deuda técnica pendiente.** La divergencia que lo originó está cerrada por ADR-007 + el guardrail, y el path de instalación productiva está cubierto por AID-287.
2. **Se mantiene el contrato de ADR-007 para larabill:** el `.php` timestamped es la única fuente editable del esquema, el `.php.stub` es artefacto derivado byte-exacto (`bin/sync-migration-stubs`), y `larabill:install` publica los stubs en el orden de `$migrationOrder`.
3. **Se rechaza/aplaza la opción (a) («0 stubs», esquema package-managed) para el contrato de instalación actual de larabill.** Reconsiderarla solo en una **versión mayor breaking**, con un plan de migración explícito para los consumidores ya instalados.

## Por qué no «0 stubs» en larabill (aunque laratickets sí)

La coherencia con laratickets no basta para justificar un cambio breaking en el núcleo de facturación:

- **El problema urgente ya no existe.** «0 stubs» resolvería una deuda (divergencia dev↔prod) que ADR-007 ya cerró. No queda bug que arreglar; sería un refactor por homogeneidad, no por corrección.
- **larabill es el núcleo maduro (v3.1.3) con contrato de instalación establecido.** `larabill:install`, `$migrationOrder`, `SCHEMA_REQUIREMENTS.md`, `docs/setup-uuid.md` y los tests de instalación en MySQL forman un contrato que los consumidores ya usan. Retirar el publishing cambia cómo un consumidor obtiene y versiona el esquema de facturación — es breaking, con coste real de migración para instalaciones existentes.
- **laratickets pudo hacerlo barato porque nació joven.** Su «0 stubs» (AID-290) era el estado objetivo desde su v1.0, sin base instalada que proteger. larabill no está en esa posición.
- **El coste de mantener los stubs ya está amortizado.** El único coste real de la dualidad (el drift manual) se eliminó por construcción con `bin/sync-migration-stubs` + guardrail byte-exacto. Lo que queda es un artefacto generado, no una segunda fuente mantenida a mano.

## Criterios de reapertura

Reconsiderar «0 stubs» para larabill solo si concurre alguno:

- Se planifica una **versión mayor breaking** por otros motivos, y el cambio de contrato de instalación puede acompañarla con un plan de migración para consumidores.
- El coste de mantener el publishing (stubs, `$migrationOrder`, guardrail, install command, tests de instalación) supera de forma demostrable al de gestionar el esquema como package-managed.
- Un caso de consumidor concreto exige esquema package-managed (p.ej. imposibilidad de publicar/versionar migraciones en su pipeline de despliegue).

## Consecuencias

**Positivas:**

- AID-302 se cierra sin tocar código de runtime ni el contrato de instalación: la decisión queda registrada, no ejecutada como refactor breaking.
- El estándar del paquete queda inequívoco para futuras sesiones y agentes externos: en larabill los stubs **se mantienen** (derivados), no se retiran.
- Se evita un cambio breaking en el núcleo de facturación sin beneficio de corrección.

**Negativas / aceptadas:**

- Divergencia deliberada con laratickets (que sí fue a 0 stubs). Aceptada: los dos paquetes están en fases distintas y la homogeneidad no justifica un breaking aquí.
- El coste de mantenimiento de la dualidad permanece, aunque reducido a «correr `bin/sync-migration-stubs` tras tocar un `.php`», ya garantizado por el guardrail.

## Referencias

- **Issue:** AID-302.
- **Deuda cerrada por:** `docs/ADR-007-stub-derived-from-php.md` (`.php` fuente / `.stub` derivado, guardrail byte-exacto).
- **Guardrail:** `tests/Unit/Console/MigrationOrderConsistencyTest.php` (`LARABILL_KNOWN_SCHEMA_DIVERGENCES = []`).
- **Path de instalación productiva en MySQL:** `tests/Integration/InstallMysql/InstallCommandSchemaTest.php` (AID-287).
- **Precedente contrastado («0 stubs»):** laratickets AID-290.
- **Contrato de migraciones:** `CONTRIBUTING.md`, `AGENTS.md`.
