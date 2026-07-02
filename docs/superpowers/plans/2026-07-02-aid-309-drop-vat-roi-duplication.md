# AID-309 — Drop the duplicated VAT/ROI verification layer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove larabill's dead, duplicated VAT/ROI verification layer (3 models, 3 services, 3 tables, the ROI surface of `CacheService`) and the dead `aichadigital/lararoi` dependency, so larabill stops owning a domain that belongs to `lararoi`.

**Architecture:** This is a **deletion**, not a build. There is no live consumer (verified adversarially): the reverse-charge decision is driven by the input flag `is_roi_taxed`, never by a verification call. The TDD cycle is inverted — each task removes code and then proves the existing suite stays green. Order is chosen so **every commit leaves the package compilable and green**: unwire live consumers first, then delete tests, then delete code, then migrations, then trim `CacheService`, then drop the dependency, then docs, then CHANGELOG + quality gate.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Pest, Orchestra Testbench (SQLite in-memory + MySQL 8 integration), PHPStan level 8, Laravel Pint.

**Source spec:** `docs/superpowers/specs/2026-07-02-aid-309-drop-vat-roi-duplication-design.md`

## Global Constraints

- **Deletion only.** Do NOT write a `lararoi` adapter or any replacement verification code (Option A: larabill does no VAT verification). If a step tempts you to "wire lararoi in", stop — that is out of scope.
- **Do NOT touch:** `is_roi_taxed` and the reverse-charge logic, `Invoice`, `EuSalesThresholdService`, `DestinationVatService`, `CountryVatRate`, `VatCategory`, `CompanyFiscalConfig::is_roi` (issuer status — a different field), the ADR-008 `LegallyRetainable` mechanism on `Invoice`/`UserTaxProfile`, and `CacheService`'s generic / VAT-rate / company-config methods.
- **PHP version:** run tests with PHP 8.3 (`~/Library/Application Support/Herd/bin/php83`). PHP 8.4 local has a known "table already exists" SQLite bug — or rely on CI (PHP 8.3+8.4 × L12+L13 + MySQL 8).
- **Migrations:** `$migrationOrder` must stay 1:1 with the package `.php.stub` files — enforced by `tests/Unit/Console/MigrationOrderConsistencyTest.php`. Leave numbering gaps; do not renumber.
- **Versioning:** breaking removal → **v4.0.0**. No `version` field in `composer.json` (release by annotated tag, post-merge). This PR fills `CHANGELOG.md` `[Unreleased]` with a BREAKING CHANGES section; the tag is a separate release step.
- **Commits:** English. One PR, scoped to larabill.
- **Green gate per task:** `composer test` (or `php83 vendor/bin/pest`) after each task; `composer quality` at the end.

---

### Task 1: Unwire the live consumers of the dead layer

Remove every reference **into** the layer from surviving code, so later deletion cannot dangle. All three references are dead (injected/mapped but never exercised).

**Files:**
- Modify: `src/Services/BillingService.php` (constructor injection + property + class docblock)
- Modify: `src/Services/ModelMappingService.php` (import + `vat_verification` map entry)
- Modify: `config/larabill.php` (`vat_apis` section, `models.vat_verification`, `field_mappings.vat_verification`)

- [ ] **Step 1: Edit `BillingService.php`** — remove the `RoiVerificationService` import (if present), the `private RoiVerificationService $roiVerificationService;` property, the `?RoiVerificationService $roiVerificationService = null` constructor parameter, and the `$this->roiVerificationService = $roiVerificationService ?? app(RoiVerificationService::class);` assignment. The constructor keeps only `TaxCalculationService`. Update the class docblock line "Handles invoice creation and management with ROI verification and optional immutability." → "Handles invoice creation and management with optional immutability."

- [ ] **Step 2: Edit `ModelMappingService.php`** — remove `use AichaDigital\Larabill\Models\VatVerification;` and the `'vat_verification' => VatVerification::class,` line from the `$defaultModels` array. Leave the other entries untouched.

- [ ] **Step 3: Edit `config/larabill.php`** — delete the whole `'vat_apis' => [ ... ],` section (~line 19), the `'vat_verification' => VatVerification::class,` line in `models` (~line 94), and the `'vat_verification' => [ ... ],` block in `field_mappings` (~line 102). If removing the `models.vat_verification` reference leaves an unused `use ...VatVerification` import at the top of the config, remove it too.

- [ ] **Step 4: Run the suite** — `php83 vendor/bin/pest` (or `composer test`). Expected: PASS (these were dead references; nothing live depended on them). If a `ModelMappingService`/`BillingService` test fails, it was asserting the dead mapping/injection — that is unexpected per the adversarial sweep; stop and investigate before continuing.

- [ ] **Step 5: Commit**

```bash
git add src/Services/BillingService.php src/Services/ModelMappingService.php config/larabill.php
git commit -m "refactor(billing): unwire dead VAT/ROI verification references (AID-309)"
```

---

### Task 2: Delete the layer's tests

Remove the tests that exercise the doomed classes, and drop the one Pest binding that references the integration test. Deleting tests first keeps the suite green when the code is deleted next.

**Files (delete):**
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

**Files (modify):**
- `tests/Pest.php` — line ~43 `->in('Feature', 'Unit', 'Integration/VatVerificationIntegrationTest.php');`

- [ ] **Step 1: Delete the test files**

```bash
git rm tests/Unit/VatVerificationTest.php \
  tests/Unit/Models/VatVerificationTest.php \
  tests/Unit/Models/UserRoiVerificationTest.php \
  tests/Unit/Models/RoiQueryTest.php \
  tests/Unit/Services/RoiVerificationServiceTest.php \
  tests/Unit/Services/VatVerificationServiceFallbackTest.php \
  tests/Unit/Services/VatVerificationServiceApiFailureTest.php \
  tests/Unit/Services/VatVerificationServiceRateLimitTest.php \
  tests/Unit/Services/VatApiIntegrationServiceTest.php \
  tests/Integration/VatVerificationIntegrationTest.php \
  tests/Models/CustomVatVerification.php
```

- [ ] **Step 2: Edit `tests/Pest.php`** — change line ~43 from `->in('Feature', 'Unit', 'Integration/VatVerificationIntegrationTest.php');` to `->in('Feature', 'Unit');` (drop the now-deleted integration test from the binding).

- [ ] **Step 3: Run the suite** — `php83 vendor/bin/pest`. Expected: PASS with a smaller test count. No "class not found" (the layer code still exists; only its tests are gone).

- [ ] **Step 4: Commit**

```bash
git add -A tests/
git commit -m "test: remove dead VAT/ROI verification tests (AID-309)"
```

---

### Task 3: Delete the layer's code (3 services + 3 models)

Nothing references these now (unwired in Task 1, tests gone in Task 2).

**Files (delete):**
- `src/Services/VatVerificationService.php`
- `src/Services/RoiVerificationService.php`
- `src/Services/VatApiIntegrationService.php`
- `src/Models/VatVerification.php`
- `src/Models/RoiQuery.php`
- `src/Models/UserRoiVerification.php`

- [ ] **Step 1: Delete the files**

```bash
git rm src/Services/VatVerificationService.php \
  src/Services/RoiVerificationService.php \
  src/Services/VatApiIntegrationService.php \
  src/Models/VatVerification.php \
  src/Models/RoiQuery.php \
  src/Models/UserRoiVerification.php
```

- [ ] **Step 2: Grep for dangling references** — `grep -rnE "VatVerificationService|RoiVerificationService|VatApiIntegrationService|VatVerification\b|RoiQuery\b|UserRoiVerification\b" src/`. Expected: **no output** (CacheService's ROI methods are named `...RoiVerification...` and are handled in Task 5 — they will appear; that is fine, note them and move on). Any `src/` reference to the six deleted classes outside `CacheService` must be resolved before continuing.

- [ ] **Step 3: Run the suite** — `php83 vendor/bin/pest`. Expected: PASS. No "class not found".

- [ ] **Step 4: Commit**

```bash
git add -A src/
git commit -m "refactor: delete duplicated VAT/ROI verification models and services (AID-309)"
```

---

### Task 4: Delete the migrations, stubs, and `$migrationOrder` entries

The three tables and their stubs go together with their `$migrationOrder` entries and the install-contract test assertions — all in one commit, or the consistency test fails.

**Files (delete):**
- `database/migrations/2024_12_01_000007_create_vat_verifications_table.php` + `database/migrations/create_vat_verifications_table.php.stub`
- `database/migrations/2026_02_16_000004_create_roi_queries_table.php` + `database/migrations/create_roi_queries_table.php.stub`
- `database/migrations/2026_02_16_000005_create_user_roi_verifications_table.php` + `database/migrations/create_user_roi_verifications_table.php.stub`

**Files (modify):**
- `src/Console/LarabillInstallCommand.php` — `$migrationOrder`: remove `'020' => 'create_vat_verifications_table'`, `'024' => 'create_roi_queries_table'`, `'025' => 'create_user_roi_verifications_table'` (leave the numbering gaps).
- `tests/Integration/InstallMysql/InstallCommandSchemaTest.php` — remove `'vat_verifications'`, `'roi_queries'`, `'user_roi_verifications'` from the expected-tables list (~lines 59, 63, 64).
- `tests/Integration/Mysql/FreshInstallTest.php` — remove the `roi_queries.user_id` UUID column assertions (~lines 36–37); grep the file for `user_roi_verifications` / `vat_verifications` and remove any assertions on them too.

- [ ] **Step 1: Delete migration + stub files**

```bash
git rm database/migrations/2024_12_01_000007_create_vat_verifications_table.php \
  database/migrations/create_vat_verifications_table.php.stub \
  database/migrations/2026_02_16_000004_create_roi_queries_table.php \
  database/migrations/create_roi_queries_table.php.stub \
  database/migrations/2026_02_16_000005_create_user_roi_verifications_table.php \
  database/migrations/create_user_roi_verifications_table.php.stub
```

- [ ] **Step 2: Edit `$migrationOrder`** in `src/Console/LarabillInstallCommand.php` — delete the three lines (`'020'`, `'024'`, `'025'`). Do not renumber the others.

- [ ] **Step 3: Edit the install tests** — remove the three table names from `InstallCommandSchemaTest.php`'s expected list, and the `roi_queries` (+ any `user_roi_verifications`/`vat_verifications`) assertions from `FreshInstallTest.php`.

- [ ] **Step 4: Run the migration guardrail** — `php83 vendor/bin/pest tests/Unit/Console/MigrationOrderConsistencyTest.php`. Expected: PASS (`$migrationOrder` and stubs stay 1:1, 3 fewer each).

- [ ] **Step 5: Run the full suite** — `php83 vendor/bin/pest`. Expected: PASS. (The MySQL install tests are gated to CI/local MySQL; if MySQL is not configured locally they are skipped — confirm the skip, and rely on CI for the real run.)

- [ ] **Step 6: Commit**

```bash
git add -A database/migrations/ src/Console/LarabillInstallCommand.php tests/Integration/
git commit -m "refactor(migrations): drop vat_verifications, roi_queries, user_roi_verifications (AID-309)"
```

---

### Task 5: Trim the ROI surface from `CacheService`

Remove the **entire** ROI surface, not just the 7 public methods, and prune the ROI cases from `CacheServiceTest`.

**Files (modify):**
- `src/Services/CacheService.php`
- `tests/Unit/Services/CacheServiceTest.php`

- [ ] **Step 1: Edit `CacheService.php`** — remove:
  - the 7 methods: `storeRoiVerification`, `getRoiVerification`, `hasRoiVerification`, `removeRoiVerification`, `getRoiVerificationKey`, `countRoiVerifications`, `flushRoiVerificationCache`;
  - the `'roi_verifications'` key from every `$entryCounts` initializer/reset (~lines 37, 792, 809);
  - the ROI stat wiring: the `countRoiVerifications()` call and the `'roi_verifications'` stat entries (~lines 716, 721, 730);
  - the `'roi_verification'` entry in the key-pattern map (~line 582);
  - and the ROI example strings in the generic key-type comments (~lines 329, 333, 350) — replace the example with a surviving type (e.g. `vat_rate`) or drop the parenthetical.
  Keep all generic, VAT-rate and company-config methods intact.

- [ ] **Step 2: Edit `CacheServiceTest.php`** — delete the three ROI-dedicated tests (`it('can store and retrieve ROI verification cache', ...)`, `it('can check if ROI verification exists in cache', ...)`, `it('can remove ROI verification from cache', ...)`) and remove the ROI assertions embedded in the mixed tests (the `storeRoiVerification`/`hasRoiVerification`/`flushRoiVerificationCache`/`getRoiVerificationKey` calls and the `config(['larabill.cache.ttl.roi_verification' => ...])` line around lines 136–198). Keep the generic + VAT-rate + company-config assertions in those mixed tests; if removing ROI empties a mixed test entirely, delete that test.

- [ ] **Step 3: Grep for leftovers** — `grep -rniE "roi" src/Services/CacheService.php tests/Unit/Services/CacheServiceTest.php`. Expected: **no output**.

- [ ] **Step 4: Run the CacheService test then the suite** — `php83 vendor/bin/pest tests/Unit/Services/CacheServiceTest.php` then `php83 vendor/bin/pest`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/CacheService.php tests/Unit/Services/CacheServiceTest.php
git commit -m "refactor(cache): remove orphaned ROI verification cache surface (AID-309)"
```

---

### Task 6: Drop the dead `aichadigital/lararoi` dependency

**Files (modify):**
- `composer.json` — remove `"aichadigital/lararoi": "^0.5"` from `require`; edit `description` to drop "VAT verification".

- [ ] **Step 1: Edit `composer.json`** — delete the `"aichadigital/lararoi": "^0.5",` line under `require`. Change `description` from `"Professional billing & invoicing package for Laravel with UUID v7, VAT verification, tax calculation for Spain/EU/worldwide, and EU compliance"` to `"Professional billing & invoicing package for Laravel with UUID v7, tax calculation for Spain/EU/worldwide, and EU compliance"`.

- [ ] **Step 2: Refresh the environment** — `composer update --no-interaction` (larabill does not track `composer.lock`; this only refreshes the local dev env and re-runs `testbench package:discover` via `composer prepare`). Confirm no lararoi package remains: `composer show aichadigital/lararoi 2>&1` → expected "not installed / not found".

- [ ] **Step 3: Grep confirms larabill never used it** — `grep -rn "Aichadigital\\\\Lararoi\|aichadigital/lararoi" src/ tests/`. Expected: **no output**.

- [ ] **Step 4: Run the suite** — `php83 vendor/bin/pest`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json
git commit -m "chore(deps): drop dead aichadigital/lararoi dependency (AID-309)"
```

---

### Task 7: Update the package docs

**Files (modify):**
- `README.md` — the `### VAT Verification` section (~lines 206–219) and the intro (~line 16).
- `SCHEMA_REQUIREMENTS.md` — the three table rows (~lines 151, 183, 187, 188).
- `CLAUDE.md` — the VAT-verification line (~line 10).

- [ ] **Step 1: Edit `README.md`** — delete the whole `### VAT Verification` section (the `use ...VatVerificationService;` / `app(VatVerificationService::class)` / `verifyVatNumber(...)` example block). In the intro sentence (~line 16), drop "comprehensive VAT verification," so it reads "…It provides tax calculation for Spain/EU/worldwide, and flexible invoice generation with immutability protection…".

- [ ] **Step 2: Edit `SCHEMA_REQUIREMENTS.md`** — remove the `vat_verifications` row (~line 151) and the three migration-order rows for `create_vat_verifications_table` / `create_roi_queries_table` / `create_user_roi_verifications_table` (~lines 183, 187, 188).

- [ ] **Step 3: Edit `CLAUDE.md`** (~line 10) — change "Cálculo fiscal (España, UE, mundial) y verificación VAT vía `lararoi`" to "Cálculo fiscal (España, UE, mundial); larabill NO verifica VAT/NIF — es responsabilidad de la app consumidora vía `lararoi` (ver AID-309)".

- [ ] **Step 4: Sanity grep** — `grep -rniE "VatVerificationService|verificación VAT|vat_verifications" README.md SCHEMA_REQUIREMENTS.md CLAUDE.md`. Expected: no stale references to the deleted layer (the CLAUDE.md line now states larabill does NOT verify).

- [ ] **Step 5: Commit**

```bash
git add README.md SCHEMA_REQUIREMENTS.md CLAUDE.md
git commit -m "docs: larabill no longer owns VAT/ROI verification (AID-309)"
```

---

### Task 8: CHANGELOG (BREAKING) + final quality gate

**Files (modify):**
- `CHANGELOG.md` — `[Unreleased]` BREAKING CHANGES section.
- possibly `phpstan-baseline.neon` — if it holds entries for the deleted files.

- [ ] **Step 1: Edit `CHANGELOG.md`** — under `## [Unreleased]`, add:

```markdown
### Removed — BREAKING

- **Removed larabill's VAT/ROI verification layer (AID-309).** Deleted the
  `VatVerification`, `RoiQuery`, `UserRoiVerification` models and their tables
  (`vat_verifications`, `roi_queries`, `user_roi_verifications`), the
  `VatVerificationService`, `RoiVerificationService` and `VatApiIntegrationService`
  services, the ROI surface of `CacheService`, and the (unused) `aichadigital/lararoi`
  dependency. This layer was **dead** — no live billing-flow consumer; reverse-charge
  is driven by the `is_roi_taxed` input flag. Intra-community VAT/NIF verification is
  the domain of the `lararoi` package; a consuming application that needs it should use
  `lararoi` directly and pass `is_roi_taxed` to larabill.
  **This is a breaking change (v4.0.0):** the three tables and the model/service classes
  are gone. No down/drop migration is generated — an app that installed a prior version
  keeps its (empty, inert) tables and may drop them manually. `RoiQuery`'s ROI-query
  legal-retention log is removed **without substitution**; larabill's fiscal retention
  remains `LegallyRetainable` on `Invoice`/`UserTaxProfile` (ADR-008). An invoice-linked
  VIES-consultation proof, if ever needed, is a new feature — not a resurrection of this
  layer.
```

- [ ] **Step 2: Run the full quality gate** — `composer quality` (Pint + PHPStan level 8 + tests). Expected: PASS.

- [ ] **Step 3: If PHPStan fails on deleted-file baseline entries** — open `phpstan-baseline.neon`, remove any `path:` blocks pointing at the six deleted `src/` files (and any deleted test paths if baselined), then re-run `composer phpstan`. Do NOT add new baseline entries; only remove entries for files that no longer exist.

- [ ] **Step 4: Run Pint format check** — `composer pint` (auto-fixes). Re-run `php83 vendor/bin/pest` if Pint changed anything. Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md phpstan-baseline.neon
git commit -m "docs(changelog): record breaking removal of VAT/ROI layer (AID-309)"
```

---

## Post-plan: PR + release

- Push the branch and open one PR scoped to larabill, in English, titled e.g. `refactor: drop duplicated VAT/ROI verification layer (AID-309)`, body summarizing the deletion + the breaking note + a pointer to the spec.
- CI matrix (PHP 8.3+8.4 × L12+L13 + MySQL 8) is the real green gate — verify job-by-job, not the exit code.
- **Release v4.0.0 is a separate step** after merge (per larabill's release process: a `chore(release)` PR moving `[Unreleased]` → `[4.0.0]`, then an annotated `v4.0.0` tag; the workflow builds the GitHub Release and the Packagist webhook picks it up).
- **Follow-up (out of scope):** correct the umbrella `CLAUDE.md` "larabill usa lararoi" line in a separate, non-larabill-scoped change.

## Self-review notes (author)

- **Spec coverage:** every "Delete"/"Modify" item in the spec maps to a task — models/services (Task 3), migrations/stubs/`$migrationOrder`/install tests (Task 4), CacheService (Task 5), composer dep + description (Task 6), config + ModelMappingService + BillingService (Task 1), tests + Pest.php (Task 2), README/SCHEMA_REQUIREMENTS/CLAUDE.md (Task 7), CHANGELOG + v4.0.0 + baseline (Task 8). Gap C, adversarial trace, and do-not-touch list are honored by the Global Constraints + Task 8 CHANGELOG wording.
- **Order safety:** consumers unwired (T1) before code deleted (T3); tests deleted (T2) before code (T3); migrations + `$migrationOrder` + install tests move together (T4) to keep `MigrationOrderConsistencyTest` green.
- **No adapter:** no task adds lararoi wiring — consistent with Option A.
