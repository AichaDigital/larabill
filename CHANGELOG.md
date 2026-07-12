# Changelog

All notable changes to `larabill` will be documented in this file.

## [Unreleased]

## [6.2.0] - 2026-07-12

**Ships migrations: no** — upgrade is a plain `composer update aichadigital/larabill`; no `larabill:install` re-run or `migrate` needed.

### Added
- **`MissingInvoiceOwnerException` (`@api`, AID-391)** — typed exception thrown by `EuSalesThresholdService` when an invoice reaches owner-scoped accounting without an owner (`user_id`), replacing the silent fabricated-owner fallback (see the Fixed entry below). New supported public surface: consumers can catch it explicitly; its `forInvoice()` factory carries the offending invoice's fiscal number in the message. This addition is what makes this release a minor instead of a patch.

### Fixed
- **COMPLIANCE — the six PDF templates now print `invoice_date` («Fecha de expedición»), never `created_at` (AID-439).** RD 1619/2012 art. 6.1.b (7.1.b for simplified invoices) requires the invoice to show its expedition date, which the package stores as `invoice_date` («Legal invoice date (appears on PDF)»); the templates printed `created_at`, a technical persistence timestamp that diverges on imports/restores/ahead-of-time creation. `issued_at` does not substitute either (reserved for technical chronological validation). Regression test renders every template with the three dates deliberately different and asserts only `invoice_date` appears. That test also surfaced and fixed a latent `ucfirst($invoice->status)` crash (enum cast) in `fiscal-minimal`/`fiscal-modern`/`proforma` — the same bug fixed in `fiscal.blade.php` on 2026-06-18 but never propagated to the sibling templates, which had never rendered with a real model until now. Adjacent `service_date` display (operation date when it differs) is tracked in AID-442, deliberately not bundled here.
- **The EU OSS sales threshold no longer attributes amounts to a fabricated owner nor to the wrong fiscal year (AID-391).** `EuSalesThresholdService` fell back to `config('larabill.company.id', '1')` — a key that does not exist and a value that is not a UUID — silently corrupting the threshold ledger whenever an invoice reached it without `user_id`; it now fails loud with the new typed `MissingInvoiceOwnerException`. The `fiscal_year` fallback used `date('Y')` (PHP TZ, natural year), ignoring `LARABILL_FISCAL_START_MONTH`; it now derives from `RegionalContext::getFiscalYear(now())` — the same source the numbering uses.

### Changed
- **PDF internals (AID-391):** the DomPDF rendering engine is now injectable into `PDFService` (third constructor argument; previously hard-wired, impossible to substitute in tests) and `DomPDFService::savePDF()` writes through the `File` facade (`ensureDirectoryExists` + `put`) instead of raw `mkdir`/`file_put_contents`. No behavior change; both `@internal`/additive.
- **Idiomatic cleanup (AID-391):** `uniqid()` replaced by `Str::uuid()`/`Str::random(16)` (`FakeFiscalVerification` fake ids, `CacheService` health-check key — low-entropy `uniqid()` collides); the 6 PDF templates' date fallback uses `now()->format()` instead of `date()`; `GroupedPaymentService` batches its two per-invoice queries (`Invoice::find()` per pivot row on reversal, `exists()` per invoice on registration) into single `whereIn` queries. No behavior change. The classic-accessor→`Attribute::make()` conversion listed in the ticket was deliberately REJECTED under STABILITY.md rule 1: it removes public methods from `@api` models for "cleaner API" alone, which does not qualify.

## [6.1.0] - 2026-07-11

**Ships migrations: yes** — upgrade with the standard ritual: `composer update aichadigital/larabill` + `php artisan larabill:install` (idempotent, publishes only the new migration) + `php artisan migrate`. The migration is data-safe by construction (columns only widen; no row is touched, no value can be truncated).

### Changed
- **The fiscal series width now matches the norm instead of an artificial cap (AID-429).** `invoices.prefix` and `invoice_series_control.prefix` widen from `varchar(10)` to `varchar(50)`, and `InvoiceSeriesResolver` validates against the same 50-char contract. The ceiling is derived, not invented: the AEAT VERI*FACTU schema types `NumSerieFactura` as `TextoIDFacturaType` `maxLength=60`, and larabill composes that field as `prefix . series_number` (raw unpadded correlative, worst case 10 digits), so the series literal may legitimately use up to 60 − 10 = 50 characters. The old 10-char cap rejected legitimate per-installation series names (e.g. `RECT-CASTRIS`). MySQL width proof added to the fresh-install integration test.
- **STABILITY.md rule 2 generalized:** the versioned upgrade ritual (idempotent `larabill:install` re-run + `migrate`, upgrade-path test, explicit CHANGELOG upgrade note) is now stated for EVERY release that ships migrations — major or minor — with the minor-eligibility criterion spelled out (additive or provably data-safe DDL only). This release is the first minor exercising that path under the stability contract.

## [6.0.0] - 2026-07-11

### Removed
- **BREAKING (AID-423) — `BillingService` removed.** The class was `@deprecated` since v4.1.0 (AID-390) and survived v5.0.0 only as an adapted caller. Migrate to `InvoiceService::createInvoice()` (fiscal snapshots, ADR-003 `billable_user_id`, `InvoiceNumberingService` numbering) — see **UPGRADE-6.0.md** for the 1:1 mapping. No schema or data change: this release ships **zero migrations**.
- **BREAKING (AID-423) — the `user_id` compatibility shims of `UserTaxProfile` removed** (deprecated aliases from the ADR-003→ADR-004 owner rename): `user()` relation (use `owner()`), `user_id` attribute accessor/mutator (use `owner_user_id`), `UserTaxProfile::getActiveForUser()` (use `getActiveForOwner()`), `UserTaxProfile::getValidForUserAt()` (use `getValidForOwnerAt()`), `UserTaxProfile::createForUser()` (use `createForOwner()`), and the `forUser()` scope (use `forOwner()`).
- **BREAKING (AID-423) — the deprecated aliases of `HasUserRelationships` removed:** `taxProfiles()` (use `ownedTaxProfiles()`), `activeTaxProfile()` (use the `currentTaxProfile()` relation) and the `withActiveTaxProfile()` scope (use `withCurrentTaxProfile()`).
- `UserTaxProfileFactory::forUser()` (use `forOwner()`; the factory is `@internal` surface).
- `RecurringBillingService::getServicesDueForBilling()` — dead `protected` method on a `final` class with zero callers (not consumer-reachable; listed for completeness).

### Added
- **`STABILITY.md` — the package's stability contract, effective from v6.0.0.** larabill is closed as a stable product: breaking changes only enter with a qualified, documented usage imperative; every future major must be auto-upgradeable from the previous one (data-aware migrations + idempotent `larabill:install` re-run + upgrade-path test, per AID-398); new deprecations live through at least one full major before removal. v6.0.0 removes the last known deprecated surface — after it the roadmap carries **no known breaking change**.

### Tests
- **`LarabillInstallCommandTest` no longer publishes stubs into the shared testbench skeleton `database_path` (AID-419).** The install test now redirects `database_path()` to a per-test temp dir (`app()->useDatabasePath()`), the same isolation the MySQL install path already uses (`InstallCommandMysqlTestCase`, AID-287). Fixes a pre-existing `pest --parallel` race: `larabill:install` published its stubs into the physically shared `vendor/orchestra/testbench-core/laravel/database/migrations/` dir and deleted them in teardown, so a concurrent worker running `RefreshDatabase` could `require()` a migration file mid-write/mid-delete and throw `FileNotFoundException` on a rotating victim test (~1 in 2-3 local `composer test-parallel` runs; CI unaffected — it runs `pest --ci` sequentially). No package surface, schema or runtime change.

## [5.0.0] - 2026-07-11

### Changed
- **BREAKING (AID-307) — invoice uniqueness now hangs from the real fiscal SERIES, not the fiscal type.** The `invoices` unique index moves from `(serie, series_number, fiscal_year)` to `(prefix, serie, series_number, fiscal_year)`. Before this release the `serie` column held the fiscal TYPE (`InvoiceSerieType`), so two real series of the same type collided (`FAC-2026-000001` and `ARB-2026-000001` both reduced to `serie='1'`). The real fiscal series lives in `prefix`, which now scopes uniqueness, the numbering counter's continuity seed, and the AEAT `NumSerieFactura` series component. See **UPGRADE-5.0.md** — the migration is DDL-only (superset key, no rows touched) and re-running `larabill:install` publishes only the new migration.
- **BREAKING (AID-307) — `VerifactuAdapter` sends the real series (`invoices.prefix`) as the AEAT series**, not the int-backed `InvoiceSerieType` value. The fiscal type still travels separately as `TipoFactura` (F1/F2/R1). Invoices emitted before the upgrade are immutable in AEAT and are not retro-altered; only future emissions carry the corrected series. The rectified-invoice reference in `FacturasRectificadas` uses the same real series.
- README schema-upgrade policy note rewritten (AID-398, «stable versions = respect for data»): in-place upgrades are supported and documented — `composer update` + idempotent `larabill:install` re-run (publishes only the NEW migrations, never overwrites the consumer's config) + `migrate`. The previous note recommending fresh installs / `migrate:fresh` across versions is retired; every schema-touching release ships a data-aware migration (see the AID-390 backfill as the canonical example).

### Added
- **`InvoiceSeriesResolver` (AID-307)** — the single source of truth for the fiscal series, resolving a type to its series through one cascade (explicit caller request → `config('larabill.invoice_numbering.series.{type}')` → legacy `invoice_prefix`/`proforma_prefix` → the enum default). A consumer runs multiple series for the same fiscal type (RD 1619/2012 art. 6) by passing `invoiceData['series']`. Backward compatible: a v4 consumer that upgrades without re-publishing its config keeps resolving `INVOICE→FAC` / `PROFORMA→PRO`. The additive `invoice_numbering.series` config map declares the per-type defaults. Mid-year series changes are supported and transparent to Verifactu — the AEAT hash chain is global per obligated party, never per series.
- **Full `@api`/`@internal` surface taxonomy (AID-413, boundary spec §3 / AID-407):** every classlike under `src/` (123) now declares its band with exactly one class-level tag — 84 `@api` (Models, Enums, Concerns, ValueObjects, Contracts, Exceptions, Events, Actions, DTOs, Console, Facade, 13 domain Services, `MigrationHelper`, `TaxRatesSeeder`) and 39 `@internal` (integrity/PDF/mapping/cache internals, listeners, notifications, provider, factories, remaining seeders). Enforced in CI by `SurfaceTaxonomyTest` (missing or conflicting tags fail; the seven amber method-level `@api` tags are asserted too). Criteria and the when-in-doubt-internal principle documented in `docs/api-surface.md`. The README no longer instructs seeding the non-existent `TaxCategoriesSeeder`.
- **Package-side contract enforcement (AID-412, consumer↔package boundary spec §4.2 / AID-407):** a golden-master snapshot of the public surface (table, columns, declared casts, relations, scopes, method signatures with `@api`/`@deprecated`/static flags) of the seven contract models (`Invoice`, `CompanyFiscalConfig`, `UserTaxProfile`, `Article`, `ArticlePrice`, `InvoiceSeriesControl`, `EuSalesThreshold`) now fails CI whenever the surface drifts from the committed snapshots. The only sanctioned regeneration path is `bin/sync-contract-snapshots`, which refuses to proceed while the CHANGELOG `[Unreleased]` section is empty; regeneration is forbidden in CI. The seven amber-band model operations (`Invoice::makeImmutable()`/`snapshotFiscalConfigs()`, `CompanyFiscalConfig::getValidAt()`/`createNew()`, `InvoiceSeriesControl::getNextNumber()`, `Article::getDefaultPrice()`, `UserTaxProfile::createForOwner()`) are tagged `@api`.
- **Shipped-migration immutability + upgrade-path tests (AID-398 "stable versions = respect for data"):** `tests/Contract/release-migration-manifest.json` records every migration shipped in the latest release (name + sha256, flagging which existed in the upgrade base). A file-level test fails CI if any shipped migration is renamed, edited or deleted, and a MySQL-gated test (`tests/Integration/UpgradePath/`) upgrades a populated previous-release database to HEAD, asserting zero data loss, numbering continuity and post-upgrade invoice issuance. `bin/tag-release` now runs the contract preflight and refreshes the manifest (via the new `bin/sync-upgrade-manifest`) before creating the tag.

## [4.1.0] - 2026-07-10

### Fixed
- **All live emission paths now derive their numbering from `InvoiceNumberingService`** (AID-390 PR2) — `fiscal_number`, `prefix`, `series_number` and `fiscal_year` come atomically from the SAME `InvoiceNumber`, on the global issuer scope. This retires two broken generators: `InvoiceService` produced `fiscal_number` with `rand(1, 9999)` (non-correlative, collision-prone, and inconsistent with its own `prefix` column), and `BillingService` used a non-atomic cache counter that reset on `cache:clear`. `RecurringBillingService` drops its private MAX+1 variant.
- Continuity seed: when a series control is created for the first time, its counter starts from the highest `series_number` already issued for that serie + fiscal year, so databases with invoices emitted by the legacy paths never reuse a number (a gap is acceptable; a duplicate never is).
- `InvoiceNumberingService` is now race-safe on the FIRST use of a series (AID-390 PR1): the creation of the `invoice_series_control` row recovers from the unique-constraint race (catch + locked re-read) and the numbering transaction retries on gap-lock deadlocks. Before, two concurrent first emitters could fork the correlative sequence.
- The issuer scope of a series is exact and never NULL: a `null` scope is normalized to the `InvoiceSeriesControl::GLOBAL_SCOPE` sentinel (`00000000-0000-0000-0000-000000000000`), so the `unique(prefix, serie, fiscal_year, user_id)` index always applies — NULL values never collide in MySQL/SQLite unique indexes. A new data migration collapses pre-existing duplicate NULL-scope controls (keeping the highest counter, so no issued number is ever reused) and backfills the sentinel.

### Deprecated
- `BillingService` as a whole (superseded by `InvoiceService` + `InvoiceNumberingService`; it now delegates its numbering but is removal-targeted for the AID-307 breaking major). The README quick-start now documents `InvoiceService`.

### Removed
- `BillingService::generateInvoiceNumber()` / `getSequenceNumber()` (cache-counter numbering, deprecated since 2026-02-15 with removal target v0.7.0), the duplicated `getTempSeriesNumber()` in `BillingService` and `InvoiceService`, and the dead float-typed `BillingService::calculateSubtotal()`.

### Changed
- **Behavior:** a user-scoped `generateNumber()` call no longer falls back to the global series control (`whereNull()->orWhere()` retired): global and user-scoped sequences are fully independent. The scope parameter is the ISSUER, never the billed customer (ADR-003).
- `InvoiceNumberingService` reads/writes `invoice_series_control` through the `InvoiceSeriesControl` model (casts and events restored) instead of raw `DB::table()`.

### Tests
- Fork-based concurrency proof (`tests/Concurrency/InvoiceNumberingConcurrencyTest.php`, gated `RUN_CONCURRENCY_IT=1` + MySQL): 6 OS processes on a non-existent series converge to ONE control and a strict 1..N sequence; steady-state increments continue without duplicates. Sensitivity verified: the test FAILS against the pre-hardening implementation.

## [4.0.2] - 2026-07-04

### Fixed
- `UPGRADE-4.0.md` reflects the hardened lararoi v1.0.4 preflight (AID-325): the legacy `vat_verifications` self-heal now demands DOUBLE proof (physical index fingerprint + larabill ledger row — a ledger row alone never costs a table), the abort path documents the operator escape hatch (`LARAROI_ASSUME_LEGACY_VAT_TABLE` / `lararoi.upgrade.assume_legacy_vat_table`, one deploy only, after verification), and a pre-migrate `mysqldump` export of the legacy cache is now an explicit step — its rows may hold residual evidence value (raw VIES responses) and the drop is one-way.

### Changed
- `aichadigital/lararoi` constraint raised to `^1.0.4` so the double-proof preflight is guaranteed.

## [4.0.1] - 2026-07-04

### Fixed
- `UPGRADE-4.0.md` now ships in the dist (AID-324): moved from `docs/` — which `.gitattributes` export-ignores — to the repo root, so consumers actually receive the upgrade guide the README and CHANGELOG point to.
- The guide gains an "Existing databases coming from 3.x" section: the legacy `vat_verifications` table self-heals through lararoi's ledger-proven preflight migration (>= 1.0.3); the inert `roi_queries` / `user_roi_verifications` leftovers get documented cleanup SQL.

### Changed
- `aichadigital/lararoi` constraint raised to `^1.0.3` so the upgrade preflight is guaranteed present.

## [4.0.0] - 2026-07-03

> ⚠️ **BREAKING RELEASE.** larabill no longer ships its own VAT/ROI verification
> layer — that domain now lives entirely in the `aichadigital/lararoi` package.
> If your app imported `VatVerificationService` / the `VatVerification` model, or
> read the `vat_verifications` / `roi_queries` / `user_roi_verifications` tables,
> you must migrate — see **[UPGRADE-4.0.md](UPGRADE-4.0.md)**. Consumers
> that bill through the `is_roi_taxed` flag (the normal path) are unaffected.

### Added

- **Thin VAT/NIF verification bridge (AID-309).** New `AichaDigital\Larabill\Actions\VerifyVatNumber`
  action exposes a single named seam — `VerifyVatNumber::run($vatNumber, $countryCode)` —
  that delegates verbatim to `lararoi`'s `VatVerificationServiceInterface` and returns
  its canonical result array unchanged. Pass the VAT number **without** the country
  prefix. No tracking, no input normalization, no output mapping. The bridge is **not**
  wired into invoice issuance: reverse charge is still decided by the `is_roi_taxed`
  flag, never by a live lookup.

### Changed

- **Activated the `aichadigital/lararoi` dependency (`^0.5` → `^1.0`) (AID-309).** The
  dependency was declared but never imported; larabill now consumes lararoi's stable
  v1.0 contract through the bridge above. lararoi is the single owner of the
  intra-community VAT/NIF verification domain; larabill is a consumer.
- **`lararoi` adds an `ext-soap` platform requirement** (its VIES SOAP provider). The
  consumer environment / CI must have the SOAP extension enabled.

### Removed

- **BREAKING: removed larabill's duplicated VAT/ROI verification layer (AID-309).**
  Deleted the models `VatVerification`, `RoiQuery`, `UserRoiVerification`; the services
  `VatVerificationService`, `RoiVerificationService`, `VatApiIntegrationService`; the
  tables `vat_verifications`, `roi_queries`, `user_roi_verifications` (migrations +
  stubs, with their `$migrationOrder` entries); the orphaned ROI cache surface in
  `CacheService`; and the unused `RoiVerificationService` injection in `BillingService`.
  This code duplicated the domain that now lives in `lararoi` and was never wired into
  billing. The `LARABILL_ABSTRACTAPI_KEY` / `LARABILL_APILAYER_KEY` /
  `LARABILL_VAT_PREFERRED_API` / `LARABILL_VAT_CACHE_DAYS` config keys are gone —
  provider/cache configuration now lives in lararoi (`lararoi-config`). Consumers that
  imported these classes or relied on the `vat_verifications` table must switch to the
  `VerifyVatNumber` bridge and lararoi's schema.

### Fixed

- **`isReverseCharge()` no longer returns `null` for an in-memory invoice with
  `is_roi_taxed` unset (AID-294).** Both `DomPDFService::isReverseCharge()` and
  `Invoice::isReverseCharge()` returned the `is_roi_taxed` column directly under a
  `: bool` return type. The column is `boolean default(false)` in the schema, but
  Eloquent skips the boolean cast on an absent key, so a fresh in-memory `Invoice`
  (never persisted, attribute unset) yielded `null` and raised
  `TypeError: Return value must be of type bool, null returned` — surfaced when
  generating a PDF for an invoice built without an explicit `is_roi_taxed`. Both
  methods now cast to `(bool)`, degrading a missing flag to a domestic (non-ROI)
  invoice and matching the sibling `isExemptInvoice()`. The robustness lives in
  the package; consumers no longer need to pre-fill the column.

## [3.1.3] - 2026-06-30

### Removed

- **Removed the dead `AeatInvoiceValidator` (AID-280).** The class was never wired
  (no service-provider registration, zero references in `src/` or tests), broken
  at the root (it dereferenced a non-existent `Invoice::taxProfile` relation, so
  `validate()` always returned invalid), and duplicated the live, wired
  `InvoiceVerifactuService::validateForVerifactu()`. **Internal cleanup, not a
  functional breaking change** — the class was unusable and undocumented. Also
  drops its two `phpstan.neon.dist` ignore paths and its `phpstan-baseline.neon`
  entry (no longer reachable).

## [3.1.2] - 2026-06-29

### Changed

- **PHPStan raised to level 8 — max meaningful level (AID-281).** Final step of
  the 6→7→8 climb. The 7→8 delta was 20 errors (`method.nonObject`,
  `return.type`, `argument.type`, `property.nonObject`) — the read-side
  nullsafety level. Fixed with real null guards, **no new ignores**: a
  non-nullable `FiscalChangeDetector` property in `InvoiceService` (was
  nullable-but-always-initialised); guards for nullable `next_billing_date` /
  `customer` (`RecurringBillingService`) and `effective_price` /
  `cancellation_type` (`ServiceLifecycleService`); `fresh() ?? $this` for fluent
  methods returning `static|null` (`CountryVatRate`, `InvoiceService`); a
  `Carbon::create()` null-assert helper in `RegionalContext` plus guards in
  `EuSalesThreshold` / `ArticleServiceStatus`; and an `items` `@param` shape
  aligned with `createInvoiceItem`. **larabill is now PHPStan level 8.**
- **PHPStan raised to level 7 (AID-279).** Second of three steps toward level 8.
  Fixed the 41-error delta with real type fixes rather than blanket ignores:
  `JSON_THROW_ON_ERROR` on `json_encode()` feeding `Crypt::encryptString()`
  (`Invoice`, `InvoiceService`, `DefaultPDFConnector`); `Closure`-typed cache
  callbacks in `CacheService`; narrowed `Model::find()` calls that widened to
  `Model|Collection` (`BillingService`, `GroupedPaymentService`,
  `TaxCalculationService`); a typed query-object shape in
  `InvoiceNumberingService` (plus a not-null guard so `createSeriesControl()`
  cannot return null); `wherePivot()` for a pivot-column filter; `->get()` before
  `pluck()` on aggregate aliases; explicit `string` casts for UUID ids and CLI
  options; and removed two phantom non-column keys (`tax_amount`, `metadata`)
  from `Invoice::create()` array literals. Two scoped, documented
  `@phpstan-ignore` remain: an Eloquent JSON-path `where()` (×2) and a
  pre-existing `AeatInvoiceValidator` debt (its `taxProfile` relation does not
  exist on `Invoice` — tracked as a follow-up).
- **PHPStan raised to level 6 (AID-277).** Added precise type annotations across
  `src/` — Eloquent relation generics (`HasMany<…, $this>`, `BelongsTo<…, $this>`),
  iterable value types, and missing return/parameter types — with no logic changes.
  First of three steps toward level 8 (6 → 7 → 8). One scoped, documented `ignoreError`
  covers a larastan limitation propagating the generic `CacheService` wrapper's type
  through `Cache::remember()`/`rememberForever()` (the public `callable` signature is
  kept; not a real type hole).

## [3.1.1] - 2026-06-28

### Changed

- **Grouped payments money columns are now `bigInteger` (AID-272).**
  `grouped_payments.amount` (the aggregate of N settled invoice totals) and
  `grouped_payment_invoice.applied_amount` move from `integer` to `bigInteger`.
  As a signed `integer` the aggregate capped at €21,474,836.47 (base-100); summing
  many invoices can plausibly approach that, so the aggregate columns get 64-bit
  headroom. Schema-only change applied in the create migrations (dev-main has no
  upgrade promise — fresh installs are born in `bigint`); the model API is
  unchanged (`FixedDecimalCast` works over the unscaled integer). `invoices.*`
  money columns stay `integer` — the cosmetic money-column unification remains out
  of scope (as noted for v2.0.0).

## [3.1.0] - 2026-06-28

### Added

- **Grouped payments (AID-30).** `GroupedPaymentService::register()` settles a set
  of issued invoices in one posted, idempotent payment; `reverse()` undoes it and
  restores each invoice's prior collection state. Idempotency (D2): replaying a key
  returns the same posted payment, while reusing a key with a different payload — or
  one already spent by a reversal — throws `IdempotencyConflictException`. Currency
  is validated (D3) against each invoice's effective fiscal config. New
  `grouped_payments` and `grouped_payment_invoice` tables (UUID v7 char(36) ids,
  base-100 integer money, `unique(grouped_payment_id, invoice_id)` and a
  NULL-permissive `unique(active_invoice_id)`). Settle and reverse are permitted on
  immutable invoices through dedicated collection-state methods (D1) without
  weakening the `Invoice::update()` immutability guard. Includes MySQL integration
  tests for the column types and unique constraints.

## [3.0.0] - 2026-06-26

**v3.0.0** — completes the "no decimals / no float in rate systems" program
(AID-237 → AID-240 → AID-242 → **AID-246**). The two large VAT rate models
(`VatCategory`, `CountryVatRate`) now expose `FixedDecimal` instead of their
legacy base-100 `int`/`float` API, the last blocker for the 3.0.0 tag. No schema
change: the columns were already `integer`/`json` base-100.

### Changed (BREAKING — VAT rate models, AID-246)

- `VatCategory::vat_rate` is now `FixedDecimal:2` (was a base-100 `int`). The
  statics `getVatRate()`, `getStandardRate()`, `getReducedRate()`,
  `getSuperReducedRate()` and the instance `getRate()` return `?FixedDecimal`.
  `findByRate(string, float)` takes the percentage and converts internally.
- `CountryVatRate::standard_rate` is now `FixedDecimal:2` (was a base-100 `int`).
  `reduced_rates` stays a raw base-100 integer JSON map; the semantic getters
  (`getRateForCategory()`, `getReducedRate()`, `getReducedRates()`,
  `getStandardRate()`, `getDefaultRateForCountry()`, `getAllRates()['standard']`)
  return `FixedDecimal`. `setReducedRate(string, FixedDecimal)` now takes a
  `FixedDecimal`.
- Removed the manual percentage/base-100 converters from both models
  (`percentageToBase100`, `base100ToPercentage`, `getVatRateAsPercentage`,
  `setVatRateFromPercentage`, `getStandardRateAsPercentage`,
  `setStandardRateFromPercentage`, `getRateForCategoryAsPercentage`,
  `getReducedRatesAsPercentages`, `getReducedRateAsPercentage`,
  `setReducedRateFromPercentage`): the `FixedDecimal:2` value already *is* the
  percentage.
- Range scopes/finders (`scopeByRate`, `findByStandardRateRange`,
  `findSimilarRates`) keep their `float` percentage signatures and convert
  internally to the stored base-100 integer for the WHERE.
- `VatCategory::isExempt()` now uses `vat_rate->isZero()` (fixing a latent
  `=== 0.0` comparison that never matched an integer column), and
  `DestinationVatService::getDestinationVatRate()` now returns the correct
  percentage on every branch (the `standard_rate`/`getRateForCategory` branches
  previously leaked the base-100 integer, e.g. `2100` instead of `21.0`).
- See `docs/upgrade-3.0-vat-rate-fixeddecimal.md`.

### Changed (BREAKING — Commission)

- `Commission::min_amount`/`max_amount` (renamed from `min_amount_base100`/
  `max_amount_base100`) are now `FixedDecimal:2` instead of plain base-100
  integers. `calculateAmount()` compares against them via `unscaledValue()`.
- Removed `Commission::rate_base100` (column + cast + fillable): it duplicated
  `rate`, which has been `FixedDecimal` since 2.0.0 and is the value
  `calculateAmount()` actually uses.

### Fixed (schema hygiene)

- Removed the orphan `tax_group_id` from `InvoiceItem::$fillable`: the table has
  no such column (it is a transient input resolved by `TaxCalculationService`),
  so a mass-assignment was silently dropped.

### Added

- `tests/Integration/Mysql/FillableSchemaConsistencyTest`: a MySQL guardrail that
  fails when a core model declares a `$fillable` column that does not exist in
  its table. Carries a documented allow-list of pre-existing `Invoice` orphans
  (`user_tax_info_encrypted`, `customer_data`, `fiscal_data`, `vat_verification`,
  residue of the ADR-003 refactor) tracked as a separate follow-up; the list may
  only shrink.

### Removed (BREAKING — AID-244)

- Removed the `CompanyConfig` model and its test: dead, table-less legacy of the
  ADR-001/003 refactor to `CompanyFiscalConfig`. It had no migration, no install
  order entry and zero consumers across the umbrella (including `larabill-filament`),
  so it was never functional. Consumers must use `CompanyFiscalConfig`.

### Fixed (latent fiscal/PDF bugs — AID-245)

- The four orphan `Invoice::$fillable` keys flagged by the AID-242 guardrail
  (`user_tax_info_encrypted`, `customer_data`, `fiscal_data`, `vat_verification`)
  were not just dead fillable: code read them, and because the columns never
  existed the reads always returned `null`. Removed them from `$fillable`/casts
  and fixed every reader to use the real ADR-003 sources:
  - **`EuSalesThresholdService::isEuSale()`** read `user_tax_info_encrypted` →
    always `null` → **every sale was treated as non-EU and the OSS/EU threshold
    never moved**. Now reads `Invoice::userTaxProfile->country_code`.
  - **`DomPDFService::isReverseCharge()`** read `fiscal_data['reverse_charge']` →
    always `false`. Now returns `Invoice::is_roi_taxed`.
  - **`DomPDFService::isExemptInvoice()`** read `fiscal_data['exempt']` → always
    `false`. Now reads `userTaxProfile->is_exempt_vat`.
  - **`DomPDFService::getClientData()`** returned hard-coded placeholder data
    behind a check on the always-null orphan. Now builds the client block from
    the `userTaxProfile` snapshot.
  - `DefaultPDFConnector` QR and `BillingService` no longer reference the orphans.
- The guardrail allow-list is now empty (a regression test covers the EU-threshold
  fix; the PDF flags are covered via reflection).
- **Reverse-charge / exempt PDF templates now render.** Fixing `isReverseCharge()`/
  `isExemptInvoice()` finally routes ROI / exempt invoices to
  `reverse-charge.blade.php` / `exempt.blade.php`, which had never rendered and
  still carried the `ucfirst($invoice->status)` enum bug (fixed in `fiscal.blade.php`
  back in 0.11.2) — now use `$invoice->status->label()`. Render-smoke tests added.

### Notes

- Still on the legacy base-100 API by design (deferred): `VatCategory.vat_rate`,
  `CountryVatRate.{standard_rate,reduced_rates}` (rate refactor — own PR, AID-246) and
  `TaxRate.rate` (base-10000 fiscal core — excluded).

## [2.0.0] - 2026-06-23

> **Note:** No `v2.0.0` git tag or GitHub release was ever cut. The work below
> shipped to Packagist under **[3.0.0]** (tags jump `v1.0.0` → `v3.0.0`); this
> section is retained for changelog continuity. Treat `[3.0.0]` as the release
> that delivered it.

Closes the **"no decimals in the database"** rule for the parallel money/rate
systems that 1.0.0 (`FixedDecimal` migration, AID-237) left untouched. Two real
`decimal` columns owned by the package are migrated to `integer` base-100 minor
units, and `EuSalesThreshold` moves from a `float` euro API to `FixedDecimal`.
This is a **breaking change** for `EuSalesThreshold` consumers. See
`docs/upgrade-2.0-eu-sales-thresholds.md`. Scope is deliberately limited to the
real violations (AID-240 plan, option C); the cosmetic unification of the
already-integer rate systems (`vat_categories`, `country_vat_rates`,
`commissions.*_base100`, `tax_rates`) is a separate follow-up.

### Changed (BREAKING — `EuSalesThreshold`)

- `total_amount` and `threshold_amount` now expose an immutable `FixedDecimal:2`
  instead of a `float`. Reading returns `FixedDecimal`; convert at the boundary
  with `->unscaledValue()` (base-100 cents), `->toDecimalString()`, or
  `->toFloat()` (lossy, display only).
- `breakdown_by_country` is now a JSON map of **integer cents per country**
  (`{"DE": 200000}` = €2,000.00), previously a map of euro floats. Per-country
  getters (`getSalesAmountByCountry()`, `getSalesForCountry()`) return
  `FixedDecimal`; the raw attribute stays integer cents.
- Mutators now require a `FixedDecimal`: `addAmount()`, `addSalesAmount()` and
  `addSalesForCountry()` take a `FixedDecimal` instead of a `float`.
- Aggregate getters expose `FixedDecimal`: `getThresholdStatistics()['total_sales_amount']`,
  `getTopCountriesBySales()[]['amount']`, `getSalesGrowthByCompany()` amounts,
  `getRemainingThresholdAmount()`, `calculateTotal()`,
  `EuSalesThresholdService::getThresholdStatus()['current_amount'|'threshold']`,
  and `EuSalesThresholdService::recalculateEuSales()`.
- Removed the float helper surface from `EuSalesThreshold`: `amountToBase100()`,
  `base100ToAmount()`, `getTotalAmountAsAmount()`, `getThresholdAmountAsAmount()`,
  `setTotalAmountFromAmount()`, `setThresholdAmountFromAmount()`.

### Changed (database — DB-only, no data migration)

- `commissions.rate`: `decimal(8,4)` → `integer`. The cast was already
  `FixedDecimalCast:2` (1.0.0); the column now matches it, removing the
  `9999.9999` overflow ceiling on fixed commissions above €99.99.
- `eu_sales_thresholds.total_amount` / `threshold_amount`: `decimal(15,2)` →
  `integer` base-100 minor units.
- `config('larabill.destination_vat.default_threshold')`: `10000.0` (euros) →
  `1000000` (base-100 minor units).

### Fixed

- **Euro-vs-cents threshold bug**: the legacy model fed `total_amount` in cents
  (from `taxable_amount->unscaledValue()`) but seeded `threshold_amount` from a
  euro-denominated config default, so ~€80 of sales falsely tripped a €10,000
  threshold. With both sides in base-100 minor units the comparison is correct;
  covered by a regression test.

### Verification

- MySQL integration test asserts `commissions.rate`,
  `eu_sales_thresholds.total_amount` and `threshold_amount` report `int`
  (`information_schema`), never `decimal`.

## [1.0.0] - 2026-06-22

Adopts lara100 v2.0.0's `FixedDecimal` value object for every monetary model
attribute. This is a **breaking change** for consumers and marks larabill's public
API as stable (1.0). See `docs/ADR-009-fixeddecimal-money-type.md` and
`docs/upgrade-1.0-fixeddecimal.md`.

### Changed (BREAKING)

- Monetary model attributes now expose an immutable `FixedDecimal` instead of a
  plain integer. Affected (8 models / 13 attributes): `Article::cost_price`,
  `ArticlePrice::price`, `ArticleOverride::custom_price`,
  `ArticleServiceStatus::effective_price`,
  `InvoiceItem::{quantity, unit_price, taxable_amount, total_tax_amount, total_amount}`,
  `Invoice::{taxable_amount, total_tax_amount, total_amount}`, `Commission::rate`,
  `TaxCategory::default_rate`.
- Reading one of these returns `FixedDecimal` (or `null`). Convert at the boundary:
  `->unscaledValue()` for the base-100 integer cents (the pre-1.0 value),
  `->toDecimalString()` for an exact decimal string, `->toFloat()` for a float
  (lossy — display only).
- Assigning one of these now requires a `FixedDecimal`; assigning a scalar throws
  `InvalidFixedDecimal`. Build with `FixedDecimal::ofUnscaled($cents, 2)`,
  `::ofDecimalString('12.34')`, or `::ofFloat(12.34, 2)`.
- Null semantics: `Base100Int` coerced `null → 0`; `FixedDecimalCast` preserves
  `null`. Only `Article::cost_price` is nullable among the migrated columns.
- **Database storage is unchanged**: columns remain `integer` storing the same
  base-100 cents. No column migration and no data migration are required.

### Fixed (EU/Spain rounding)

- The per-line taxable amount (`quantity × unit_price`) now settles to the cent
  with **HalfUp** (round half away from zero), per EU/Spain accounting rules,
  instead of truncating toward zero. This changes the computed amount by at most
  one cent on lines whose `quantity × unit_price` produced a fractional cent
  (e.g. 1.50 × €12.33 = €18.4950 → **€18.50**, previously €18.49). Tax amounts were
  already HalfUp and are unchanged.

### Dependencies

- `aichadigital/lara100`: `^1.2` → `^2.0`.

### Notes

- Out of scope (still raw base-100/base-10000 integers, by design): the parallel
  systems in `EuSalesThreshold`, `CompanyConfig`, `CountryVatRate`, `VatCategory`,
  and `Commission::{rate_base100, min_amount_base100, max_amount_base100}`.

## [0.12.1] - 2026-06-21

### Fixed

- `VerifactuAdapter::toVerifactuInvoice()` emitted `serie` as a raw int (from the
  int-backed `InvoiceSerieType` enum), which broke `VerifactuInvoice::getSerie()`
  (typed `?string`) with a `TypeError` during XML build — so no larabill invoice
  could be registered/submitted end-to-end. The serie is now cast to a string.
  Surfaced by the AID-136 consumer sandbox; same class of latent defect as the
  AID-129 recipient bug (the adapter → verifactu → XML path had never been
  exercised end-to-end).

### Documentation

- Backfilled release notes for two changes that shipped in **0.12.0** without an
  entry (the AID-136 work landed in the same tag and crowded them out):
  - **Legal retention contract (AID-221).** `Invoice` and `UserTaxProfile`
    implement `AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable`, exposing
    `retainedUntil(): ?DateTimeInterface` — the fiscal/accounting retention hold
    the privacy layer reads to block/restrict a record while it is legally kept.
    A fiscal invoice is held until the fiscal-year-end of its `invoice_date` plus
    `larabill.retention.fiscal_years` (default 6 — Código de Comercio art. 30); a
    proforma carries no hold. A `UserTaxProfile` is held for the latest hold among
    its invoices, computed even when the profile is soft-deleted. Adds the
    `aichadigital/lara-privacy-core: ^1.0` dependency.
  - **Immutable fiscal snapshots survive soft-delete (AID-222).**
    `Invoice::userTaxProfile()` and `Invoice::companyFiscalConfig()` now use
    `withTrashed()`, so soft-deleting a referenced `UserTaxProfile` /
    `CompanyFiscalConfig` no longer makes an already-issued invoice lose its
    historical fiscal identity — which had broken Verifactu validation with
    "Invoice must have a tax profile".

## [0.12.0] - 2026-06-21

### Added

- Intra-EU services support (AID-136). `VerifactuAdapter::toVerifactuBreakdowns()`
  now emits a single **N2** ("no sujeta por reglas de localización") breakdown for
  cross-border EU B2B service invoices to a VAT-registered customer, and
  `toVerifactuInvoice()` identifies foreign recipients through **IDOtro**
  (`recipient_nif = null`; `recipient_id_type` `02` for a valid NIF-IVA, `04`
  otherwise) instead of misemitting a foreign VAT as a Spanish `<NIF>` (AEAT rule
  1100). The N2 predicate is read solely from the immutable issuer/customer
  snapshots (both EU, issuer ≠ customer, customer VAT-registered), never from live
  `CompanyFiscalConfig::getActive()`.

### Changed

- Requires `aichadigital/lara-verifactu: ^1.0.0-rc2` (N2 + IDOtro emission,
  `InvoiceBreakdownContract::getCalificacion()`, `calificacion` column).
- `InvoiceVerifactuService::registerInvoice()` builds the full payload (invoice +
  breakdowns) and persists inside a transaction, so a guard rejection rolls back
  cleanly instead of leaving an orphan `VerifactuInvoice` row that would block the
  retry via `isRegistered()`.

### Removed

- `VerifactuAdapter` no longer emits the `operation_key` or `simplified` fields
  (both dropped from the lara-verifactu invoice model in 1.0); the
  `mapOperationKey()` helper was removed.

### Guards (fail-loud — out of scope for AID-136)

- Intra-EU **goods** (E5, art. 25), **OSS/IOSS** (régimen 17) B2C sales, N2 lines
  carrying real VAT (rule 1237), and incomplete reverse-charge invoices are
  rejected with a `ValidationException` rather than silently mapped to S1/E1.

## [0.11.2] - 2026-06-18

### Fixed

- Fiscal PDF: the Veri*Factu QR emitted by lara-verifactu as an `<svg>` root
  preceded by an XML declaration (`<?xml …?>`) was misclassified — neither the
  bare `<svg` nor the PNG data-URI branch matched — and dumped as **escaped
  text** instead of an image. A leading XML declaration is now stripped before
  SVG detection, so the persisted QR renders inline.
- `DomPDFService::renderTemplate()` swallowed any view render error and returned
  a plausible **mock invoice** (`TEST-001`), masking real failures. It now
  returns the mock only when no view layer is booted (pure-unit context); a
  genuine render failure is logged with invoice context and rethrown.
- `PDFService::generatePDF()` now throws instead of reporting `success: true`
  when the underlying render failed.

### Changed

- Fiscal PDF template renders the invoice status via the `InvoiceStatus::label()`
  accessor (was `ucfirst()` on the enum) and shows the Veri*Factu AEAT legend for
  fiscal-verification QRs.
- Tests: replaced the unit-only `FiscalQrRenderingTest` (synthetic object, string
  status, bare `<svg>`) with `FiscalPdfRealRouteTest`, which drives the
  production path (real `Invoice` + enum casts + XML-preamble QR) through
  `fiscalVerificationQrResult → prepareTemplateData → renderTemplate`.

## [0.11.1] - 2026-06-18

### Changed

- CI: refreshed pinned action SHAs (`shivammathur/setup-php`,
  `codecov/codecov-action`, the `mysql:9` service image — #33). No runtime
  code changes.

### Fixed

- Release hygiene: `v0.11.0` was tagged on a commit that predated the CI
  dependency bump (#33), so the published tag sat off the `main` history.
  `v0.11.1` re-tags from `main` to realign the release. The distributed
  Composer payload is identical to `v0.11.0`.

## [0.11.0] - 2026-06-17

### Added

- Larabill now syncs asynchronous lara-verifactu registration results back onto
  the source invoice, including the AEAT registry number, QR, hash, verification
  timestamp, and provider metadata.
- Fiscal PDFs can render the persisted Veri*Factu QR as SVG or PNG and include
  the AEAT verification URL when lara-verifactu provides it.

### Changed

- Immutable invoices now allow post-issue updates only for fiscal verification
  fields, so asynchronous AEAT registration data can be persisted without
  reopening invoice content for mutation.

## [0.10.0] - 2026-06-11

### Changed

- **lara-verifactu constraint raised to `^0.10`** (rectificative invoice
  support); `dev-main` branch-alias moved to `0.10.x-dev`.
- `VerifactuAdapter` rectification mapping (AID-135):
  - `rectification_type` now emits the AEAT `ClaveTipoRectificativaType`
    code `'I'` (incremental, por diferencias) instead of the invoice-type
    code `'R1'` that leaked into the field.
  - `metadata['rectified_invoices']` carries the rectified invoice
    reference (serie+series_number and issue date) consumed by
    lara-verifactu 0.10 to emit the AEAT `FacturasRectificadas` block.

## [0.9.4] - 2026-06-11

### Fixed

- `TaxCalculationService::calculateForInvoiceItem()` was a stub (the tax
  lookup was commented out as TODO): it always returned zero tax and no
  `taxes_applied`, so `InvoiceService::createInvoice()` produced invoices
  without VAT — and, downstream, incorrect exempt Verifactu breakdowns.
  It now resolves the tax group (explicit `tax_group_id` wins, otherwise
  the article's) and delegates to the configured strategy (AID-138).
- `InvoiceService::createInvoiceItem()` now persists the immutable
  `taxes_applied` snapshot and the item's `article_id`, and forwards an
  optional per-item `tax_group_id` override.
- `VatCalculationStrategy` emits integer tax amounts (base-100 invariant);
  it previously leaked floats from `round()` into `total_tax_amount` and
  the `taxes_applied` snapshot.

### Added

- Tests: tax resolution from article, explicit override, tax-free fallback,
  and the `InvoiceService::createInvoice` end-to-end regression asserting
  VAT totals and the persisted `taxes_applied` snapshot.

## [0.9.3] - 2026-06-11

### Fixed

- `VerifactuAdapter` classified invoices as F2 (simplified) by amount
  (< 400) regardless of recipient data. AEAT validation 1190 rejects F2
  records carrying a `Destinatarios` block, so any invoice with an
  identified recipient submitted as F2 was refused by the sandbox. The
  rule is now recipient-driven: identified recipient → F1 regardless of
  amount; F2 reserved for invoices without recipient data. Found during
  the AID-129 high-volume sandbox testing from Castris.

## [0.9.2] - 2026-06-11

### Fixed

- `InvoiceVerifactuService::validateForVerifactu()` resolved the non-existent
  `taxProfile` relation (the model defines `userTaxProfile`), so every invoice
  failed validation with "Invoice must have a tax profile" regardless of its
  data. Caught by the Castris consumer integration tests (AID-129); a
  regression test for the fully-valid invoice case is now part of the suite.

## [0.9.1] - 2026-06-11

Identical content to the intended 0.9.0 release. The `v0.9.0` tag was pushed by
mistake against the pre-merge `main` (0.8.4 content) and the repository tag
rules forbid deleting it — do not use `v0.9.0`; Composer resolves `^0.9` to
this release.

### Changed

- **lara-verifactu constraint raised to `^0.9`** (sandbox-validated beta). The verifactu
  bridge now targets the 0.9 schema and API; `dev-main` branch-alias moved to `0.9.x-dev`.
- `VerifactuAdapter::toVerifactuInvoice()` updated to the 0.9 native invoice schema:
  - Emits the consolidated `issue_datetime` (from `issued_at`, falling back to
    `invoice_date`) instead of the removed `issue_date`/`issue_time` pair.
  - Maps ROI-taxed invoices to operation key `04` (reverse charge) — `09` does not exist
    in `OperationTypeEnum` 0.9 and made the model cast throw.
  - No longer eager-loads the `customer`/`customerFiscalData` relations removed by
    ADR-003 (was throwing `RelationNotFoundException` at runtime).
- `VerifactuAdapter::toVerifactuBreakdown(InvoiceItem)` (one row per line item) replaced
  by `VerifactuAdapter::toVerifactuBreakdowns(Invoice)`: AEAT Desglose rows grouped by
  tax rate, with non-taxed items merged into a single exempt row, matching the 0.9
  breakdown schema (`tax_type`/`tax_rate`/`base_amount`/`tax_amount`/`exempt`).
- `InvoiceVerifactuService::createBreakdowns()` persists the grouped rows.

### Added

- Test coverage for the verifactu bridge (17 tests): adapter payload contract asserted
  against the native 0.9 `fillable` (anti-drift guard), base-100 conversions, F1/F2/R1
  mapping, ROI reverse charge, tax-rate grouping, exempt aggregation, and the service
  registration round-trip.

### Known limitations

- Reverse-charge (S2) and not-subject (N1/N2) operation qualifications are not emitted
  by lara-verifactu's XmlBuilder yet; ROI invoices currently produce an exempt breakdown
  row. To be revisited during the high-volume sandbox testing phase (AID-129).

## [0.8.4] - 2026-06-06

### Fixed

- Reconciled the six core tables whose published `.php.stub` had drifted structurally from
  their development `.php` — dev/tests validated a schema the installer did not ship:
  - `company_fiscal_configs` — the stub created a stale `fiscal_settings` table (dropped by
    a later migration), leaving consumers without the table the model needs.
  - `tax_rates` — the stub stored `rate` as `decimal(5,4)`, breaking the base-100 integer
    invariant; restored to the int base-100 schema.
  - `invoice_items` — the stub used the pre-v0.3.3 single-tax columns
    (`tax_rate`/`tax_category_id`/`tax_amount`); restored to the immutable tax snapshot
    (`total_tax_amount`/`taxes_applied`).
  - `company_template_settings` — the stub used `string` columns instead of the
    tinyint-backed enums.
  - `invoices` — restored the missing `billable_user_id` index.
  - `article_prices` — comment alignment.

### Added

- `bin/sync-migration-stubs` — internal script that regenerates every `.php.stub` from its
  timestamped `.php` source. The `.php.stub` is now a derived artifact, never hand-edited.
- `docs/ADR-007-stub-derived-from-php.md` — records the decision: the timestamped `.php` is
  the single source of truth; the published `.php.stub` is a byte-identical derived artifact.

### Changed

- `MigrationOrderConsistencyTest` now enforces byte-for-byte identity between each
  `.php.stub` and its `.php` (`LARABILL_KNOWN_SCHEMA_DIVERGENCES` is empty, no longer a
  frozen skip-list). Any drift fails CI with an actionable message: run `bin/sync-migration-stubs`.
- Documentation: removed stale "Filament 4" references (the package is framework-agnostic)
  and corrected residual `int|uuid|ulid` agnostic wording to UUID-first (ADR-006) across
  `CLAUDE.md`, `AGENTS.md`, and `CONTRIBUTING.md`.

## [0.8.3] - 2026-06-05

### Fixed

- Added the missing dedicated `.php.stub` for the `add_article_id_to_invoice_items_table`
  migration introduced in v0.8.2 (the install command had been resolving it through the
  timestamped-`.php` fallback). Also normalized five pre-existing migrations that were
  likewise missing their `.php.stub` (`create_tax_groups_table`,
  `create_tax_group_tax_rate_table`, `create_commissions_table`,
  `add_converted_fields_to_invoices_table`, `make_articles_translatable`).
  `LarabillInstallCommand::$migrationOrder` now maps 1:1 to dedicated stubs.

### Added

- `tests/Unit/Console/MigrationOrderConsistencyTest.php` — CI guardrail enforcing the
  migration contract: every `$migrationOrder` entry must have a dedicated `.php.stub`,
  no orphan stubs, and no new structural drift between a table's `.php` and `.php.stub`.
- `AGENTS.md` — cross-agent hard-rules anchor (read by Codex/Cursor/etc.) centered on the
  migration contract and UUID-first invariants.

### Changed

- Removed internal/exploratory documentation (analyses, work orders, AI-agent context,
  operational notes, internal plans) from the distributed package. Only stable reference
  docs remain in `docs/`: `ARCHITECTURE.md`, `QUEUE_AND_RECURRING_BILLING.md`,
  `TAX_RATES_MIGRATION_GUIDE.md`, `setup-uuid.md`, and ADR-003/004/006.

### Known issues

- Six core tables have a development `.php` migration that diverges structurally from
  their published `.php.stub`. The new guardrail freezes this set so it can only shrink;
  reconciliation is tracked for a dedicated ADR.

## [0.8.2] - 2026-06-05

### Fixed

- Corrected `ArticleServiceStatus` enum property docs for `status` and
  `cancellation_type` so Larastan sees the casted enum types.
- Allowed `InvoiceNumberingService::generateNumber()` to accept UUID string
  user IDs.
- Added generic `Builder<static>` PHPDocs to `UserTaxProfile` scopes so chained
  scope calls keep the concrete model type.
- Added nullable `invoice_items.article_id` migration support for the existing
  `InvoiceItem::$article_id` contract.
- Removed an unreachable duplicate return from `Invoice::items()` and restored
  the malformed `InvoiceItem::invoice()` PHPDoc.

### Added

- Added `Invoice::isDraft()`.
- Added `Article::invoiceItems()`.
- Added `UserTaxProfile::invoices()`.

### Changed

- Updated the Composer `dev-main` branch alias to the `0.8.x-dev` series.

## [0.8.1] - 2026-06-04

### Fixed

- `InvoiceSeriesControlFactory` defaulted `number_format` to lowercase
  `{{prefix}}-{{year}}-{{number}}`, but `InvoiceNumberingService::formatNumber()`
  only substitutes UPPERCASE placeholders — a factory-created series emitted
  literal `{{prefix}}...` as the invoice number. Aligned the factory default to
  `{{PREFIX}}-{{YEAR}}-{{NUMBER}}` (matching the service, the column default and
  the documented convention). Added a regression test.

## [0.8.0] - 2026-05-10

### Breaking changes — UUID-first

This release retires the public pretension of supporting `int`/`uuid`/`ulid`
for the consumer's `users.id` and adopts **UUID v7 char(36) as the sole
contract** (ADR-006). `dev-main` did not promise upgrade across versions, so
no migration path is provided — consumers must already be on UUID or convert
before installing v0.8.0.

### Removed

- **Config**: `larabill.user_id_type` (and the `LARABILL_USER_ID_TYPE` env var
  it read) is gone from `config/larabill.php`. Setting it in `.env` now has
  no effect.
- **Helper**: `MigrationHelper::getUserIdType()`, `detectUserIdType()`,
  `getIdTypeDescription()`, `isSupportedIdType()`. The helper is reduced to
  `userIdColumn()` + `agnosticIdColumn()`, both emitting UUID v7 char(36)
  unconditionally.
- **Command**: `larabill:detect-user-id` (`DetectUserIdTypeCommand`) deleted.
- **CLI flag**: `larabill:install --user-id-type=` removed.
- **Tests**: `MigrationHelperEnhancedTest`, `DetectUserIdTypeCommandTest`, and
  the `->with(['int','uuid','ulid'])` matrix of `FreshInstallUserIdTypeTest`
  (renamed to `FreshInstallTest.php` with a single UUID case).

### Changed

- **Install command**: `larabill:install` runs a UUID-only preflight check on
  `users.id`. Aborts with an actionable message pointing to
  `docs/setup-uuid.md` and `docs/ADR-006-uuid-first-no-agnostic.md` if the
  column is not UUID-compatible.
- **Tests fixtures (Camino B)**: every hardcoded `'user_id' => 1`,
  `'user-123'`, `'company-123'`, etc. has been replaced by the deterministic
  UUID v7 constants `TestCase::USER_UUID_1/2/3`. Test migrations for `users`
  and `test_users` now use `$table->uuid('id')->primary()`. `TestUser` and
  `Tests\Models\User` use `HasUuids`. A new
  `tests/Database/Factories/UserFactory.php` binds the UUID-keyed test User
  model to factory creation.
- **DTO**: `AuditEntry::$userId` is now `?string` (was `?int`).
- **Docs**: README, SCHEMA_REQUIREMENTS, CONTRIBUTING, `.claude/CRITICAL_RULES`,
  `docs/AGENT_CONTEXT`, and CLAUDE.md rewritten as UUID-first; the prior
  `int|uuid|ulid` agnostic narrative is removed. `setup-uuid.md` is the new
  canonical onboarding guide for consumer apps.
- **Test infrastructure**: `tests/TestCase` now forces `cache.default = array`
  to keep `Cache::flush()` stable when tests run in arbitrary order against
  SQLite in-memory.

### Added

- **ADR**: `docs/ADR-006-uuid-first-no-agnostic.md` — canonical rationale for
  retiring agnosticism. Referenced by `~/development/packages/aichadigital/STANDARDS.md`
  STD-001.
- **SPEC**: `docs/2026-05-10-spec-uuid-first-implementation.md` — the
  implementation plan executed in this release.
- **Setup guide**: `docs/setup-uuid.md` — consumer-facing 4-step guide from
  `laravel new` to `larabill:install`.
- **SUPERSEDED banner** on `docs/2026-05-09-fresh-install-agnostic-mysql.md`,
  preserved as a record of the intermediate `int|uuid|ulid` MySQL contract
  that bridged v0.7.4 and v0.8.0.

### Migration guide

Larabill `dev-main` does not promise schema upgrades. To move an installation
to v0.8.0:

1. Ensure your `users.id` is UUID v7 char(36) and your User model uses
   `HasUuids`. Follow `docs/setup-uuid.md`.
2. Drop the database and reinstall: `php artisan migrate:fresh && php artisan larabill:install`.

If your app is already on UUID (the only configuration ever exercised in
production), the upgrade is a no-op other than removing
`LARABILL_USER_ID_TYPE` from `.env` (its value is now ignored).

## [0.7.4] - 2026-05-09

### Added

- **Tests**: New `tests/Integration/Mysql/` suite covering the agnostic fresh-install contract on real MySQL 8 — one Pest test with dataset `[int, uuid, ulid]` verifies that the full migration set runs cleanly via `artisan migrate`, that agnostic `customer_id` and `user_id` columns reflect the configured type (`bigint` / `char(36)` / `char(26)`), that composite UNIQUE indexes are preserved with `customer_id` at position 0, and that uniqueness is actively enforced after install
- **Tests**: `MysqlIntegrationTestCase` opt-in harness — extends `Orchestra\Testbench\TestCase` directly, requires `LARABILL_TEST_MYSQL_*` env vars, marks tests as `skipped` with an actionable message when not configured, and uses `SET FOREIGN_KEY_CHECKS=0` for FK-safe drops
- **CI**: New `mysql-integration` job (PHP 8.3 + Laravel 12 + MySQL 8 service container) running only the new MySQL suite — the existing 4-job SQLite matrix is untouched
- **Docs**: `docs/2026-05-09-fresh-install-agnostic-mysql.md` formalising the contract `dev-main` actually promises — fresh install agnostic on MySQL for `int`/`uuid`/`ulid`. Schema upgrades across `dev-main` versions are explicitly NOT promised at this stage (use `migrate:fresh`)
- **Docs**: `CONTRIBUTING.md` section explaining how to run the MySQL suite locally with Docker

### Removed

- **Migrations**: Deleted `database/migrations/2026_05_08_000001_repair_article_customer_id_columns.php` and its stub `repair_article_customer_id_columns.php.stub`. Retired the `'032'` entry in `LarabillInstallCommand::$migrationOrder`. The migration was best-effort with no test coverage and communicated an upgrade promise that pre-v1.0 `dev-main` does not assume

### Changed

- **Pest config**: `tests/Pest.php` binds `MysqlIntegrationTestCase` to `Integration/Mysql/` and restricts the global `TestCase + RefreshDatabase` binding to explicit paths (Pest cannot rebind a folder once a subpath is bound)
- **Docs**: `docs/2026-05-09-blocker-upgrade-test-customer-id-bigint-to-uuid.md` marked SUPERSEDED with banner pointing to the new fresh-install doc; preserved as a record of the corrected reframe
- **Docs**: `docs/AGENT_CONTEXT.md` "Known Active Blocker" section replaced by "Active Contract" describing what the package now demonstrates
- **Docs**: `CLAUDE.md` repo entry-point: required reading reordered, anti-patterns updated (no `repair_*` migrations without tests; SQLite suite does NOT prove agnostic schema), "Estado actual" reflects the reframe

## [0.6.1] - 2026-02-16

### Fixed

- **Config**: `config/larabill.php` referenced test User model (`Tests\Models\User`) instead of `App\Models\User` in published config
- **Config**: Added top-level `user_model` config key — all code reads `config('larabill.user_model')` but the key was never defined in config (worked only via fallback)
- **InvoiceSeriesControl**: Wrong fallback for `user_model` config pointed to test class
- **Migrations**: Applied Pint formatting and added 3 missing `.php.stub` files for alteration migrations
- **Install command**: Removed 6 ghost entries from `$migrationOrder` that referenced non-existent stubs

### Changed

- **Migrations**: Converted 6 stub-only migrations to timestamped `.php` files for auto-loading in development

### Added

- **Tests**: MigrationHelper unit tests covering uuid, int, and ulid ID types
- **Docs**: Updated CONTRIBUTING.md and SCHEMA_REQUIREMENTS.md with migration pattern rules

## [0.6.0] - 2026-02-15

### Added

- **InvoiceNumber Value Object**: New VO for type-safe invoice number handling
- **InvoiceNumberingService**: Returns `InvoiceNumber` VO instead of raw strings
- **LegalEntityTypesSeeder**: Added `is_company` field support

### Changed

- **ADR-004**: Renamed `user_id` to `owner_user_id` in `user_tax_profiles` table
- **Install command**: Respect config priority and prevent migration duplicates (#12)
- **Migrations**: Use `MigrationHelper` for `billable_user_id` column

### Deprecated

- **BillingService**: Legacy numbering methods deprecated, removal target v0.7.0

### Removed

- **Filament dependency**: Package is now fully framework-agnostic (no Filament coupling)

### Documentation

- Added CONTRIBUTING.md with migration pattern standards
- Updated SCHEMA_REQUIREMENTS.md to version 2.1

## [0.5.0] - 2026-02-01

### Breaking Changes

- **ADR-001**: CompanyFiscalConfig replaces FiscalSettings — complete fiscal model refactor
- **ADR-002**: Migrated from binary UUID to string UUID v7 (native Laravel `Str::orderedUuid()`)
- **ADR-003**: Customer/User unification — `CustomerFiscalData` merged into `UserTaxProfile`
- **All enums**: Migrated from string-backed to int-backed, removed MySQL `enum()` usage

### Added

- **ADR-001**: `CompanyFiscalConfig` and `CustomerFiscalData` fiscal models with temporal validity
- **ADR-003**: `FiscalIntegrityChecker` service for fiscal change detection during proforma conversion
- **Article pricing**: Frequency-based pricing system (monthly, quarterly, annual, one-time)
- **Translatable articles**: Name and description fields support `spatie/laravel-translatable`
- **HasUserRelation trait**: UUID binary + Filament compatibility for user relationships
- **VatVerification**: Added `verified_at` field and soft deletes
- **Base100Int**: Migrated all monetary values to base-100 integer storage via `lara100` package
- **Verifactu integration**: Adapter services and VAT validation methods
- **Invoice methods**: `calculateTotals()` and tax validation
- **Install UX**: Improved `larabill:install` experience for production environments

### Fixed

- Migration ordering for foreign keys (invoices FK extracted to separate migration)
- Duplicate columns in incremental migrations
- Agnostic `user_id` types via `MigrationHelper::userIdColumn()` in all migrations
- CI compatibility for migration auto-loading

### Testing

- 953 tests passing (2754 assertions)
- Test coverage increased from 50.3% to 53.9%
- Comprehensive ADR-001 fiscal model tests

## [0.4.0-alpha] - 2025-01-17 (WIP)

### 🚀 **MAJOR REFACTOR**: Agnostic Billable Entity System

This is a **breaking change** release that fundamentally restructures the billing system to be agnostic to the billable entity, replacing the rigid User coupling with a flexible Customer entity.

---

### 🔥 **BREAKING CHANGES**

#### 1. **Customer Entity Replaces User Coupling**
- **Old**: Invoices were tightly coupled to `User` model
- **New**: Invoices are issued to `Customer` entities

**Migration Required**:
```bash
php artisan larabill:migrate-to-v040
```

#### 2. **New Core Tables**
- `legal_entity_types` - Flexible entity types (person, company, public entity)
- `issuer_config` - Singleton issuer configuration
- `issuer_tax_profiles` - Historical issuer fiscal data
- `customers` - Billable entities (replaces direct User link)
- `customer_tax_profiles` - Historical customer fiscal data  
- `commissions` - Multi-level commission system

#### 3. **Invoice Schema Changes**
- Added: `customer_id` (replaces various user fields)
- Added: `issuer_snapshot` (encrypted issuer fiscal data)
- Added: `customer_snapshot` (encrypted customer fiscal data)
- Added: `fiscal_snapshot` (encrypted tax context)
- Added: `fiscal_verification_id`, `fiscal_verification_qr`, `fiscal_verification_hash`
- Added: `converted_invoice_id` (for proforma conversion tracking)
- Added: `is_immutable` (locks proforma after conversion)

---

### ✨ **Added**

#### **Core Architecture**

**Single Issuer Model**  
Only one entity issues invoices (your company). Supports:
- Historical tracking of issuer identity changes
- Audit trail for legal name, tax ID changes
- Singleton pattern for active issuer configuration

**Agnostic Customer Entity**  
Flexible billable entity supporting:
- `relationship_type`: self, self_company, client, other
- Multiple fiscal identities per User
- Any legal entity type (person, company, public entity)

**Immutable Invoice Snapshots**  
Encrypted JSON snapshots capturing fiscal context at invoice time:
- Issuer fiscal data (legal name, tax ID, address)
- Customer fiscal data (name, tax ID, ROI verification)
- Tax context (rates, thresholds, ROI status, OSS)

#### **Models**

**New Models**:
- `LegalEntityType` - Flexible entity types with fiscal requirements
- `IssuerConfig` - Singleton issuer configuration
- `IssuerTaxProfile` - Historical issuer fiscal profiles
- `Customer` - Agnostic billable entity (replaces rigid User coupling)
- `CustomerTaxProfile` - Historical customer fiscal profiles
- `Commission` - Multi-level commission system

**Model Features**:
- Full Eloquent relationships
- Soft deletes support
- Comprehensive scopes (active, by type, by level)
- Factory support for testing

#### **Services**

**InvoiceService** (Refactored):
- `createInvoice()` - Creates invoice with encrypted snapshots
- `createProforma()` - Creates proforma invoice
- `convertProformaToInvoice()` - Converts proforma to final invoice with locking
- `createInvoiceItem()` - Creates invoice items with tax calculation
- `verifyInvoiceFiscally()` - Triggers fiscal verification via contract

**CommissionCalculationService** (New):
- Multi-level commission support (global, product group, product)
- Priority system (product > group > global)
- Date range validation
- Percentage and fixed amount types

**TaxCalculationService** (Updated):
- Integration with Customer and IssuerConfig context
- Support for encrypted snapshots

#### **Contracts & Testing**

**FiscalVerificationContract**:
- Interface for fiscal verification integrations
- Allows external packages (lara-verifactu, etc.) to implement
- Decoupled from core billing logic

**FakeFiscalVerification**:
- Test double for fiscal verification
- No external dependencies required for testing

#### **Migrations**

**New Migrations**:
- `2025_01_25_000001_create_legal_entity_types_table`
- `2025_01_25_000002_create_issuer_config_table`
- `2025_01_25_000003_create_issuer_tax_profiles_table`
- `2025_01_25_000004_create_customers_table`
- `2025_01_25_000005_create_customer_tax_profiles_table`
- `2025_01_25_000006_create_commissions_table`
- `2025_01_25_000007_add_v040_fields_to_invoices_table`

---

### 🔧 **Fixed**

#### **Migration System**
- Fixed duplicate index creation in `customers` table
- Resolved "index already exists" error via TDD approach
- Unified migration loading in tests
- Cleaned duplicate migrations from test directory

#### **PHPStan**
- Fixed covariance errors in factories
- Added missing PHPDoc properties
- Corrected Faker method calls

#### **CI/CD**
- Added VCS repositories for private packages (lara-verifactu, lararoi)
- Fixed Composer installation in GitHub Actions

#### **Enums**
- Added `PENDING` and `CONVERTED` statuses to `InvoiceStatus`

---

### 📚 **Documentation**

**New Documents**:
- `REFACTOR_ARQUITECTÓNICO-LARABILL-v0.4.0.md` - Architecture specification
- `REFACTOR_V040_PROGRESS.md` - Implementation progress
- `TAX_SYSTEM_ANALYSIS_AND_RECOMMENDATIONS.md` - Tax system analysis

**Updated**:
- README (pending)
- CHANGELOG (this file)

---

### 🧪 **Testing**

**Test Suite Status**: 640/913 tests passing (70%)

**New Tests** (55 total):
- Model tests (34): Customer, IssuerConfig, Commission, etc.
- Service tests (16): InvoiceService, CommissionCalculationService
- Integration tests (5): Complete billing flows

---

### 🎯 **Migration Guide**

#### **For Package Users**

1. **Update composer.json**:
```bash
composer require aichadigital/larabill:^0.4.0-alpha
```

2. **Run migrations**:
```bash
php artisan migrate
```

3. **Seed initial data**:
```bash
php artisan db:seed --class=LarabillSeeder
```

4. **Migrate existing data** (if upgrading):
```bash
php artisan larabill:migrate-to-v040
```

5. **Update code**:
- Replace `Invoice::create(['user_id' => ...])` with `Invoice::create(['customer_id' => ...])`
- Create `Customer` entities for your users
- Update fiscal verification integration (if using lara-verifactu)

---

### ⚠️ **Deprecations**

The following will be removed in v1.0.0:
- Direct `user_id` on invoices (use `customer_id`)
- Old `UserTaxProfile` model (use `CustomerTaxProfile`)

---

### 🚀 **Roadmap**

**v0.4.1**:
- Complete service implementation
- Fix remaining test failures

**v0.5.0**:
- Production-ready
- Complete documentation
- Migration command

**v1.0.0**:
- Stable API
- Remove deprecations
- Full Laravel 12 support

---

## [0.3.4] - 2025-01-13

### 🎯 Tax Rates System Refactor

#### Changed
- **Unified Tax Rates Migration**: Eliminated duplicate `tax_rates` migration for clarity and consistency
  - Removed: `2024_12_01_000006_create_tax_rates_table.php` (conflicting duplicate)
  - Enhanced: `2024_12_01_000000_create_tax_rates_table.php` with new features
  - **Action Required**: Users who published migrations must delete the duplicate (see migration guide)

#### Added
- **SoftDeletes Support**: Tax rates now use Laravel's native soft deletion
  - `deleted_at` column for "deleting" rates without losing historical data
  - Automatic filtering of deleted rates in queries
  - Easy restoration with `restore()` method
  - **Breaking**: Replaces custom `is_active` field with Laravel standard
- **Special Conditions (JSON)**: New metadata field for complex tax rules
  - Perfect for Spanish special territories (Canarias, Ceuta, Melilla)
  - Stores exemptions, territory types, special notes
  - Flexible JSON structure for any custom metadata
- **Enhanced Territory Support**: Better handling of Spanish special tax territories
  - Canary Islands (IC): IGIC tax system (7% / 3%)
  - Ceuta (CE): IPSI tax system (0% for digital services)
  - Melilla (ML): IPSI tax system (0% for digital services)
  - Full metadata in `special_conditions` field

#### Improved
- **TaxRatesSeeder**: Completely refactored for consistency
  - Now uses unified structure: `name`, `rate`, `region`, `type`, `special_conditions`
  - Comprehensive EU countries coverage (10 countries)
  - Spanish special territories with full metadata
  - Removed incompatible `country_code`, `tax_name` structure
- **TaxRate Model**: Enhanced with new features
  - Added `SoftDeletes` trait
  - New `special_conditions` cast to array
  - Updated PHPDoc with `deleted_at` property
- **TaxRateFactory**: New helper method
  - `withSpecialConditions(array $conditions)` state for testing
  - Default `special_conditions => null` in definition
- **Test Coverage**: Migration tests updated
  - Test database migration synchronized with main migration
  - All 856 tests passing (100%)

#### Documentation
- **New Guide**: `docs/TAX_RATES_MIGRATION_GUIDE.md`
  - Complete migration instructions for existing users
  - SoftDeletes usage examples
  - Special conditions examples (Canarias, Ceuta, Melilla)
  - Troubleshooting section
- **Comprehensive Analysis**: `docs/TAX_SYSTEM_ANALYSIS_AND_RECOMMENDATIONS.md`
  - 1,500+ lines of technical analysis
  - Comparison of old vs new structure
  - Spanish/EU requirements analysis
  - Decision matrix and recommendations

#### Migration Guide for Users
**If you haven't published migrations**: Nothing to do, just update the package.

**If you published migrations (v0.3.3 or earlier)**:
1. Delete duplicate: `rm database/migrations/*000006_create_tax_rates_table.php`
2. For production with data: Create ALTER TABLE migration to add new columns
3. For development: Use `migrate:fresh`

See `docs/TAX_RATES_MIGRATION_GUIDE.md` for detailed instructions.

---

## [0.3.0] - 2025-01-13

### Breaking Changes

- **User ID Agnostic Architecture**: All foreign key references to `User` model now support multiple ID types
  - Migrations no longer hardcode `unsignedBigInteger` for `user_id` columns
  - Package now supports: `int`, `uuid`, `uuid_binary`, `ulid`, `ulid_binary`
  - **Action Required**: Run `php artisan larabill:detect-user-id` before migrating
  - **Migration**: Existing installations with `int` user IDs work without changes (default)
  - **Breaking**: If using custom user ID types, must configure `LARABILL_USER_ID_TYPE` in `.env`

### Added

- **MigrationHelper**: New helper class for agnostic user ID column creation
  - `MigrationHelper::userIdColumn()` - Adds user_id with auto-detected type
  - `MigrationHelper::detectUserIdType()` - Auto-detects User model ID type from database
  - `MigrationHelper::getUserIdType()` - Gets configured or detected ID type
  - Supports MySQL, PostgreSQL, and SQLite
- **Detection Command**: New `larabill:detect-user-id` Artisan command
  - Auto-detects User model ID type from existing database
  - Displays detected type with detailed description
  - Can automatically update `.env` file with `--update-env` flag
  - Validates configuration and provides manual instructions
- **Configuration**: New `user_id_type` config option in `config/larabill.php`
  - Environment variable: `LARABILL_USER_ID_TYPE`
  - Default: `int` (standard Laravel)
  - Supports: `int`, `uuid`, `uuid_binary`, `ulid`, `ulid_binary`

### Changed

- **All Migrations Updated**: Now use `MigrationHelper::userIdColumn()` instead of hardcoded types
  - `create_invoices_table.php`
  - `create_user_tax_infos_table.php`
  - `create_company_fiscal_configs_table.php`
  - `create_company_template_settings_table.php`
  - Migration stubs updated
- **Removed Duplicate Indexes**: `user_id` index now added automatically by MigrationHelper
- **Documentation**: Added comprehensive docs for User ID type configuration

### Removed

- **Incomplete PDF tests**: Removed 2 empty/skipped PDF generation tests
  - Removed `InvoiceIntegrationTest::can generate PDF for invoice`
  - Removed `InvoiceManagementFeatureTest::can generate PDF for invoices`
  - **Justification**: Empty tests that duplicated existing unit test coverage
  - **Coverage maintained**: Full PDF testing in `PDFServiceTest` and `DomPDFServiceTest` (16 tests)

### Migration Guide for 0.3.0

#### For New Installations

```bash
# 1. Auto-detect your User ID type
php artisan larabill:detect-user-id --update-env

# 2. Run migrations
php artisan migrate
```

#### For Existing Installations with Integer User IDs

No changes needed! Default is `int`.

#### For Projects with UUID/ULID User IDs

```bash
# Before migrating Larabill tables
php artisan larabill:detect-user-id --update-env

# Or manually add to .env:
LARABILL_USER_ID_TYPE=uuid_binary  # or uuid, ulid, ulid_binary
```

#### Supported User ID Types

| Type | Description | Database Column | Use Case |
|------|-------------|-----------------|----------|
| `int` | Standard Laravel | `unsignedBigInteger` | Default, most common |
| `uuid` | UUID string | `char(36)` | Human-readable UUIDs |
| `uuid_binary` | UUID binary | `binary(16)` | Most efficient UUID (recommended) |
| `ulid` | ULID string | `char(26)` | Sortable, human-readable |
| `ulid_binary` | ULID binary | `binary(26)` | Sortable, efficient |

### Testing

- ✅ All 453 tests passing
- ✅ 0 PHPStan errors (level 6)
- ✅ 100% code style compliance (Pint)
- ✅ Auto-detection tested on MySQL, PostgreSQL, SQLite

## [0.2.0] - 2025-01-13

### Breaking Changes

- **Removed deprecated `CompanyConfigService`**: All functionality has been migrated to `FiscalSettings` model
  - Use `FiscalSettings::getOrCreateForUser()` instead of `CompanyConfigService::getCurrentConfig()`
  - Use `FiscalSettings` model methods directly instead of service methods
- **Removed deprecated methods from `VatVerification` model**:
  - Removed `findByVatNumber()` - use `whereVatCode()` scope instead
  - Removed `findByVatNumberAndCountry()` - use `findByVatCodeAndCountry()` instead

### Added

- **Binary UUID Relationship Support**: Implemented `BinaryUuidBuilder` to enable full Eloquent relationship support when using `EfficientUuid` cast
  - Custom query builder automatically converts UUID strings to binary in WHERE and WHERE IN clauses
  - Fixes relationships (`belongsTo`, `hasMany`) for models using binary UUID storage
  - Zero performance penalty for non-UUID queries
  - Applied to `Invoice` and `InvoiceItem` models
- **Auto-apply Destination VAT**: `FiscalSettings::checkThreshold()` now automatically enables `apply_destination_iva` when threshold is exceeded and `auto_apply_destination` is true

### Changed

- **Migration**: `invoice_items.invoice_id` column changed from `uuid()` (char 36) to `binary(16)` for consistency with `Invoice` model's binary UUID storage
- **Enhanced `FiscalSettings` model**:
  - Added `incrementEuSales()` method for updating EU sales amounts
  - Improved `checkThreshold()` to auto-enable destination VAT when configured
- **Refactored Integration Tests**: All tests in `VatSystemIntegrationTest` now use `FiscalSettings` directly instead of deprecated service

### Fixed

- **UUID Binary Relationships**: Fixed `Invoice` ↔ `InvoiceItem` relationship queries when using binary UUID storage
- **EU Sales Threshold**: Fixed auto-application of destination VAT when threshold is exceeded

### Removed

- `CompanyConfigService` class and all its methods
- `CompanyConfigServiceTest` test file
- Deprecated `findByVatNumber()` and `findByVatNumberAndCountry()` methods from `VatVerification`

### Testing

- ✅ All 453 tests passing
- ✅ 0 PHPStan errors (level 6)
- ✅ 100% code style compliance (Pint)
- ✅ Binary UUID relationships fully tested and working

### Migration Guide

If you were using `CompanyConfigService`:

```php
// ❌ Old (deprecated)
$config = app(CompanyConfigService::class)->getCurrentConfig();

// ✅ New
$config = FiscalSettings::getOrCreateForUser($userId, $fiscalYear);
```

If you were using deprecated `VatVerification` methods:

```php
// ❌ Old
$verification = VatVerification::findByVatNumberAndCountry($vat, $country);

// ✅ New
$verification = VatVerification::findByVatCodeAndCountry($vat, $country);
```

For Binary UUID relationships, add to your models:

```php
use AichaDigital\Larabill\Database\Query\BinaryUuidBuilder;

public function newEloquentBuilder($query)
{
    return new BinaryUuidBuilder($query);
}
```
