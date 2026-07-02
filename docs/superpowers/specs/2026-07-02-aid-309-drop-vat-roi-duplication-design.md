# AID-309 — Drop the duplicated VAT/ROI verification layer (design)

> **Status**: Approved (brainstorming) — pending spec review
> **Date**: 2026-07-02
> **Issue**: AID-309
> **Relates**: lararoi (owns the VAT/ROI domain), AID-301/AID-263 (lararoi stabilization). Orthogonal to AID-302/ADR-010 (migration `.php`/`.stub` model).

## Goal

`lararoi` already owns the domain contract for intra-community VAT/NIF (ROI) verification. larabill duplicated that contract with its own models, services and tables — and that duplicate is, in fact, **dead code**: nothing in larabill's live billing flow calls it. AID-309 makes larabill **stop duplicating the contract and accept the boundary**: larabill becomes pure billing, receives `is_roi_taxed` as an input, and leaves verification to the consuming application (which uses `lararoi` in its own layer).

This is not "extract to lararoi" nor "convert lararoi" — lararoi already exists and is the source of truth. larabill retreats.

## Context / findings (verified 2026-07-02)

- **Dead dependency.** `composer.json` requires `aichadigital/lararoi: ^0.5` but larabill imports **nothing** from the `Aichadigital\Lararoi` namespace (`grep` → 0 hits).
- **Duplicated, disconnected layer.** larabill ships its own `VatVerificationService` (multi-provider + fallback + rate-limit + cache), `VatApiIntegrationService`, `RoiVerificationService`, and models `VatVerification` / `UserRoiVerification` / `RoiQuery`. This reimplements what `lararoi`'s `VatProviderManager` + `VatVerificationService` already do.
- **The layer is dead.** No live `src` code invokes it:
  - `RoiVerificationService` is injected into `BillingService` but **never called** (only self-used + its own tests).
  - `VatVerificationService::verifyVatNumber` is called **only** from `RoiVerificationService`.
  - `VatApiIntegrationService` is used **only** by `VatVerificationService`.
  - Real reverse-charge is decided by the **`is_roi_taxed` flag** on the invoice — an input the consumer sets, read by `Invoice::isReverseCharge()` and `EuSalesThresholdService`. It never calls the verification layer.
- **Table collision (latent).** Both larabill and lararoi create a table named `vat_verifications`. It doesn't explode today only because larabill never loads lararoi's migrations. Removing larabill's table removes the collision.
- **No external consumers.** No sibling package (`lara-verifactu`, `laratickets`, `lara-content`, `lara-privacy*`) references larabill's VAT/ROI classes.
- **Not part of the legal-retention contract.** None of the 3 models implement `LegallyRetainable` (ADR-008 lives in `Invoice`/`UserTaxProfile`). Deleting `RoiQuery` does not touch that contract.

## Decision

**Option A (chosen):** larabill keeps **zero** VAT/ROI verification. It receives `is_roi_taxed` as input, persists no verifications, and **drops the `lararoi` dependency**. Verification, if needed, is the consuming application's responsibility via `lararoi`.

Rejected: a thin convenience wrapper over `lararoi` in larabill (option B) — it would re-couple larabill to something it does not need, since there is no live consumption point.

## Scope

### Delete (dead code, no live consumers)

**Models (3):**
- `src/Models/VatVerification.php`
- `src/Models/RoiQuery.php`
- `src/Models/UserRoiVerification.php`

**Services (3):**
- `src/Services/VatVerificationService.php`
- `src/Services/RoiVerificationService.php`
- `src/Services/VatApiIntegrationService.php`

**Migrations + stubs (3 tables × 2 files):**
- `database/migrations/2024_12_01_000007_create_vat_verifications_table.php` + `create_vat_verifications_table.php.stub`
- `database/migrations/2026_02_16_000004_create_roi_queries_table.php` + `create_roi_queries_table.php.stub`
- `database/migrations/2026_02_16_000005_create_user_roi_verifications_table.php` + `create_user_roi_verifications_table.php.stub`

**Tests (~11):**
- `tests/Unit/VatVerificationTest.php`
- `tests/Unit/Models/VatVerificationTest.php`
- `tests/Unit/Models/UserRoiVerificationTest.php`
- `tests/Unit/Models/RoiQueryTest.php`
- `tests/Unit/Services/RoiVerificationServiceTest.php`
- `tests/Unit/Services/VatVerificationServiceFallbackTest.php`
- `tests/Unit/Services/VatVerificationServiceApiFailureTest.php`
- `tests/Unit/Services/VatVerificationServiceRateLimitTest.php`
- `tests/Unit/Services/VatApiIntegrationServiceTest.php`
- `tests/Integration/VatVerificationIntegrationTest.php`
- `tests/Models/CustomVatVerification.php` (fixture)

### Modify

- **`composer.json`** — remove `require aichadigital/lararoi: ^0.5`; edit `description` to drop "VAT verification".
- **`src/Console/LarabillInstallCommand.php`** — remove the 3 `$migrationOrder` entries (`020` vat_verifications, `024` roi_queries, `025` user_roi_verifications). Leave the numbering gaps (the consistency test does not require contiguous keys); do not renumber. The remaining entries stay 1:1 with the stubs.
- **`src/Services/ModelMappingService.php`** — remove `use VatVerification` + the `'vat_verification' => VatVerification::class` mapping.
- **`src/Services/BillingService.php`** — remove the dead `RoiVerificationService` constructor injection + property.
- **`src/Services/CacheService.php`** — remove the 7 now-orphaned ROI methods (`storeRoiVerification`, `getRoiVerification`, `hasRoiVerification`, `removeRoiVerification`, `getRoiVerificationKey`, `countRoiVerifications`, `flushRoiVerificationCache`). Keep generic cache, VAT-rate and company-config methods.
- **`tests/Unit/Services/CacheServiceTest.php`** — drop the ROI-cache assertions; keep generic + VAT rates + company config.
- **`config/larabill.php`** — remove `models.vat_verification`, `field_mappings.vat_verification`, and the `vat_apis` section.
- **`tests/Pest.php`** — remove `Integration/VatVerificationIntegrationTest.php` from the `->in(...)` list (line ~43).
- **`tests/Integration/InstallMysql/InstallCommandSchemaTest.php`** — remove `vat_verifications`, `roi_queries`, `user_roi_verifications` from the expected-tables list.
- **`CLAUDE.md`** (local, line ~10) — reframe the "verificación VAT vía lararoi" line: larabill does **no** VAT verification; it is the consumer's responsibility via lararoi.
- **`README.md`** — remove the `### VAT Verification` section (usage example of the deleted `VatVerificationService`, ~line 209) and drop "comprehensive VAT verification" from the intro (~line 16).
- **`SCHEMA_REQUIREMENTS.md`** — remove the `vat_verifications`, `roi_queries`, `user_roi_verifications` rows from the schema and migration-order tables.
- **`CHANGELOG.md`** — `[Unreleased]` → **BREAKING CHANGES** section describing the removal (see Versioning).

### Do NOT touch

`is_roi_taxed` (the real reverse-charge input), `Invoice`, `EuSalesThresholdService`, `DestinationVatService`, `CountryVatRate`, `VatCategory`, and `CacheService`'s generic methods. `CacheService` itself stays (used by `DestinationVatService`).

## Versioning & breaking change

Removing public models, services, tables, config keys and a dependency is **breaking → v4.0.0**.

- **No `down`/drop migration is generated.** A consumer that already installed v3.1.x keeps its (empty, inert) `vat_verifications` / `roi_queries` / `user_roi_verifications` tables; their data is not touched. They may drop them manually if desired.
- CHANGELOG documents: verification is no longer provided by larabill; consumers who need intra-community VAT verification should use `lararoi` directly.
- If a real consumer of v3.1.3 exists that relied on these tables, the major bump + breaking note is the contract signal.

## Out of scope (follow-ups)

- **Umbrella `CLAUDE.md`** ("larabill usa lararoi" — will be false) → separate follow-up, not in this larabill-scoped PR.

## Verification / testing

- `tests/Unit/Console/MigrationOrderConsistencyTest.php` green — `$migrationOrder` and stubs stay 1:1 (3 fewer each).
- `tests/Integration/InstallMysql/InstallCommandSchemaTest.php` green — expected-tables list matches the reduced schema.
- Full Pest suite green after removing the layer's tests (nothing live depended on it).
- `composer quality` green (Pint + PHPStan level 8 + tests). No new PHPStan baseline entries.

## Risks

- **Breaking removal of published migrations** — mitigated by the v4.0.0 major bump + BREAKING CHANGES note, and by not dropping consumer data (no down migration). Aligned with larabill's dev-main posture (no upgrade promised across majors).
- **Missed reference** — mitigated by the verified blast-radius sweep (0 external consumers, no live `src` caller). CI (SQLite + MySQL matrix) is the backstop.
