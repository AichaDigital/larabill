# Upgrading larabill 6.x → 7.0

## TL;DR

larabill **7.0 reshapes the agreement model** (ADR-015): the closed `BillingFrequency` cadence becomes a unit + quantity model, service states become a five-state lifecycle with a table-backed cause vocabulary, every contracted service gains modality and renewal attributes, and the package gains a guarantee refund window, a configurable plan-change policy and import-support columns.

**It ships migrations: yes — 8 of them (040–047).** They transform existing data (cadence re-key, status program, renewal backfill, deferred-closure carry-over) and loud-abort — leaving every table byte-identical — when they find data they refuse to interpret. **Your 6.x application code keeps working**: the retired public surface ships through 7.0 as a deprecated compatibility shim (see "The deprecated shim layer" below); the shims are removed in **8.0**.

> **⚠️ Dated correction (2026-10-26, AID-1778 / ADR-015 Enmienda 2) — this document's removal promise is superseded, not rewritten:** the shims were NOT removed in 8.0 — the retirement was deferred to **9.0**, where it shipped (see [UPGRADE-9.0.md](UPGRADE-9.0.md)). The exception within that retirement is the **`BillingFrequency` family** — `BillingFrequency`, `BillingInterval::toLegacyFrequency()` and `Article::getAvailableFrequencies()` — which 7.0 left alive and without a deprecation tag: it is deprecated since 9.0.0 with removal in **10.0**, honouring the one-full-major window of STABILITY.md. The rest of this guide is kept verbatim as the 7.0-era record.

## The one upgrade ritual

```bash
composer update aichadigital/larabill
php artisan larabill:install   # idempotent: publishes only NEW migrations, never overwrites config
php artisan migrate
```

This is the ONE upgrade mechanism of the package, unchanged from 6.x (STABILITY.md §2). `migrate:fresh` is never part of the path — it destroys your data and is never needed here.

Two practical notes:

- **The migrations are preflighted and loud-abort.** Before any DDL, each data migration checks your existing rows and refuses with an actionable error if it finds data it will not interpret (weekly cadences, out-of-domain values, importer-shadow rows — full list below). An aborted migration leaves the schema and data untouched; remediate as the error text instructs and re-run `php artisan migrate`.
- **Order matters and is handled for you**: the migrations run in dependency order (cadence re-key 040/041 → cause vocabulary 042/043 → status program 044 → modality 045 → guarantee 046 → import 047). `larabill:install` publishes them in that order.

## BREAKING changes, grouped by area

### Cadence (AID-952)

- **`billing_frequency` is gone from both `article_prices` and `article_service_status`.** It is replaced by `billing_unit` (`varchar(8)`, nullable — `month` | `year`) and `billing_quantity` (`unsigned tinyint`, nullable — 1–12 when the unit is `month`, 1–10 when it is `year`). A NULL cadence is a **one-time** cadence: real, persisted, queryable data, not an absence.
- **`BillingFrequency` (the closed int enum, including `ONE_TIME`) is `@deprecated`** — use the new `BillingInterval` value object (`unit` + `quantity`). `ONE_TIME` maps to a NULL cadence (plus `modality = one_time` on agreements).
- **The `article_prices` unique index is re-keyed** from `(article_id, billing_frequency, valid_from)` to `(article_id, billing_unit, billing_quantity, valid_from)` — multiple price versions per cadence stay legal through disjoint validity ranges, exactly as before. With nullable cadence columns the engine-level unique cannot express the ADR-012 no-overlap invariant for one-time prices, so the guarantee remains **application-level**, unchanged in mechanism: `ArticlePrice::scopeOverlapping()` (re-keyed) plus the locked write path — now `ArticlePriceService::setIntervalPrice()`; the deprecated `setPrice()` forwards to it.
- The recurring engine schedules through `BillingInterval`, whose overflow semantics are pinned to the old `BillingFrequency::addToDate()` math (Jan 31 + 1 month → Mar 3), so existing schedules do not shift.

### Lifecycle with causes (AID-949)

- **`article_service_status.status` widens from `char(1)` int-backed to `varchar(16)` string-backed** with the five ADR-015 states: `pending`, `active`, `suspended`, `guarantee`, `closed`. The single-letter values disappear; the `ServiceStatus` int enum (0–4) is `@deprecated` — use `AgreementStatus`.
- **`cancellation_type` is DROPPED**, retired with the commercial policy it encoded (ADR-014). Its job is subsumed by:
  - the **cause vocabulary** — a new table `agreement_causes` (`scope` = `closed` | `suspended`, `key`, `label`, `is_active`), seeded with the closure causes `unpaid_termination`, `client_request`, `fulfilment`, `fraud`, `provider_rescission` and the shim cause `legacy_cancellation`, and the suspension causes `unpaid`, `hacking`, `other`. New causes are grown by row, no code change. Cause is **optional** on closed agreements (`closed` + NULL cause is a legal state).
  - the **close/suspend API** — `ArticleServiceStatus::close(?AgreementCause $cause = null, ?string $note = null, ?Carbon $effectiveAt = null)` and `::suspend(AgreementCause $cause, ?string $note = null)` (plus `::cancelScheduledClose(?string $note = null)`). A future `effectiveAt` schedules the close: the agreement stays `active` with `close_scheduled_at` set, and the scheduler materialises it on that date. New events: `ServiceClosed` / `ServiceSuspended`.
- **Deferred closures are carried over.** Rows still `active` with a `cancellation_effective_at` in the future (the 6.x end-of-period / notice cancellations that had not fired yet) stay `active` and gain `close_scheduled_at` = the stored date; the new scheduler closes them on that date (with cause NULL — see the mapping table) and the emission gate never bills a period reaching beyond it. A cancellation effective today or in the past closes now.
- **`account_status` is added** (`varchar(12)`, default `up_to_date` — `up_to_date` | `overdue`): the accounting axis, independent of the service state. WHMCS conflated the two; larabill now separates them.
- `cancellation_requested_at`, `cancellation_effective_at` and `refund_unused` **keep their columns** untouched; their retirement is the AID-971 rewrite of the close path, not this release.

### Modality and renewal (AID-953)

- **`modality`** (`varchar(12)`, default `recurring` — `recurring` | `one_time` | `non_billable`) is added to `article_service_status`: what KIND of contract the row carries, independent of its lifecycle state. Backfill: NULL-cadence rows → `one_time`; every cadence-bearing row → `recurring`. `non_billable` is **never derived from legacy data** — it exists for the consumer/importer to set explicitly (§2.5 "Free Account").
- **`renewal`** (`varchar(12)`, default `auto` — `auto` | `closed_term`) is backfilled from `expires_at`: NULL → `auto`, non-null → `closed_term`. A dated agreement must not silently become auto-renewing: with `closed_term`, the engine never advances past `expires_at` and expiry closes the agreement.
- **Engine semantics**: the emission gate only bills `active` agreements with a non-null `next_billing_date`. `non_billable` modality never emits; `one_time` emits exactly once at contracting (a one-time agreement must carry `next_billing_date = NULL`); no period is ever emitted across a scheduled close or a closed term's end.

### Guarantee (AID-953)

- **`articles.guarantee_days`** (`unsigned smallint`, nullable) is the per-product refund-window capability. **No backfill**: every existing product keeps NULL = no window until you decide otherwise — backfilling would invent a commercial policy.
- **`article_service_status.guarantee_days`** is the SNAPSHOT frozen into the agreement at contracting; later product changes never move an existing window. No backfill: agreements contracted before this release had no capability to snapshot.
- **New table `article_guarantee_operations`** — the operations registry (`agreement_id`, `original_invoice_id`, `rectifies_invoice_id`, base-100 `amount`, `status` `pending`|`refunded`, `refunded_at`, and a `pending_slot` nullable-sentinel UNIQUE pair that makes two concurrent pendings for one agreement impossible). Empty by construction. larabill records; your application decides client policy.
- **`invoices.settlement_operation_key`** (`varchar`, nullable, UNIQUE) — the deterministic settlement key (`guarantee_ops:{registry_row_uuid}`) that serialises rectificative issuance. NULL is the historical truth for every existing invoice.

### Import support (AID-895 side)

- **`invoices.is_historical`** (`bool`, default `false`) — set by the importer on every document migrated from the legacy system. Existing native invoices stay `false`; no backfill needed.
- **`article_service_status.origin_key`** (`varchar(64)`, nullable, UNIQUE) — the importer's idempotency key, format `whmcs:<source_table>:<source_id>`. A re-import UPDATES the matched row instead of duplicating. NULL for native agreements; the existing nullable unindexed `external_reference` is untouched and is NOT an idempotency key.

### Plan change (AID-953)

- **One new config key**: `larabill.agreements.plan_change.policy` = `restart` (default) | `align_end`. No other config default is touched. Note the effective default is read at the read point (`PlanChangeService::resolvePolicy()`), so a consumer whose published config predates this key still gets `restart`; any other value throws `PlanChangeNotAllowedException` loud.

## What the migrations do to your data

### Cadence mapping (migrations 040 + 041, both tables)

By raw `billing_frequency` value, through the single source `BillingInterval::fromLegacyFrequency()`:

| Stored `billing_frequency` | New `billing_unit` / `billing_quantity` |
| -- | -- |
| `ONE_TIME` (0) | NULL / NULL — a one-time price/agreement; on agreements the `modality` migration (045) then sets `one_time` |
| `MONTHLY` (3) | `month` / 1 |
| `BIMONTHLY` (4) | `month` / 2 |
| `QUARTERLY` (5) | `month` / 3 |
| `SEMIANNUAL` (6) | `month` / 6 |
| `YEARLY` (7) | `year` / 1 |
| `BIENNIAL` (8) | `year` / 2 |
| `TRIENNIAL` (9) | `year` / 3 |
| `WEEKLY` (1) or `BIWEEKLY` (2) | **loud abort** (see preflight below) |

The mapping is injective over the old key, so the unique-index swap cannot collide.

### Status mapping (migration 044)

By raw stored value — the legacy `ServiceStatus` backed ints persisted in the `char(1)` column (the `A/P/…` letters in the old migration comment were a stale comment, never the storage format):

| Stored `status` | New |
| -- | -- |
| 0 (`ACTIVE`) | `active` |
| 1 (`PENDING`) | `pending` |
| 2 (`SUSPENDED`) | `suspended`, **cause NULL** |
| 2.5 — deferred closures: rows still `0` (`ACTIVE`) with a `cancellation_effective_at` | Future date → stays `active` + `close_scheduled_at` = the stored date (the scheduler closes it on that date, cause NULL). Date today or past → `closed`, cause NULL |
| 3 (`CANCELLED`) | `closed`, **cause NULL** |
| 4 (`EXPIRED`) | `closed` + cause `fulfilment` |

**Why most causes land NULL.** Legacy data cannot establish why an agreement was suspended or cancelled: `CancellationType` recorded timing mechanics (immediate / end of period / notice), not a commercial cause, and a suspended row carries no reason at all. Inventing `unpaid` or `client_request` would fabricate business history. The **only** mapping the source data establishes is `EXPIRED` → `fulfilment`, because the expiry process is what set that state. NULL causes are a legal, documented state — set them per row afterwards through the new API (`ArticleServiceStatus::close()` on data, or a targeted `UPDATE` of `closure_cause_id` from your own migration) if your records justify it.

**Renewal backfill** (same migration): `expires_at IS NULL` → `renewal = auto`; non-null → `renewal = closed_term`. Asserted on both branches by the upgrade-path tests.

### The deferred-closure race (read this if you had pending 6.x cancellations)

A 6.x cancellation with `cancellation_effective_at` in the future was supposed to be materialised by the 6.x scheduler (`processPendingCancellations()`) when the date arrived. If your 6.x cron **was expected to have already processed** a date that is today or in the past at upgrade time, the migration materialises it instead: the agreement becomes `closed` with cause NULL, and the legacy timing metadata (the `CancellationType` recorded by the cancel path) is not replayed. The race window is "date already due but the scheduler had not run yet" — e.g. a cron that was down over a weekend. If that matters for your records, check the rows **before** upgrading:

```sql
SELECT id, status, cancellation_type, cancellation_effective_at
FROM article_service_status
WHERE status = '0' AND cancellation_effective_at <= CURDATE();
```

Every row it returns closes during the migration with cause NULL. If any of them should have closed under different bookkeeping, resolve them (close them through the 6.x path, or note the correction for after the upgrade) before running `migrate`.

## The loud-abort preflight

Each data migration checks BEFORE any DDL, so an abort leaves every table byte-identical. The migration refuses when it finds:

- **Any `WEEKLY` (1) or `BIWEEKLY` (2) row** — in `article_prices` or `article_service_status`, each reported **independently** with its own count and remediation text. The ADR-015 cadence domain (months 1–12, years 1–10) cannot represent sub-monthly cadences and larabill refuses to guess one. Remediation: map each row manually to the month/year interval it really billed (or retire it), then re-run `php artisan migrate`.
- **Any `CANCELLED` (3) row lacking `cancellation_type`** — the importer-shadow state: the package refuses to invent the missing timing mechanics. Remediation: set the legacy `cancellation_type` each row was really cancelled under (0 = immediate, 1 = end of period, 2 = notice period), or retire the row.
- **Any out-of-domain raw value** — `billing_frequency` outside 0–9, `status` outside 0–4, `cancellation_type` outside 0–2. None of these columns ever carried a DB domain constraint, so such rows can exist silently in a legacy database. Remediation: correct or retire those rows manually — decide what each one really was.
- **A missing `fulfilment` cause seed** — the status program resolves the seed through `AgreementCause::findActive('closed', 'fulfilment')` during the preflight too, so a tampered seed aborts while the table is still untouched.

All remediation is manual and deliberate: the package never invents a cadence, a status or a cause.

**The one_time `next_billing_date` artifact is cleared by the migration itself — a count is informational, not a gate (AID-1595).** Real 6.x data can carry a `next_billing_date` on ONE_TIME rows (the old engine wrote it; a one-time agreement never bills again). The modality migration (045) nulls `next_billing_date` on every NULL-cadence row in the same update that backfills `modality = 'one_time'` — no preflight, nothing to remediate. If you want to audit how many artifacts your database carries before upgrading, run this INFORMATIONAL count (its result changes nothing):

```sql
SELECT COUNT(*) FROM article_service_status
WHERE billing_frequency = 0 AND next_billing_date IS NOT NULL;
```

The recurrent engine's loud gate for a one_time row with a billing date stays in place as defense in depth — after the migration it is unreachable through the supported upgrade path and only fires on rows written against the data contract afterwards.

## The deprecated shim layer (removed in 8.0 — actually retired in 9.0; see the dated correction at the top, AID-1778)

Every retired public symbol still ships through 7.0, marked `@deprecated` with its named replacement, delegating to the new model. The shims are a **compatibility surface, not a second engine**: package internals and the WHMCS import run on the new model only. Each delegating public path emits an `E_USER_DEPRECATED` notice naming the replacement, so your logs tell you exactly what to migrate before 8.0 removes it.

**The shim → replacement contract** (this table is the binding mapping):

| Retired symbol (shim, 7.0) | Replacement (new model) |
| -- | -- |
| `ServiceStatus` (int cases 0–4) | `AgreementStatus` — string-backed enum: `pending` / `active` / `suspended` / `guarantee` / `closed` |
| `BillingFrequency` (incl. `ONE_TIME`) | `BillingInterval` (`unit` + `quantity`); `ONE_TIME` → NULL cadence + modality |
| `CancellationType` | cause vocabulary (`agreement_causes` / `AgreementCause`) + the close/suspend API |
| `ArticleServiceStatus::cancel()` ×2 (the two `cancel()` implementations of ADR-015 Enmienda 1 — the model shim and the service shim, covered across this row and the next) | `::close(cause)` / `::suspend(cause)`; the shim forwards with the seeded **`legacy_cancellation`** cause, preserving the original `CancellationType` in the closure metadata — no commercial cause is invented |
| `ServiceLifecycleService::cancel()` / `calculateCancellationEffectiveDate()` / `processPendingCancellations()` | same close/suspend API; the scheduler (`processScheduledClosures()`) subsumes deferred closures (close with effective date) and expiry (`closed` + `fulfilment`); `processPendingCancellations()` now simply delegates to it |
| `ServiceCancelled` event (constructor takes `CancellationType`) | `ServiceClosed` / `ServiceSuspended` |
| `calculateNextBillingDate()` / `shouldBeBilled()` | `BillingInterval::addToDate()` via `ArticleServiceStatus::billingInterval()` / the state + interval gate (modality + `billingInterval()`) |
| `Article::isRecurring()` / `scopeRecurring()` | modality-based derivation (agreements declare `modality = recurring`) |
| `ArticlePrice` casts/scopes (`scopeOverlapping`) | interval columns + `scopeOverlapping` re-keyed on `(article_id, billing_unit, billing_quantity)` |
| `PricingService::getEffectivePrice()` / `createPricingDetails()` (frequency signatures) | `getEffectivePriceForInterval()` / `createPricingDetailsForInterval()` |
| `Article::getPriceFor()` / `getEffectivePriceFor()` / `getBillingDaysInAdvanceFor()` (frequency signatures) | `getPriceForInterval()` / `getEffectivePriceForInterval()` / `getBillingDaysInAdvanceForInterval()` |
| `RecurringBillingService` frequency math / window logic | `BillingInterval` scheduling; emission gate shape unchanged (plus the scheduled-close and closed-term exclusions) |
| `ArticlePriceService::setPrice(..., $frequency)` | **`setIntervalPrice(..., ?BillingInterval $interval, ...)`** — a DISTINCT method (PHP cannot overload two same-named signatures); the deprecated `setPrice` keeps its frequency signature and dispatches internally — `ONE_TIME` resolves as NULL/NULL |
| `ArticleOverride::getDiscountAmount()` / `getDiscountPercentage()` (frequency signatures) | resolve the base price over `BillingInterval` (`Article::getPriceForInterval()` / `PricingService::getEffectivePriceForInterval()`) and subtract `custom_price` |
| `DiagnosePriceOverlapsCommand` | command re-keyed to the interval columns |
| `ArticlePriceFactory` | state seeds `billing_unit` / `billing_quantity` |
| `OverlappingArticlePriceException` | **retained, not retired** — the candidate payload is re-keyed to the interval columns |

Two per-path precision notes, so the timing behaviour you relied on is not lost:

- The **two `cancel()` shims compute their legacy notice dates on their own metadata routes, verbatim** — `ArticleServiceStatus::cancel()` reads `metadata.service.cancellation_policy.notice_days`, `ServiceLifecycleService::cancel()` reads `metadata.notice_period_days`. Each public path forwards with its own legacy computation (defaulting to 30 days when the metadata is absent); there is no shared abstraction. `END_OF_PERIOD` forwards with `effectiveAt = next_billing_date ?? now`; `IMMEDIATE` closes now.
- The `cancel()` shims **dispatch the legacy `ServiceCancelled` event** for their cancellations — including on the deferred materialisation of a shim-scheduled close — so existing listeners keep receiving them. New code should listen to `ServiceClosed` / `ServiceSuspended`.

**The one narrowing — the only case where a deprecated surface stops forwarding.** Shim inputs whose cadence the new model cannot represent — `BillingFrequency::WEEKLY` and `BIWEEKLY` — emit `E_USER_DEPRECATED` and throw `LegacyCadenceUnsupportedException` (which extends the existing `InvalidBillingIntervalException`, so existing catch-sites keep working) instead of forwarding. This narrowing is the qualified exception recorded in STABILITY.md §3 itself, with a measured imperative: zero instances in the migration corpus (AID-895 survey, 2026-08-12), the cadence domain is defined as months 1–12 / years 1–10 by design (ADR-015), and the migration preflight loud-aborts any weekly or biweekly row — so no 7.0 installation can hold such data. **No other shim narrows**; every other deprecated symbol forwards with its existing behaviour intact.

**Deprecation window**: the shims live through all of 7.x and are removed in **8.0** (one full major, STABILITY.md §3). Migrate at your own pace during 7.x — the `E_USER_DEPRECATED` notices are the checklist.

## Not in this release

- **No money movement, no refund execution**: the guarantee registry and the plan-change liquidation record and issue fiscal documents (ADR-014 frontier); charging a card or moving funds stays your application's job.
- **No VeriFACTU changes.**
- **The AID-971 settlement artifacts** (`SettlementService`, `SettlementArithmetic` and their seven exceptions) shipped additively in the same major — they are new `@api` surface, not breaking; see the CHANGELOG.
- **`approvals/majors/` registry**: the governance entry for this major lives in the umbrella repository, not in this package.
