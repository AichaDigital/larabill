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
- **`src/Services/CacheService.php`** — remove the **entire** ROI surface, not only the 7 public methods (`storeRoiVerification`, `getRoiVerification`, `hasRoiVerification`, `removeRoiVerification`, `getRoiVerificationKey`, `countRoiVerifications`, `flushRoiVerificationCache`) but also the internal references: the `'roi_verifications'` key in every `$entryCounts` init/reset (~lines 37, 792, 809), the stats wiring (`countRoiVerifications()` call + `'roi_verifications'` stat, ~lines 716, 721, 730), the `'roi_verification'` pattern-map entry (~line 582), and the ROI example strings in the generic key-type comments (~lines 329, 333, 350). Keep generic cache, VAT-rate and company-config methods. `CacheService` itself stays (used by `DestinationVatService`).
- **`tests/Unit/Services/CacheServiceTest.php`** — drop the ROI-cache assertions; keep generic + VAT rates + company config.
- **`config/larabill.php`** — remove `models.vat_verification` (~line 94), `field_mappings.vat_verification` (~line 102), and the `vat_apis` section (~line 19). Note: there is **no** `roi_verification` config section (`RoiVerificationService` read a `config('larabill.roi_verification', [])` default that never existed) — nothing to remove there.
- **`tests/Pest.php`** — remove `Integration/VatVerificationIntegrationTest.php` from the `->in(...)` list (line ~43).
- **`tests/Integration/InstallMysql/InstallCommandSchemaTest.php`** — remove `vat_verifications`, `roi_queries`, `user_roi_verifications` from the expected-tables list (~lines 59, 63, 64).
- **`tests/Integration/Mysql/FreshInstallTest.php`** — remove the `roi_queries.user_id` UUID column-type assertions (~lines 36–37); check for and remove any `user_roi_verifications` assertion too.
- **`CLAUDE.md`** (local, line ~10) — reframe the "verificación VAT vía lararoi" line: larabill does **no** VAT verification; it is the consumer's responsibility via lararoi.
- **`README.md`** — remove the `### VAT Verification` section (usage example of the deleted `VatVerificationService`, ~line 209) and drop "comprehensive VAT verification" from the intro (~line 16).
- **`SCHEMA_REQUIREMENTS.md`** — remove the `vat_verifications`, `roi_queries`, `user_roi_verifications` rows from the schema and migration-order tables.
- **`CHANGELOG.md`** — `[Unreleased]` → **BREAKING CHANGES** section describing the removal (see Versioning).

### Do NOT touch

`is_roi_taxed` (the real reverse-charge input) and the reverse-charge logic, `Invoice`, `EuSalesThresholdService`, `DestinationVatService`, `CountryVatRate`, `VatCategory`, **`CompanyFiscalConfig::is_roi`** (the *issuer's* OSS/ROI-registration status — a different field, not a verification result), the **ADR-008 `LegallyRetainable`** retention mechanism on `Invoice`/`UserTaxProfile`, and `CacheService`'s generic methods. `CacheService` itself stays (used by `DestinationVatService`).

## Versioning & breaking change

Removing public models, services, tables, config keys and a dependency is **breaking → v4.0.0**.

- **No `down`/drop migration is generated.** A consumer that already installed v3.1.x keeps its (empty, inert) `vat_verifications` / `roi_queries` / `user_roi_verifications` tables; their data is not touched. They may drop them manually if desired.
- CHANGELOG documents: verification is no longer provided by larabill; consumers who need intra-community VAT verification should use `lararoi` directly.
- If a real consumer of v3.1.3 exists that relied on these tables, the major bump + breaking note is the contract signal.

## Adversarial verification (2026-07-02)

Three independent adversarial reviewers were tasked to REFUTE the design's pillars before planning. None overturned the deletion:

- **Liveness (is the layer really dead?) → CONFIRMED-DEAD.** Closed island: `VatApiIntegrationService` → only `VatVerificationService` → only `RoiVerificationService` → a `BillingService` constructor property no method invokes. Every `is_roi_taxed` occurrence in `src/` is a read; the only writer is the test factory. `is_roi` on `CompanyFiscalConfig`/`InvoiceService` is a **different** field (issuer OSS/ROI-registration status), not a verification result.
- **Breakage → external SAFE.** No sibling imports the doomed classes (the `lararoi` name hits are a namespace collision, not a dependency; lararoi does not know larabill exists). No sibling requires `aichadigital/larabill` → the v4.0.0 major breaks no constraint. No surviving migration FKs into the three doomed tables.
- **lararoi coverage → GAPS-FOUND, none blocking.** lararoi is NOT a strict superset. It covers/improves multi-provider verification (official VIES vs 3rd-party proxies), TTL cache, per-country syntax validation (26 EU states) and the exception model. It does NOT cover: per-user ROI state (gap B — a non-loss: VAT validity is a property of the number, not the asker), query statistics/batch/force-refresh (gap 7 — trivial or dead, e.g. `checkApiRateLimit` was a `rand()` mock), and the legal-retention query log (gap C — see below). The return-shape mismatch (lararoi **throws** on total failure instead of returning an `all_apis_failed` flag) is **moot under this design**: larabill writes no adapter and never calls lararoi, so there is no shape to reconcile — a point in favour of Option A over the rejected wrapper (Option B).

## Gap C — RoiQuery removed deliberately, NOT substituted

`RoiQuery` was a legal-retention audit log of ROI queries (7-year hold). lararoi has no equivalent (its single `vat_verifications` table is a mutable cache). This capability is **removed, not replaced** — a deliberate product decision:

- It was **dead**: never wired to the issuance flow, never linked to an invoice, never captured the VIES `requestIdentifier` (the official consultation proof number — which neither package captures).
- larabill's **real** fiscal retention is `LegallyRetainable` on `Invoice`/`UserTaxProfile` (ADR-008), untouched by this work.
- Keeping `RoiQuery` would preserve an *apparent* legal promise, not an effective capability.

If an invoice-linked VIES-consultation legal proof is ever wanted, it is a **new feature with its own design** (likely outside larabill, or in lara-verifactu) — **not** a resurrection of this dead layer. AID-309 does not carry it forward as technical debt.

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
