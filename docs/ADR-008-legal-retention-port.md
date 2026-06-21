# ADR-008: Puerto de retención legal — larabill implementa `LegallyRetainable`, no privacidad operativa

> **Status**: Accepted
> **Date**: 2026-06-21
> **Relates**: documenta la dependencia `aichadigital/lara-privacy-core` introducida en v0.12.0 (AID-221). Complementa ADR-006 (UUID-first). Fuente de diseño: `docs/notes/lara-privacy-retention-contract.md`. No supersede ningún ADR.

## Contexto

larabill posee la verdad fiscal de una factura: su `serie`, su `invoice_date`, el ejercicio contable, la distinción proforma vs. factura fiscal. De esos datos se deriva una obligación legal concreta: **hasta qué fecha un registro fiscal no puede tocarse** (Código de Comercio art. 30 — 6 años contables; bases legales completas en `docs/notes/lara-privacy-retention-contract.md`).

El borrado, la anonimización, las SAR, el consentimiento y el *pruning* programado son **privacidad operativa**: pertenecen al software final (que decide si instala y ejecuta una capa de privacidad) o al paquete `aichadigital/lara-privacy`. Pero esa capa **no puede calcular** la fecha de retención de una factura sin recrear una regla fiscal delicada. Si cada app consumidora reimplementa "fin de ejercicio de `invoice_date` + N años, salvo proforma", el resultado es drift garantizado.

`aichadigital/lara-privacy-core` resuelve esto con un **contrato mínimo**: una interface (`LegallyRetainable`, único método `retainedUntil(): ?DateTimeInterface`) y una decisión pura (`CheckLegalHold`, resuelve "¿bajo retención ahora?" con `now` inyectado). A fecha de esta ADR el paquete son dos ficheros, `require: php ^8.3` y nada más: sin Eloquent, sin ServiceProvider, sin migraciones, sin efectos secundarios.

## Decisión

larabill implementa `AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable` en `Invoice` y `UserTaxProfile`, exponiendo un **puerto de retención legal**:

- `Invoice::retainedUntil()` — factura fiscal (`serie->isFiscal()`) → fin de ejercicio de `invoice_date` + `larabill.retention.fiscal_years` (default 6); PROFORMA o factura sin fecha → `null`.
- `UserTaxProfile::retainedUntil()` — `MAX()` sobre las facturas que lo referencian; se computa aun con el perfil soft-deleted; perfil nunca facturado → `null`.

**Razón:** larabill es el único que entiende bien los datos que entran en `retainedUntil()` (serie, `invoice_date`, ejercicio fiscal, proforma vs. factura fiscal). larabill es **un adapter** del contrato; el cálculo del *gate* (`isUnderRetention`) lo hace `CheckLegalHold` del core. La dependencia apunta a una **abstracción mínima de retención legal**, no a un framework de privacidad.

## No-decisión (lo que larabill NO hace)

larabill **no** ejecuta `erasure`, `anonymisation`, SAR, `consent`, `pruning jobs` ni ninguna política RGPD operativa. Solo declara *"este registro fiscal no puede tocarse hasta esta fecha"*. Quién respeta esa señal, cuándo borra y cómo anonimiza es responsabilidad del software final / `lara-privacy`, nunca de larabill.

## Invariantes (lo que mantiene sana esta dependencia)

1. **`LegallyRetainable` se queda en un único método**: `retainedUntil(): ?DateTimeInterface`. Si la interface crece un segundo método, larabill pasa a implementar algo que ya no es solo retención.
2. **larabill depende solo de la superficie de retención**, nunca de `lara-privacy` completo (jobs, migraciones, providers, anonimización).
3. **Los contratos nuevos de privacidad no son obligatorios para larabill**: cualquier interface adicional (consent, PII, anonymization strategy) es separada y larabill es libre de no implementarla (ISP).
4. **La semver del core se gobierna por la estabilidad del contrato**, no por el roadmap de `lara-privacy`. Romper `LegallyRetainable` (→ 2.0) debe ser un evento de años; añadir contratos no relacionados no debe forzar un major que larabill tenga que seguir.

## Riesgo futuro

Si `lara-privacy-core` deja de ser *retention-only* (empieza a acumular contratos o lógica de privacidad), el disparador es **extraer `lara-retention-contracts`** (o fijar un namespace `Retention` estable como única superficie que larabill consume). El nombre `*-privacy-core` es hoy aceptable porque el paquete es solo contrato + gate; deja de serlo en cuanto la superficie de privacidad crece alrededor del puerto de retención.

## Jurisdicción

Hoy es configurable la **duración** (`larabill.retention.fiscal_years`, env `LARABILL_RETENTION_FISCAL_YEARS`), **no el ancla** (fin de ejercicio de `invoice_date`, de forma española: ejercicio = año natural). Una futura multi-jurisdicción que ancle distinto (fin de periodo fiscal ≠ año natural, fecha de presentación…) debe añadir una **estrategia interna en larabill** — el ancla sigue siendo del puerto —, **no** desplazar la regla a la app final. La app puede configurar plazos; no inventa el ancla legal de una factura.

## Consecuencias

- larabill gana una dependencia de runtime (`lara-privacy-core ^1.0`) de coste mínimo (contrato puro) y semver glacial.
- La verdad fiscal de retención vive en un solo sitio; ninguna app consumidora la reimplementa.
- Una sesión futura que vea `larabill → *-privacy-core` tiene aquí la justificación: es un puerto de retención, no privacidad metida en facturación. No arrancar la dependencia; no dejarla crecer hacia `lara-privacy` completo.
