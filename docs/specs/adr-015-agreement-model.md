# Spec — Agreement model redesign (ADR-015, major 7.0.0)

- **Status:** DRAFT — pending adversarial gate before any implementation.
- **Refs:** [ADR-015](../ADR-015-agreement-anatomy-modality-cadence-lifecycle.md), AID-953, AID-949, AID-952, AID-895 (WHMCS import), AID-971 (settlement artifacts, prerequisite).
- **Decision authority:** operator, session of 2026-10-06 (three decision rounds, act in `~/claude/informes/2026-10-06-larabill-modelo-acuerdos-diseno-cerrado.md`).

## 1. Scope

One major release (7.0.0) that reshapes the agreement model:

1. **Cadence as a model** (AID-952): quantity + unit replaces the closed `BillingFrequency` enum, in agreements and prices.
2. **Lifecycle with causes** (AID-949): five service states, table-backed closure/suspension cause vocabulary, accounting state separated from service state.
3. **Modality and renewal** (AID-953): every contracted service owns an agreement row carrying modality and renewal attributes.
4. **Guarantee**: per-product refund window with a transient `GUARANTEE` state, rectificative invoicing and an operations registry.
5. **Plan change**: configurable company policy (restart vs aligned end).
6. **Import support**: `is_historical` marker on imported invoices; non-fiscal series guidance for legacy WHMCS zero invoices.

**Explicit non-goals:** no VeriFACTU changes (legal obligation 2028, corpus predates it); no money movement (ADR-014 frontier); no client blacklisting (consumer policy; larabill only registers guarantee operations); no purchase-option contracts (another contract, out of scope).

## 2. Target schema

### 2.1 `article_service_status` (agreement)

| Change | Detail |
| -- | -- |
| `status` `char(1)` → `varchar(16)` | Values: `pending`, `active`, `suspended`, `guarantee`, `closed`. Collision note: current code `C` (cancelled) disappears; new string values avoid the single-letter trap. |
| ADD `billing_unit` `varchar(8) nullable` | `month` \| `year`. NULL for one-time / non-billable. |
| ADD `billing_quantity` `unsignedTinyInt nullable` | 1–12 when unit is `month`; 1–10 when unit is `year`. |
| DROP `billing_frequency` | Replaced by unit + quantity (data migration, §4). |
| ADD `guarantee_days` `unsignedSmallInt nullable` | **Snapshot** of the product's window, frozen into the agreement at contracting (§3.4): changing the product later never moves an existing right. |
| ADD `close_scheduled_at` `date nullable` | Deferred closure carried over from 6.x (§4.2): rows cancelled end-of-period/notice with a future effective date stay `active` until this date, then close with cause NULL. |
| ADD `modality` `varchar(12)` | `recurring` \| `one_time` \| `non_billable`. Default for existing recurring rows: `recurring`. |
| ADD `renewal` `varchar(12)` | `auto` \| `closed_term`. Backfill rule (§4.2): `expires_at IS NULL` → `auto`; non-null → `closed_term` (a dated agreement must not silently become auto-renewing: the current engine advances dates unconditionally, `RecurringBillingService.php:481-488`). |
| ADD `closure_cause_id` FK nullable | → `agreement_causes` (§2.2). Only meaningful when `status` is `closed` or `suspended`. |
| ADD `account_status` `varchar(12)` default `up_to_date` | `up_to_date` \| `overdue`. Accounting axis, independent of service state (operator decision: WHMCS conflated them). |
| `cancellation_type` DROP | Retired with the commercial policy it encodes (ADR-014 / AID-971). `cancellation_requested_at` / `cancellation_effective_at` / `refund_unused` keep their columns pending the AID-971 rewrite of the close path; the new close API (§3.2) subsumes them. |

### 2.2 New table `agreement_causes` — cause vocabulary, NOT an enum

- Columns: `id`, `scope` `varchar(12)` (`closed` \| `suspended`), `key` `varchar(32)` unique, `label`, `is_active` bool, timestamps.
- Seeds — `closed`: `unpaid_termination`, `client_request`, `fulfilment`, `fraud`, `provider_rescission`, **`legacy_cancellation`** (seeded shim cause: a 6.x `cancel()` forwarding through the deprecated API records it, with the original `CancellationType` preserved in metadata — never invented as a commercial cause, gate round 6 P1). Seeds — `suspended`: `unpaid`, `hacking`, `other`.
- Grow-by-row: new causes require no code change. The `scope` column prevents assigning a suspension cause to a closure and vice versa **at application level only** — a FK on `closure_cause_id` proves existence, not scope, and Eloquent bulk `query()->update()` bypasses model events (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1315-1318`). The invariant is documented as application-enforced and pinned by a test; it is NOT database-enforced (a cross-table CHECK is not expressible here).
- Cause is **optional** on closed agreements (import requirement): `closed` + NULL cause is a legal state; the narrative lives in the order notes of the consumer, never invented by the package.

### 2.3 `article_prices` (ADR-004 reformulated, not superseded)

- `billing_frequency` → `billing_unit` + `billing_quantity` (same domain as §2.1; **nullable here too** — a NULL-cadence price is a one-time price, which is real persisted data today: `BillingFrequency::ONE_TIME = 0` and `Article::getDefaultPrice()` queries it first, `src/Models/Article.php:342-353`).
- Unique overlap invariant (ADR-012) re-keyed on `(article_id, billing_unit, billing_quantity)`; `scopeOverlapping()` updated; `ArticlePriceService::setPrice()` remains the guarantee.
- `billing_days_in_advance` stays per-price, now keyed by interval.

### 2.4 New value object `BillingInterval` (`src/ValueObjects/BillingInterval.php`)

- `readonly` class: `unit` (`month`|`year`), `quantity` (1–12 / 1–10, validated in the constructor). Methods: `addToDate()`, `subtractFromDate()`, `approximateDays()`, `label()` (`3 months`, `5 years`), `equals()`. **Overflow semantics are pinned to preserve current behaviour exactly** — addition overflows (`addMonths`/`addYears`, Jan 31 + 1 month → Mar 3, as `BillingFrequency::addToDate()` does today), subtraction uses `NoOverflow` (as `subtractFromDate()` does today). Changing addition semantics would silently shift existing schedules on migration; it is out of scope.
- **Weekly/biweekly cadences are rejected**: outside the new domain, zero instances in the corpus, and the operator fixed the domain as months 1–12 / years 1–10. See §4.3 for the migration posture on pre-existing weekly rows.

### 2.5 `invoices.is_historical`

- ADD `is_historical` `bool default false`. Set by the importer on every document migrated from the legacy system. Plain, visible, queryable.
- Legacy zero invoices of sponsored services import as **0-amount invoices with a traceability line** ("legacy error, originating from WHMCS"), created through the **existing non-fiscal route: `InvoiceSerieType::PROFORMA` via `InvoiceService`** (gate round 6 P1 — a series prefix cannot make a document non-fiscal; fiscal quality is the serie type, `src/Enums/InvoiceSerieType.php:20-69`, `src/Services/InvoiceService.php:118-135`). The consumer configures the proforma `prefix` for the legacy series; larabill adds no new serie type. Every document carries `is_historical`.
- **Import terminal-state contract (gate round 7, P2 #6).** WHMCS agreement states map deterministically, and the original state always survives in metadata + order notes:

| WHMCS state | larabill target |
| -- | -- |
| `Active` | `active` |
| `Pending` | `pending` |
| `Suspended` | `suspended`, cause NULL |
| `Cancelled`, `Terminated`, `Completed`, `Fraud` | `closed`, cause NULL — the operator's stance governs: the import records **that the service ended**; the WHMCS state and narrative live in metadata and order notes, never as an invented `closure_cause` |

  Every imported agreement carries its original identifiers, dates and state in metadata namespaced; unsupported states appear as `unsupported` in the importer report without approximation.
- **Import cadence contract (gate round 10, P2).** Source WHMCS billing cycles map deterministically (`Monthly` → 1 month, `Quarterly` → 3 months, `Semiannually` → 6 months, `Annually` → 1 year, `Biennially` → 2 years, `Triennially` → 3 years, `One Time` → NULL cadence + modality); `Free Account` → non-billable modality per AID-953. Unrepresentable source cadences (the 15 long-period domains, any unknown cycle string) stay **blocked**: they keep their original value in metadata, appear as `unsupported` in the importer report, and are never approximated. The import runs per-record in a transaction with an idempotent re-run contract (gate round 11, P2): ADD `origin_key` `varchar(64) nullable` + **UNIQUE index** on the agreement table, format `whmcs:<source_table>:<source_id>` — re-importing an already-imported record updates it (matched by `origin_key`) rather than duplicating. The existing nullable unindexed `external_reference` is not an idempotency key and stays untouched.

### 2.6 New table `agreement_guarantee_operations`

- Columns: `id`, `agreement_id` FK, **`original_invoice_id` FK** (the paid invoice the refund corrects — materially fiscal for a rectificative per ADR-014), `rectifies_invoice_id` FK nullable (the rectificative, once emitted), `amount` (base-100 int, `FixedDecimalCast:2`), `status` `varchar(12)` (`pending` \| `refunded`), `refunded_at` nullable, timestamps.
- **Pending-idempotency via the repo's nullable-sentinel pattern** (gate round 2, P1): a partial unique index is NOT portable — Laravel's MySQL `Blueprint::unique()` accepts columns only (`vendor/.../Schema/Grammars/MySqlGrammar.php:496-506`). Instead: ADD `pending_slot` `varchar(16) nullable` — fixed value `'PENDING'` while the operation is pending, NULL once refunded — with a UNIQUE index on `(agreement_id, pending_slot)`. Two concurrent pendings for the same agreement collide; refunded rows (NULL) never do. Same mechanism as `grouped_payment_invoice_table` (`database/migrations/2026_06_27_000002_create_grouped_payment_invoice_table.php:23-29`). A concurrent duplicate request is rejected and covered by a fork test.
- Registry only. larabill records; the consumer decides client policy.

## 3. Target behavior

### 3.1 State machine

```
PENDING ──provisioned──▶ ACTIVE ──suspend(cause)──▶ SUSPENDED ──reinstate──▶ ACTIVE
   │                        │                           │
   │                        │ expire (expires_at)       │ prolonged suspension
   │                        ▼                           ▼
   └──▶ ACTIVE/CLOSED    CLOSED(fulfilment)        CLOSED(cause)
                             ▲
GUARANTEE ──refund recorded──┘   ACTIVE ──close(cause)──▶ CLOSED
```

- `GUARANTEE` is entered from `ACTIVE` when a within-window cancellation fires (§3.4); it is **transient**: once the refund is recorded, the agreement moves to `CLOSED` with cause `client_request` and the registry row flips to `refunded`.
- `expires_at` + `processExpiredServices()` now produce `CLOSED` + `fulfilment` (today: `EXPIRED`, which disappears).
- Prolonged suspension → `CLOSED` with cause; data elimination afterwards is lara-privacy territory, not this package's.

### 3.2 Close/suspend API (replaces both `cancel()` implementations)

- `ArticleServiceStatus::close(Cause $cause, ?string $note = null, ?Carbon $effectiveAt = null)` and `::suspend(Cause $cause, ?string $note = null)` — one path each, no duplicated implementations, no invented defaults (ADR-014 Enmienda 1 lesson). `close()` with `$effectiveAt` (future) is a **scheduled close**: the agreement stays `active` with `close_scheduled_at` = `$effectiveAt`, and the scheduler materialises it on that date with the given cause. Dispatches `ServiceClosed` / `ServiceSuspended` / `ServiceReinstated` (on materialisation too).
- **Scheduled-close semantics (gate round 7, P1s 1–2):** the emission gate **never emits a period reaching beyond `close_scheduled_at`**: an agreement with a scheduled close inside the billing window (`next_billing_date − daysInAdvance` … period end) is not billed. When the scheduled date arrives, the close materialises with its stored cause. When both `close_scheduled_at` and `expires_at` exist and **differ**, whichever comes first wins and the other is ignored at closure time (gate round 7, P2 #5). On **equal dates the scheduled close wins** with its stored cause — an explicit close decision outranks the automatic expiry (gate round 8, P2 #2) — and one locked materialiser handles both fields in the same pass, so scheduler order never determines the resulting history.
- **Schedule cancellation (gate round 8, P2 #3):** `::cancelScheduledClose(?string $note = null)` clears `close_scheduled_at` (the agreement stays `active`, no close scheduled), writes the cancellation of the schedule into `metadata`, and requires `status = active` under the same lock/revalidation discipline as every other transition. It is the route an agreement must take before a plan change (§3.5).
- **Shim timing (gate round 7, P1 #2; per-path precision gate round 8, P1 #1):** the deprecated `cancel(IMMEDIATE)` forwards to `close(legacy_cancellation)` now. The two legacy NOTICE paths compute their effective date from **different metadata routes** (`src/Models/ArticleServiceStatus.php:235-237` reads `metadata.service.cancellation_policy.notice_days`; `src/Services/ServiceLifecycleService.php:259-265` reads `metadata.notice_period_days`) — each shim path forwards with **its own** legacy computation, verbatim; there is no single abstracted "legacy notice computation". `cancel(END_OF_PERIOD)` forwards with `effectiveAt = next_billing_date ?? now` on its own path. Deprecated surface preserves each existing public path, not an approximation (the notice policy itself retires via ADR-014/AID-971).
- `CancellationType` and `requiresRefund()` retire; refund decisions live in the guarantee window (§3.4) and the settlement service (ADR-014 / AID-971).
- Emission gate unchanged in shape: only `active` agreements with non-null `next_billing_date` are ever billed (`non_billable` modality additionally never emits; `one_time` emits exactly once at contracting) — **plus the scheduled-close exclusion above**.

### 3.3 Modality and renewal

- `renewal = auto`: the engine advances `next_billing_date` after emission, as today.
- `renewal = closed_term`: the engine never advances past `expires_at`; expiry closes the agreement (§3.1). The house example: a 12-month plan that must not renew.
- One-time extension ("one more year"): a consumer action that (a) extends the agreement window, (b) emits a new one-time invoice. Modality unchanged.

### 3.4 Guarantee

- Capability on the product: `articles.guarantee_days` `unsignedSmallInt nullable` (NULL/0 = no guarantee window). Domains and provisioned VPS simply carry NULL — exclusion is the consumer's data, not package logic.
- **Guarantee eligibility is frozen at contracting (gate round 6 P2).** The window anchor is the agreement's `started_at`; the length is the `guarantee_days` snapshot taken at creation (§2.1). Later changes to the product's `guarantee_days` never move an existing agreement's window. The flow is: window entry (state `guarantee`) → registry `pending` (sentinel unique) → rectificative via the ADR-014 settlement service (**AID-971 hard prerequisite**) → refund recorded → registry `refunded` + agreement `closed` + `client_request`. If the refund is never confirmed, the agreement stays `guarantee` (no automatic closure); if the product loses `guarantee_days` after contracting, existing agreements keep their snapshot.
- **Settlement is idempotent through collision-and-adoption (gate rounds 10–13, converged).** First-wins issuance is enforced by the DATABASE, not by a lock held across an independent commit — on Laravel's default connection a nested independent transaction is a savepoint (`ManagesTransactions.php:150-160`), so the two cannot coexist (gate round 13, P1):

  - **Deterministic operation key:** the pending registry row's id yields the key `guarantee_ops:{registry_row_uuid}` — every requester of the same claim resolves the SAME key. `settlement_operation_key` carries a **UNIQUE index** on `invoices`.
  - **Phase 1 — issuance, independent transaction (no registry lock held):** issue the rectificative stamped with the key. A concurrent issuer of the same claim **collides on the UNIQUE index**: the loser catches the collision, does not issue, and proceeds straight to adoption. If the transaction fails on anything else, nothing was issued — retry simply issues.
  - **Phase 2 — registry completion, separate transaction:** under `FOR UPDATE` on the registry row, revalidate it is still `pending` (if not, abort — idempotent no-op), find the rectificative by key (own issuance or the winner's — identical outcome), and complete the registry update (`rectifies_invoice_id`, `refunded_at`, status `refunded`).

  No interleaving can issue two fiscal corrections for one claim: the unique index is the serialisation point. A real interleaving test covers collision and adoption (§6).

### 3.5 Plan change

- Config: `larabill.agreements.plan_change.policy` = `restart` (default) \| `align_end`.
- **Eligibility (gate round 6 P2; round 7 P1 #4; round 10 P2):** only `active` agreements with **no `close_scheduled_at`** are plan-changed. A `suspended` agreement must be reinstated (or closed) first; a `guarantee` one completes its refund first; an agreement with a scheduled close must let it materialise or explicitly cancel the schedule through the API before a plan change. `align_end` **requires a closed-term predecessor** (`renewal = closed_term`): an auto-renewing agreement has no previous end date to align to — the combination is rejected with a validation error, not silently reinterpreted (gate round 10, P2). The state machine draws no other transition.
- **Atomic and idempotent boundary (gate round 6 P1):** the flow runs in one DB transaction holding `FOR UPDATE` on the agreement row with state revalidation inside the lock — the same pattern as the emission boundary (`RecurringBillingService.php:205-238`). Two concurrent plan-change requests on the same agreement: the second revalidates inside the lock, sees the row already `closed`, and aborts without issuing anything. A serial test alone would NOT prove this; the test plan requires a real interleaving (§6).
- Flow (both policies): close current agreement with proportion-of-unconsumed abono (ADR-014 liquidation, **not** guarantee) + create the new agreement for the new plan — full term from the change date (`restart`) or only until the previous end date with a calculated price (`align_end`). The successor is a **new contract**: its own `started_at` and its own guarantee snapshot per the product's current `guarantee_days` (gate round 7, SUSPECTED #1 — the predecessor's window does not transfer).
- **Fiscal artifact pinned:** the abono is an **ordinary `F1` invoice with a negative line, `rectifies_invoice_id` NULL**, current fiscal context on the abono line (ADR-014:43-46, 74-76). A test asserts exactly that — implementing the liquidation through the rectificative path would reclassify it `R1` and is a defect.

## 4. Data migration and upgrade program (6.x → 7.0.0)

### 4.1 Cadence mapping (deterministic)

| `BillingFrequency` (old) | `billing_unit` / `billing_quantity` |
| -- | -- |
| `MONTHLY` | `month` / 1 |
| `BIMONTHLY` | `month` / 2 |
| `QUARTERLY` | `month` / 3 |
| `SEMIANNUAL` | `month` / 6 |
| `YEARLY` | `year` / 1 |
| `BIENNIAL` | `year` / 2 |
| `TRIENNIAL` | `year` / 3 |
| `ONE_TIME` | unit/quantity NULL (+ `modality = one_time` on agreements; on `article_prices` the row itself keeps NULL cadence — it stays queryable as today) |
| `WEEKLY` / `BIWEEKLY` | **loud abort** (§4.3) |

### 4.2 Status mapping (by persisted value, not by letter)

Legacy persisted values are **backed integers** (`ServiceStatus: ACTIVE=0, PENDING=1, SUSPENDED=2, CANCELLED=3, EXPIRED=4`, `src/Enums/ServiceStatus.php:10-16`); the `A/P/...` letters in the old migration comment are stale and were a spec error (gate finding #2). Mapping, by raw stored value:

| Stored value | New |
| -- | -- |
| 0 (`ACTIVE`) | `active` |
| 1 (`PENDING`) | `pending` |
| 2 (`SUSPENDED`) | `suspended`, **cause NULL** (legacy data cannot establish why — inventing `unpaid` would violate the cause-optional principle; gate finding #3) |
| 2.5 — **deferred closures carried over (gate round 6 P1; boundary fixed gate round 7 P1 #3):** applies to rows still `ACTIVE` with a `cancellation_effective_at`. Effective date **in the future** → stays `active` + `close_scheduled_at` = the stored date; the new scheduler closes it on that date with cause NULL, and the emission gate never bills beyond it (§3.2). Effective date **today or past** → `closed` + cause NULL (no branch of the mapping may leave today's date unhandled — gate round 7 P1 #3). `UPGRADE-7.0.md` documents the race if the 6.x scheduler was expected to have processed it. |
| 3 (`CANCELLED`) | `closed`, **cause NULL** (`CancellationType` records timing mechanics, not commercial cause — the data does not prove `client_request`) |
| 4 (`EXPIRED`) | `closed` + cause `fulfilment` (the only mapping the source data establishes: the expiry process set it) |

Causes left NULL are documented in `UPGRADE-7.0.md` and settable per row afterwards via the new API. **`renewal` backfill:** `expires_at IS NULL` → `auto`; non-null → `closed_term` (§2.1). Tests seed raw values `0..4` plus out-of-domain values, and assert both `expires_at` branches.

### 4.3 Loud-abort preflight (data-respect rule, AID-398)

The migration **aborts with an actionable error** if it finds: (a) any `WEEKLY`/`BIWEEKLY` price or agreement; (b) any `CANCELLED` row lacking `cancellation_type` (the importer-shadow state AID-949 warned about); (c) **any value outside the known legacy domains** — `billing_frequency` beyond 0–9, `status` outside 0–4, `cancellation_type` outside 0–2 (none of these columns carries a DB domain constraint, so out-of-domain rows can exist silently; gate finding #5). Remediation is documented, not guessed. Same spirit as the UUID preflight of `larabill:install`.

### 4.4 Deliverables in the same PR set

- Every migration with its data transformation; **no fresh-install-only DDL**.
- `UPGRADE-7.0.md` **in the dist** (lesson AID-324).
- Contract snapshots regenerated (`bin/sync-contract-snapshots`) — `Invoice`, `ArticleServiceStatus`, `Article`, `ArticlePrice` surfaces change.
- Migration manifest regenerated; `$migrationOrder` extended (`.php` + `.php.stub` pairs per ADR-007).
- CHANGELOG with breaking changes in bold; `approvals/majors/` registry entry opened before tagging (umbrella governance).

## 5. Breaking surface and deprecation path (owner decision B, gate round 1)

Gate round 1 adjudged outright removal in 7.0 in conflict with `STABILITY.md:26-32` (public `@api` surface deprecated in major N, working until N+1). The owner chose to **honour the window**:

- **7.0 runs the new model exclusively internally.** Every retired public symbol ships through 7.0 as a **deprecated compatibility shim** — `@deprecated` + `E_USER_DEPRECATED`, delegating to the new model. Shims are a compatibility surface, **not a second engine**: internal code and the WHMCS import use the new model only. No coexistence of logic.
- **8.0 removes the shims.** `UPGRADE-7.0.md` documents every shim and its replacement; the 8.0 upgrade is then trivial.

Removal list for 8.0 (= everything that becomes a shim in 7.0):

- `ServiceStatus` (int cases 0–4), `BillingFrequency` (incl. `ONE_TIME`), `CancellationType`.
- `ArticleServiceStatus::cancel()` ×2, `ServiceLifecycleService::cancel()`/`calculateCancellationEffectiveDate()`/`processPendingCancellations()` signatures; `calculateNextBillingDate()`; `shouldBeBilled()`.
- `Article::isRecurring()`/`scopeRecurring()` (re-derived from modality, not price frequency), `ArticlePrice` casts/scopes, `PricingService` frequency entry points.
- `RecurringBillingService` frequency math and window logic.
- Verified-by-gate additions: `ArticleOverride::getDiscountAmount()/getDiscountPercentage()` (`src/Models/ArticleOverride.php:303-324`, `@api` model), `ArticlePriceService::setPrice()` (`src/Services/ArticlePriceService.php:46-68`, `@api`), `ServiceCancelled`'s public `CancellationType` constructor parameter (`src/Events/ServiceCancelled.php:17-25`, `@api`), `DiagnosePriceOverlapsCommand` (`src/Console/DiagnosePriceOverlapsCommand.php:34-53`), `ArticlePriceFactory` and `OverlappingArticlePriceException`.
- **Shim contract (gate round 2, P1/P2).** Per `STABILITY.md:26`, every shim ships `@deprecated` **with its named replacement** — the mapping below is the contract, not deferred to `UPGRADE-7.0.md`:

| Retired symbol (shim, 7.0) | Replacement (new model) |
| -- | -- |
| `ServiceStatus` (int cases 0–4) | `AgreementStatus` — new string-backed enum with the five cases `pending`/`active`/`suspended`/`guarantee`/`closed` (§3.1, the state machine) |
| `BillingFrequency` (incl. `ONE_TIME`) | `BillingInterval` (`unit`+`quantity`); `ONE_TIME` → NULL cadence + modality |
| `CancellationType` | cause vocabulary (`agreement_causes`) + close/suspend API |
| `ArticleServiceStatus::cancel()` ×2 | `::close(cause)` / `::suspend(cause)`; the shim forwards with the seeded **`legacy_cancellation`** cause (`agreement_causes`), preserving the original `CancellationType` in the closure metadata — no commercial cause is invented (§2.2) |
| `ServiceCancelled` event (gate round 10, P1) | the `cancel()` shims **dispatch the legacy `@api` `ServiceCancelled`** for their cancellations (including deferred materialisation through the shim path), so existing listeners keep receiving them; new code listens to `ServiceClosed`/`ServiceSuspended` |
| `ServiceLifecycleService::cancel()` / `calculateCancellationEffectiveDate()` / `processPendingCancellations()` | same close/suspend API; the scheduler subsumes deferred closures (close with effective date) and expiry (`closed` + `fulfilment`) |
| `ServiceCancelled` (ctor takes `CancellationType`) | `ServiceClosed` / `ServiceSuspended` |
| `calculateNextBillingDate()` / `shouldBeBilled()` | `BillingInterval::addToDate()` / state+interval gate |
| `Article::isRecurring()` / `scopeRecurring()` | modality-based derivation (`modality = recurring`) |
| `ArticlePrice` casts/scopes (`scopeOverlapping`) | interval columns + `scopeOverlapping` re-keyed on `(article_id, billing_unit, billing_quantity)` (§2.3) |
| `PricingService` frequency entry points | same entry points over `BillingInterval` (§2.3 for the model, §3.2 for the emission gate) |
| `RecurringBillingService` frequency math / window logic | `BillingInterval` scheduling; emission gate shape unchanged (§3.2) |
| `ArticlePriceService::setPrice(..., freq)` | **`setIntervalPrice(..., unit, qty)`** — a DISTINCT method (gate round 10, P2: PHP cannot overload two same-named signatures); the deprecated `setPrice` keeps its frequency signature and dispatches internally — `ONE_TIME` resolves as `NULL`/`NULL` (§2.3) |
| `ArticleOverride::getDiscountAmount/Percentage(freq)` | same signatures over `BillingInterval`; `ONE_TIME` resolves against the NULL-cadence price (§2.3) |
| `DiagnosePriceOverlapsCommand` | command re-keyed to the interval columns |
| `ArticlePriceFactory` | state seeds `billing_unit`/`billing_quantity` |
| `OverlappingArticlePriceException` | **retained** (not retired): candidate payload re-keyed to the interval |

- **Declared limit — inputs outside the new domain (gate round 2 P1; qualified exception in `STABILITY.md` §3, gate round 3 P1):** shim inputs whose cadence the new model cannot represent — `WEEKLY`/`BIWEEKLY` — emit `E_USER_DEPRECATED` and throw a typed `LegacyCadenceUnsupportedException` with remediation text. The narrowing is not a shim exception carved ad hoc: it is recorded as the qualified exception of `STABILITY.md` §3 itself — measured imperative (zero corpus instances, survey 2026-08-12), unrepresentable by design (ADR-015 cadence domain), and unreachable in practice (the migration preflight, §4.3, loud-aborts any weekly row, so no 7.0 installation can hold such data). `UPGRADE-7.0.md` documents it as the one case where the deprecated surface narrows instead of forwards. No other shim narrows.
- Consumer-visible config: `larabill.agreements.plan_change.policy` added; no other config defaults touched.

## 6. Test plan

- **Unit:** `BillingInterval` math (months/years, overflow, 1–12 / 1–10 validation), cause-scope integrity, state machine transitions incl. illegal ones.
- **Integration (MySQL + MariaDB, both drivers):** cadence scheduling across the boundary (day-grain — AID-974 lesson), unique re-keying of prices, upgrade-path tests seeding legacy 6.x rows and asserting the §4 mappings row by row.
- **Loud-abort tests:** weekly row present; biweekly row present independently (stored values 1 and 2, gate finding #9); out-of-domain raw values (frequency 10, status 9) abort with remediation text; a mapping limited to canonical values must NOT silently pass them.
- **Upgrade-path tests:** legacy rows seeded with raw stored values 0–4 migrate to the five target strings (gate finding #2); `renewal` backfill asserted on both `expires_at` branches; a raw frequency-0 price migrates to NULL cadence and stays queryable (gate finding #1).
- **Concurrency:** close/suspend under `pcntl_fork` where the old concurrency gates apply (`RUN_CONCURRENCY_IT`); plan-change flow under real writes; **guarantee settlement collision-and-adoption interleaving (gate round 14, P3)** — forked processes race the same pending registry row: exactly one rectificative is issued (UNIQUE operation-key collision), the loser adopts by key or completes as a no-op, and a Phase-2 failure after a committed Phase 1 is adopted on retry (§3.4).
- **Sensitivity proofs:** every new guard proven red-able (disable the mechanism → red).

## 7. Task breakdown (execution order)

| # | Task | Ticket | Prereq |
| -- | -- | -- | -- |
| 0 | Settlement artifacts service (ADR-014) — rectificative/anulation/abono-line engine | AID-971 | — |
| 1 | `BillingInterval` + cadence schema on prices & agreements + deterministic migration + loud preflight | AID-952 | — (0 may run in parallel; sequencing below is the operator's chain) |
| 2 | Lifecycle v2: states, cause vocabulary, close/suspend API, expiry rewire, `account_status` | AID-949 | #0, **#1** |
| 3 | Modality + renewal + emission gate changes | AID-953 | #1, **#2** |
| 4 | Guarantee: product capability, `GUARANTEE` state, registry, rectificative wiring | AID-953 | #0, #2 |
| 5 | Plan change policy | AID-953 | #0, #2, **#3** |
| 6 | Import support: `is_historical`, zero-invoice guidance, importer contract updates | AID-895 side | #1–#3 |
| 7 | Deprecated shims over the retired surface + `UPGRADE-7.0.md`, snapshots, manifest, `approvals/majors/` entry, tag | release | all |

## 8. Open questions for the adversarial gate

1. `account_status` shape: two values enough (`up_to_date`/`overdue`), or does partial payment belong here?
2. `GUARANTEE` → `CLOSED` trigger: manual consumer action vs package-side listener on refund confirmation.
3. Fate of `cancellation_requested_at`/`cancellation_effective_at` columns: subsumed by the close API, or kept as closure timestamps?
4. ~~§4.2 status-mapping assumptions~~ — **resolved by gate round 1:** causes left NULL where legacy data cannot establish them (§4.2); only `EXPIRED → fulfilment` maps a cause.
5. `modality = non_billable` vs `renewal` interaction: can a non-billable agreement be closed-term (yes, per design) — does the engine need any special casing beyond "never emits"?

## 9. Release governance — resolved (owner decision B, 2026-10-06)

Gate round 1 found the conflict: outright removal in 7.0 vs the deprecation window of `STABILITY.md:26-32`. **The owner chose to honour the window** — for good cause and in case affected consumers exist:

- **7.0:** new model runs internally; retired public symbols remain as deprecated shims mapped to it (no second engine, no logic coexistence).
- **8.0:** shims removed.
- This amends the earlier no-compatibility-layer decision recorded in ADR-015 §8 (see its Enmienda 1). The gate's closing sequence proceeds: corrections-audit round, then an identical repeat, union of findings.

## 10. Gate adjudication

### Round 1 — session `01a1158d-2244-77e2-b977-31b74f46bcfd`, over `0517202`

Verdict: method RIGHT on schema/data, BLOCKED on release governance. 10 VERIFIED (4 blockers) + 2 SUSPECTED — all accepted, none discarded.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 | One-time prices have no valid target (nullable cadence) | Accepted | §2.3, §4.1, §6 |
| 2 | Status mapping must key on persisted ints 0–4, not letters | Accepted | §4.2 |
| 3 | Legacy causes not provable — preserve NULL (only EXPIRED→fulfilment) | Accepted | §4.2 |
| 4 | `renewal` backfill rule missing | Accepted | §2.1, §4.2, §6 |
| 5 | Preflight must reject every out-of-domain legacy value | Accepted | §4.3, §6 |
| 6 | Cause-scope invariant is application-level only | Accepted | §2.2 |
| 7 | Breaking-surface list not exhaustive | Accepted | §5 |
| 8 | Plan-change abono must pin the F1 artifact | Accepted | §3.5 |
| 9 | Biweekly abort test missing (separate stored value) | Accepted | §6 |
| 10 | Task chain not encoded in prerequisites | Accepted | §7 |
| S1 | Guarantee registry: original invoice link + idempotency | Accepted | §2.6 |
| S2 | Overflow semantics must be pinned (preserve current) | Accepted | §2.4 |

### Round 2 — over `0517202..c38e6aa` (revision + decision B)

Verdict: `GATE: FAIL` — 3 VERIFIED (2 P1, 1 P2), 0 SUSPECTED. All accepted.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Partial unique index not portable to MySQL/MariaDB — nullable-sentinel pattern instead | Accepted | §2.6 |
| 2 (P1) | Shim promise fails for WEEKLY/BIWEEKLY inputs — declared limit: deprecation + typed exception | Accepted | §5 (shim contract) |
| 3 (P2) | Per-symbol replacement contract missing from §5 | Accepted | §5 (mapping table) |

### Round 3 — over `c38e6aa..fa63980` (round-2 corrections)

Verdict: `GATE: FAIL` — 2 VERIFIED (1 P1, 1 P2), 0 SUSPECTED. Both accepted. The sentinel mechanism itself was verified sound against the precedent and its real-engine test (`tests/Integration/Mysql/GroupedPaymentConstraintsTest.php:59-75`).

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Throwing on WEEKLY/BIWEEKLY still violates `STABILITY.md:26` — needs the qualified exception in the contract itself | Accepted | `STABILITY.md` §3 (narrow, dated exception) + §5 declared limit |
| 2 (P2) | Replacement table not exhaustive; `AgreementStatus` undefined; "interval-keyed equivalents" not a named replacement | Accepted | §5 mapping table completed row by row |

### Round 4 — over `dfa2d4a` (round-3 corrections)

Verdict: `GATE: FAIL` — 1 VERIFIED (P3), 0 SUSPECTED. Accepted.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P3) | Two cross-references of the replacement table inaccurate (`AgreementStatus` → §4 instead of §3.1; `PricingService` → §3.3) | Accepted | §5 (commit `7b9bdd1`) |

### Round 5 — over `7b9bdd1` (round-4 correction)

Verdict: **`GATE: PASS`** (bounded). 0 VERIFIED, 0 SUSPECTED. Locator-only correction; the bounded phase converged.

### Round 6 — unbounded, the path AFTER the mechanisms, over `7b9bdd1`

Verdict: `GATE: FAIL` — 8 VERIFIED (4 P1, 3 P2, 1 P3), 0 SUSPECTED. All accepted, none discarded. The kind of finding changed as predicted: end-to-end gaps no bounded diff could surface.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Deferred 6.x cancellations lose their executable behaviour on upgrade | Accepted | §2.1 (`close_scheduled_at`), §4.2 (deferred-closure row) |
| 2 (P1) | `cancel()` shim has no non-invented forwarding contract | Accepted | §2.2 (`legacy_cancellation` seed), §5 (shim row) |
| 3 (P1) | Historical zero invoices: series prefix is not fiscal quality — route via `InvoiceSerieType::PROFORMA` | Accepted | §2.5 |
| 4 (P1) | Plan change lacks an atomic/idempotent boundary | Accepted | §3.5 (lock + revalidation, interleaving test) |
| 5 (P2) | One-time price/discount shims lack a named NULL-cadence operation | Accepted | §5 (nullable unit/qty rows) |
| 6 (P2) | Guarantee eligibility not frozen or anchored | Accepted | §2.1 (`guarantee_days` snapshot), §3.4 |
| 7 (P2) | Plan-change eligibility for suspended/guarantee unspecified | Accepted | §3.5 (active-only) |
| 8 (P3) | §10 missing rounds 4–5 records | Accepted | this section |

### Round 7 — bounded audit of the round-6 corrections, over `8b95a71`

Verdict: `GATE: FAIL` — 7 VERIFIED (4 P1, 2 P2, 1 P3) + 1 SUSPECTED (P2). All accepted; the SUSPECTED item resolved in the same revision.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Scheduled closures remain billable — emission gate must exclude periods beyond `close_scheduled_at` | Accepted | §3.2 (scheduled-close semantics) |
| 2 (P1) | END/NOTICE shims cannot preserve timing through immediate-only `close()` | Accepted | §3.2 (shim timing: effectiveAt + legacy date computation verbatim) |
| 3 (P1) | A cancellation effective today is lost on upgrade (boundary hole) | Accepted | §4.2 row 2.5 (today-or-past → closed) |
| 4 (P1) | Plan change can overwrite a scheduled closure | Accepted | §3.5 (eligibility excludes `close_scheduled_at`) |
| 5 (P2) | `expires_at` vs `close_scheduled_at` precedence undefined | Accepted | §3.2 (first wins, other ignored) |
| 6 (P2) | Importer lacks its terminal-state contract | Accepted | §2.5 (WHMCS mapping table) |
| 7 (P3) | Deferred-active rule placed under the SUSPENDED row | Accepted | §4.2 (own row 2.5) |
| S1 (P2) | Plan-change successor guarantee window unstated | Accepted | §3.5 (new contract, own snapshot) |

### Round 8 — bounded audit of the round-7 corrections, over `acbc3a4`

Verdict: `GATE: FAIL` — 3 VERIFIED (1 P1, 2 P2), 0 SUSPECTED. All accepted.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | The two legacy NOTICE computations are distinct metadata routes — shim must preserve each public path, not an abstraction | Accepted | §3.2 (per-path shim timing) |
| 2 (P2) | Equal-date closures nondeterministic — tie-break + single locked materialiser | Accepted | §3.2 (scheduled close wins on equal dates) |
| 3 (P2) | §3.5 requires schedule cancellation but no such API existed | Accepted | §3.2 (`cancelScheduledClose`) |

### Round 9 — bounded audit of the round-8 corrections, over `700c08c`

Verdict: **`GATE: PASS`** (bounded). 0 VERIFIED, 0 SUSPECTED. All three round-8 corrections land; the bounded phase converged for the second time.

### Round 10 — identical repeat of the unbounded round over the final revision `700c08c`

Verdict: `GATE: FAIL` — 6 VERIFIED (2 P1, 3 P2, 1 P3), 0 SUSPECTED. All accepted; union with round 6 taken (no overlap: the repeat found runtime-compatibility and partial-failure gaps round 6 did not).

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Deprecated `cancel()` does not preserve the public `ServiceCancelled` event behaviour | Accepted | §5 (shim dispatches the legacy `@api` event) |
| 2 (P1) | Guarantee rectification lacks an atomic/idempotent settlement boundary | Accepted | §3.4 (one transaction, retry adopts existing rectificative) |
| 3 (P2) | `setPrice` overload impossible in PHP — distinct replacement method needed | Accepted | §5 (`setIntervalPrice`, shim dispatches internally) |
| 4 (P2) | WHMCS cadence import contract missing (mapping, blocking, idempotent re-run) | Accepted | §2.5 (import cadence contract) |
| 5 (P2) | `align_end` undefined for auto-renewing agreements | Accepted | §3.5 (rejected combination) |
| 6 (P3) | §10 omitted the round-9 PASS record | Accepted | this section |

### Round 11 — bounded audit of the round-10 corrections, over `6d4ca34`

Verdict: `GATE: FAIL` — 2 VERIFIED (1 P1, 1 P2), 0 SUSPECTED. All accepted.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Retry-adoption cannot find a rectificative issued before the registry update — needs a durable operation identity stamped on the artifact at creation | Accepted | §3.4 (`settlement_operation_key` on the rectificative, same-transaction stamp) |
| 2 (P2) | Import re-run identity not an exact key — named `origin_key` with UNIQUE index | Accepted | §2.5 (`whmcs:<table>:<id>` format) |

### Round 12 — bounded audit of the round-11 corrections, over `241e39a`

Verdict: `GATE: FAIL` — 1 VERIFIED (P1), 0 SUSPECTED. Accepted.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | One-transaction settlement contradicts retry adoption: a rolled-back transaction leaves no artifact to adopt — issuance must be independently durable | Accepted | §3.4 (two-phase boundary: issuance+stamp atomic; registry completion separate; adopt-or-issue under registry lock) |

### Round 13 — bounded audit of the round-12 correction, over `55a56f2`

Verdict: `GATE: FAIL` — 1 VERIFIED (P1), 0 SUSPECTED. Accepted.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P1) | Phase 2's lock nests Phase 1 as a savepoint (or, if released, permits concurrent issuance) — no UNIQUE invariant on the operation key meant duplicate fiscal corrections remained possible | Accepted | §3.4 (deterministic key + UNIQUE index; collision-and-adoption; no lock held across the independent commit) |

### Round 14 — bounded audit of the round-13 correction, over `1126236`

Verdict: `GATE: FAIL` — 1 VERIFIED (P3), 0 SUSPECTED. Accepted. The reviewer's own method ruling: the collision-and-adoption mechanism closes the round-13 P1 — no interleaving can issue two corrections under the declared unique operation key.

| # | Finding | Decision | Landed in |
| -- | -- | -- | -- |
| 1 (P3) | §3.4 claimed the §6 interleaving test but §6 never specified it — false cross-reference | Accepted | §6 (guarantee settlement collision-and-adoption interleaving named explicitly) |

### Round 15 — bounded audit of the round-14 correction, over `5231992`

Verdict: **`GATE: PASS`** (bounded). 0 VERIFIED, 0 SUSPECTED.

### Coverage statement — what the verdicts cover

Fifteen rounds over nine revisions (`0517202` → `5231992`), all findings measured against the tree and adjudicated above; none discarded:

- **Detection of design defects:** rounds 1 (full, unbounded: 10+2), 2–5 (bounded corrections, 3→2→1→PASS), 6 (unbounded, path-after: 8), 7–9 (bounded, 7→3→PASS), 10 (identical repeat of the unbounded round over the final model: 6, **no overlap with round 6**), 11–14 (bounded, 2→1→1→1), 15 (PASS over `5231992`).
- **What the verdicts cover:** the SPEC as a plan — its schema, mappings, shims, flows, concurrency boundaries, and test plan — plus its coherence with the current tree (`src/`, migrations, `STABILITY.md`, ADR-014).
- **What NO round executed:** there is no implementation yet; no real MySQL/MariaDB migration was run; no production data was touched; the AID-895 corpus figures are cited from the survey, not re-measured. Every claim about future code remains unproven until the TDD phase executes it — the implementation gate (before merge/tag) is a separate, later gate.

Readiness is the owner's decision taken with this paragraph, not with the last verdict.
