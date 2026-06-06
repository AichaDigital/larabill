# ADR-007: El `.php.stub` es artefacto derivado del `.php`, no fuente editable

> **Status**: Accepted
> **Date**: 2026-06-06
> **Relates**: refuerza el contrato de migraciones de `CONTRIBUTING.md`/`AGENTS.md`. Complementa ADR-006 (UUID-first) y STD-001 (umbrella). No supersede ningún ADR.

## Contexto

Cada tabla del paquete se distribuye como **dos** ficheros (patrón del esqueleto Spatie):

- `database/migrations/YYYY_MM_DD_HHMMSS_<name>.php` — timestamped, auto-cargado en dev/tests vía `loadMigrationsFrom()`.
- `database/migrations/<name>.php.stub` — publicado a la app consumidora por `larabill:install` en producción.

El contrato documentado decía que ambos debían tener **"same content"**, mantenido a mano. El 2026-06-05, al normalizar los stubs durante el fix de v0.8.3, se descubrió que **6 tablas core tenían el `.php` estructuralmente distinto de su `.php.stub`**:

| Tabla | `.php` (dev/tests) — coincide con modelo+casts | `.php.stub` (prod) — fósil |
|---|---|---|
| `invoice_items` | `total_tax_amount` + `taxes_applied` (snapshot JSON multi-impuesto, v0.3.3) | `tax_rate`+`tax_category_id`+`tax_amount` (diseño único previo) + comentario `binary(16)` obsoleto |
| `tax_rates` | `name`,`rate`(int base-100),`region`,`type`(enum),softDeletes | `country_code`,`tax_type`,`rate decimal(5,4)`… (≈ esquema de `country_vat_rates`; `decimal` **viola base-100**) |
| `company_fiscal_configs` | crea `company_fiscal_configs` (identidad fiscal + temporalidad de la config fiscal, ADR-003) | crea **`fiscal_settings`** → tabla que la migración 027 `drop_fiscal_settings` **borra** |
| `company_template_settings` | `tinyint`+enums + `client_id` uuid | `string(50)`/`string(100)` |
| `invoices` | `billable_user_id` **con índice** | sin índice + comentario con timestamp incorrecto |
| `article_prices` | + 1 línea de comentario | sin comentario (trivial) |

Consecuencia: **los ~933 tests validan un esquema que NO es el que se instala en producción.** Dos divergencias eran bugs graves de instalación: `company_fiscal_configs.stub` dejaría al consumidor sin la tabla que su modelo necesita (y con una `fiscal_settings` muerta), y `tax_rates.stub` instalaría `rate` como `decimal`, rompiendo el invariante base-100 que asume todo el código.

`lara-verifactu` (paquete hermano) es **agnóstico**: consume `InvoiceContract`/`InvoiceBreakdownContract`, nunca columnas de larabill. No impone restricción externa sobre el esquema, lo que deja la decisión enteramente del lado del modelo + tests de larabill.

### Causa raíz

El `.php` se **ejecuta** en cada `composer test` (SQLite) y en `tests/Integration/Mysql/FreshInstallTest` (MySQL real, vía `artisan migrate` que carga las migraciones del ServiceProvider). El `.php.stub` **no se ejecuta nunca en CI** — ni SQLite ni MySQL lo tocan; solo se materializa en una instalación real de consumidor, que ningún test corre. Un artefacto que nunca se ejecuta, mantenido a mano en paralelo al que sí se ejecuta, deriva de forma inevitable.

## Decisión

1. **El `.php` timestamped es la única fuente de verdad editable del esquema.**
2. **El `.php.stub` es un artefacto derivado.** No se edita a mano. Se regenera desde su `.php` con el script interno `bin/sync-migration-stubs`.
3. **Reconciliación de las 6:** cada `.stub` se sobrescribe con el contenido de su `.php`. Los `.php` **no cambian de esquema** (ya son la verdad validada en MySQL); solo se limpian comentarios que referencian nombres timestamped de otras migraciones, para que la derivación quede limpia.
4. **Guardrail estricto (byte-exacto):** el guardrail (`MigrationOrderConsistencyTest`) compara cada `.php.stub` **byte a byte** con su `.php` — sin normalizar `strict_types` ni líneas en blanco. `LARABILL_KNOWN_SCHEMA_DIVERGENCES = []` queda **vacío** (no una lista congelada de excepciones toleradas). Cualquier diferencia, por trivial que sea, rompe CI. El mensaje de fallo indica exactamente: *run `bin/sync-migration-stubs`*. La igualdad byte-a-byte es deliberada: "igual salvo normalización" reabriría la ambigüedad que esta ADR cierra.
5. **Verificación por transitividad (de contenido):** `stub ≡ php` (guardrail) **+** `php` validado en MySQL (`FreshInstallTest`) prueba que **el contenido de esquema del stub es correcto**. No prueba el resto del flujo de producción — orden de publicación, timestamp del fichero destino, paths del install command, ni que una app consumidora migre realmente desde los stubs. Esa validación end-to-end queda como **follow-up** (ver No-objetivos).

### Dos clases de cambio en la reconciliación inicial

La regeneración byte-exacta de esta PR produce **dos** clases de cambio, que se mantienen conceptualmente separadas (en la descripción del PR y en review con `git diff --ignore-blank-lines`):

- **6 reconciliaciones estructurales de esquema** — las de la tabla de arriba. Son el fix funcional real (y las únicas mencionadas en el CHANGELOG).
- **10 normalizaciones whitespace-only** — stubs que ya estaban estructuralmente en sync pero diferían de su `.php` en una línea en blanco final sobrante (`create_country_vat_rates_table`, `create_eu_sales_thresholds_table`, `create_invoice_series_control_table`, `create_invoice_templates_table`, `create_roi_queries_table`, `create_tax_categories_table`, `create_unit_measures_table`, `create_user_roi_verifications_table`, `create_user_tax_infos_table`, `create_vat_categories_table`). No cambian esquema; son ruido inocuo del generador, aceptado **una vez** para garantizar idempotencia del script y del guardrail byte-exacto.

### Mecanismo: `bin/sync-migration-stubs`

Script PHP CLI plano (no comando Artisan, no toca runtime ni ServiceProvider, código y comentarios en inglés). Para cada `.php` timestamped que tenga un `.php.stub` asociado, reescribe el `.stub` con el contenido del `.php`. Flujo de desarrollo:

```text
1. editas SOLO el .php de la migración
2. php bin/sync-migration-stubs   (o: bin/sync-migration-stubs)
3. commit (.php + .stub regenerado)
```

## Lo que NO cambia

- **Sigue habiendo `.php` Y `.php.stub`** en el repo. No se elimina el `.stub` (eso cambiaría el contrato de `larabill:install` y las expectativas del esqueleto Spatie). El `.stub` pasa de "fuente paralela a mano" a "artefacto generado".
- `LarabillInstallCommand::$migrationOrder` sigue mapeando 1:1 a los `.php.stub`.
- Los **2 stubs consumer-only** (`add_user_relationships_to_users_table.php.stub`, `rename_user_id_to_owner_user_id_in_user_tax_profiles.php.stub`) **no derivan de ningún `.php`** (modifican la tabla `users` del consumidor). Siguen siendo fuente editable a mano; el script los ignora.
- ADR-006 (UUID-first), que consolida el estándar UUID v7 char(36), la inmutabilidad de facturas y los snapshots fiscales no se ven afectados.

## No-objetivos / Follow-up

- **Test que publique y migre los stubs reales en MySQL** (ejecutar el artefacto de producción directamente, no por transitividad): **follow-up explícito**, fuera de scope de esta PR. La transitividad (decisión §5) es suficiente mientras `stub ≡ php` esté garantizado por el guardrail.
- **Eliminar la dualidad `.php`/`.stub`** generando el contenido publicado on-the-fly desde el `.php`: descartado ahora — cambiaría el install command, la documentación y el contrato mental de los agentes externos (Codex/Cursor).
- **Auditar el resto del stack** (`laratickets`, `lara-content`, etc., mismo patrón Spatie `.php`/`.stub`): follow-up en **sesión propia por paquete** (regla "una sesión por paquete" de STD-001). El guardrail de larabill es plantilla replicable.
- **Deuda colateral anotada (no se arregla aquí):** `InvoiceItem::$fillable` incluye `tax_group_id`, columna que no existe en ninguna migración (`.php` ni `.stub`) → fillable huérfano, mass-assignment silenciosamente ignorado. Deuda separada.

## Consecuencias

**Positivas:**
- Divergencia `.php`/`.stub` imposible por construcción (guardrail estricto, sin excepciones para los stubs que tienen `.php` asociado).
- El artefacto de producción deja de pudrirse: lo que se instala es, byte a byte, lo que validan los tests.
- Mensaje inequívoco a los agentes: el `.stub` no se edita; se regenera.
- Cierra dos bugs de instalación reales (`fiscal_settings`, `rate decimal`).

**Negativas:**
- El desarrollador debe ejecutar `bin/sync-migration-stubs` tras tocar un `.php`. Mitigado: el guardrail falla con el comando exacto a ejecutar.

## Referencias

- **Input / orden de trabajo:** `docs/2026-06-05-blocker-schema-divergence-php-vs-stub.md` (gitignored).
- **Contrato de migraciones:** `CONTRIBUTING.md`, `AGENTS.md`.
- **Guardrail:** `tests/Unit/Console/MigrationOrderConsistencyTest.php`.
- **UUID-first:** `docs/ADR-006-uuid-first-no-agnostic.md`, STD-001 en `~/development/packages/aichadigital/STANDARDS.md`.
- **Verificación MySQL:** `tests/Integration/Mysql/FreshInstallTest.php`.
