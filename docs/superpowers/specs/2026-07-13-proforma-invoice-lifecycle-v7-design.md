# Proforma → Invoice Lifecycle Redesign (v7) — Design

- **Date:** 2026-07-13
- **Status:** Draft — pending adversarial review (Codex) before implementation planning
- **Supersedes:** AID-444 (rejected as specified — see §13), reframes AID-442's data layer
- **Related:** ADR-001 (fiscal config freezing), ADR-003 (user/customer unification), AID-307 (fiscal series vs type), AID-328 (snapshot vs live-row comparison), AID-390 (numbering owner), `STABILITY.md`
- **Target release:** v7.0.0 (major — qualified imperative documented in §1)

## 1. Motivation — forensic findings on the current conversion

`InvoiceService::convertProformaToInvoice()` exists since before v1.0, is public `@api` surface (`docs/api-surface.md`), and has **zero tests**. A forensic review (2026-07-13) confirmed the following defects, each independently a fiscal-correctness or integrity problem:

| # | Defect | Evidence |
|---|--------|----------|
| D1 | No row locking: `refresh()` instead of `lockForUpdate()`; the idempotency check is TOCTOU — two concurrent conversions can produce two final invoices | `src/Services/InvoiceService.php:346,353` |
| D2 | The canonical link is never written: `invoices.proforma_id` exists and `convertedInvoices(): HasMany` reads it, but conversion only writes `converted_invoice_id`; the bidirectional link is broken from birth | `src/Models/Invoice.php:397`, `InvoiceService.php:409-415` |
| D3 | Line reconstruction copies only 4 fields (`article_id`, `description`, `quantity`, `base_price`); it discards `item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata`, and the immutable `taxes_applied` snapshot | `InvoiceService.php:391-396` |
| D4 | Taxes are RECALCULATED against the live catalog (`Article::find()->tax_group_id`, `TaxGroup::find()->with('taxRates')`) — the amounts the customer saw on the proforma can silently change | `src/Services/TaxCalculationService.php:98-103` |
| D5 | No target fiscal series can be selected at conversion, although `createInvoice()` supports explicit series | `InvoiceService.php:388-402` |
| D6 | Conversion, expedition and fiscal registration are collapsed: the final invoice is born `PENDING` + immutable + numbered + dated in one step | `InvoiceService.php:398,405` |
| D7 | Conversion does not require the proforma to be frozen or paid; any `serie=PROFORMA` row converts | `InvoiceService.php:348` |
| D8 | `Invoice` immutability does not protect `InvoiceItem`: no update/delete guard exists on the line model — lines of an issued invoice can be edited or deleted | `src/Models/InvoiceItem.php` (no guard) |
| D9 | Contradictory cardinality: `converted_invoice_id` (scalar, 1:1) coexists with `proforma_id` + `convertedInvoices()` (1:N); the database enforces neither | `Invoice.php:79,397`, migration `2024_12_01_000003:45` |
| D10 | `service_date` is populated by no service — nullable, only the factory writes it (randomly); the operation-date row (AID-442) fires only by accident | grep across `src/` |

A green build (26 numbering/tax/drift tests pass) does not cover the conversion contract. These defects are the **qualified, documented imperative** that `STABILITY.md` requires for a breaking release.

## 2. Domain ownership and boundary rules

Larabill is the invoicing engine and the **sole owner** of the proforma domain:

- **Larabill owns:** proforma lifecycle, freezing of lines and amounts, conversion to invoice, fiscal numbering, determination and persistence of the tax snapshot, the traceable proforma→invoice link, fiscal immutability, and the rules around operation date, advance payment and expedition.
- **Consumers own:** when to invoke those operations, their UX, and their commercial domain (acceptance, payment gateways, orders).
- **Consumers may NOT:** clone lines, recalculate or copy taxes, assign fiscal numbers, or implement an alternative conversion. A consumer-side port is legitimate only when it adapts a larabill primitive; if it contains the fiscal algorithm because larabill does not offer it, it is duplicated domain under another name.

**Operative blocking rule:** if a consumer needs to write directly into `invoices`, `invoice_items`, snapshots, totals, series or conversion links, the consumer work is BLOCKED and a capability-gap ticket is opened in larabill first. No local fallback is admitted.

Two questions must never be conflated: domain authority belongs to larabill; real consumer usage only informs SemVer/migration risk (§12 is the only section where consumers appear).

## 3. Settled design decisions (the constitution of this redesign)

1. Larabill owns the full proforma and conversion domain (§2).
2. AID-444 is not implemented as specified; it is superseded by this design (§13).
3. A frozen proforma freezes its **commercial contract**; what is contractually binding depends on the persisted price semantics (§4.3).
4. Conversion never consults the live catalog (articles, prices, tax groups, tax rates) to rebuild lines.
5. The tax snapshot is fixed according to an **explicit accrual (devengo) fact and its date**, determined by larabill from economic facts — never delivered pre-resolved by the consumer (§5).
6. Cardinality is **strict 1:1**: one proforma originates exactly one final invoice. `unique(invoices.proforma_id)` guarantees "at most one"; the atomic `FROZEN → CONVERTED` transition guarantees "exactly one". Future 1:N (partial advances) is a safe expansion: drop the unique and add explicit allocations — own ticket, out of scope here.
7. `service_date` represents a **fact**, never a configurable preference. `service_date == invoice_date` can only result from both real dates coinciding. No global boolean exists.
8. Conversion, expedition and fiscal registration are **distinct transitions** with distinct state axes (§6).
9. Conversion is atomic, locked and idempotent, backed by database constraints.
10. The invoice keeps a verifiable canonical link to its proforma (`proforma_id`); `converted_invoice_id` is deprecated with an exact-equivalence dual-write during v7 and removal in v8.
11. The target fiscal series is resolved inside larabill (`InvoiceSeriesResolver`) and can be indicated per operation.
12. This redesign is the **evolution of a public API**, not greenfield. Every schema/data change ships its upgrade program in the same PR (AID-398).
13. New operations take **typed commands/DTOs**, not a widened ambiguous `$options` array. No `legacy_recalculate=true` escape hatch exists.
14. Consumer references appear only in the impact/migration section (§12).

## 4. Proforma documental lifecycle

### 4.1 `ProformaStatus` — a separate enum and column

Proforma states do NOT enter `InvoiceStatus` (which already mixes document, delivery, collection and process axes). A new `ProformaStatus` enum backs a new nullable `invoices.proforma_status` column, canonical for rows with `serie = PROFORMA`:

```text
DRAFT ──freeze()──▶ FROZEN ──convert()──▶ CONVERTED   (terminal)
  │                    ├──supersede()──▶ SUPERSEDED   (terminal)
  │                    └──cancel()─────▶ CANCELLED    (terminal)
  └──cancel()──▶ CANCELLED (terminal)
```

- **DRAFT:** editable. Not convertible — `convert()` on a DRAFT fails loud (closes D7). Its identity snapshots are **provisional** (§4.4).
- **FROZEN:** commercial content immutable (§4.3). The only state from which conversion is legal.
- **CONVERTED:** terminal; linked 1:1 to its invoice.
- **SUPERSEDED:** terminal; neither editable nor convertible. Design inference from the AEAT traceability requirement (proformas must be preserved and linked to definitive invoices), not a literal prescription of the norm.
- **CANCELLED:** terminal. A frozen proforma may be abandoned without a successor.

**Guards:**

- Once an accrual has been **materialized** (§5.4), the proforma can no longer be cancelled or superseded to make it disappear: after accrual, an invoice must be issued; any later correction belongs to the rectificative domain (out of scope, own ticket).
- `supersede()` creates/links the successor and closes the predecessor **atomically** in one transaction.
- State transitions are executed and validated by larabill; consumers only request them.

`InvoiceStatus` remains in v7 for compatibility but stops being authority for proforma document state. No general read-compatibility is promised: `FROZEN` and `SUPERSEDED` have no legacy case, so any legacy projection is documented as lossy. Larabill never writes false legacy values (e.g. `DRAFT` for a `FROZEN` proforma) to keep old code running.

### 4.2 Supersession — a single FK

The successor carries `supersedes_proforma_id` (self-referencing FK). The inverse relation is a query from the predecessor — no second physical pointer (avoids reproducing the D9 contradiction).

- `unique(supersedes_proforma_id)` — only one successor may exist.
- Self-reference forbidden; cycle prevention enforced in the service; both sides must be proformas.

### 4.3 What FROZEN freezes — price semantics

A new `price_tax_mode` value (`tax_exclusive` | `tax_inclusive`) is persisted **per line** as part of the frozen contract:

- **`tax_exclusive`:** the agreed net base/unit price is binding; tax and total may change if the law changes between freeze and accrual.
- **`tax_inclusive`:** the agreed gross total is binding; base and quota redistribute (with a defined rounding/residual rule per line) if the rate changes.

It is impossible to promise base, quota and total simultaneously invariant under a rate change; the persisted mode declares which amount is the contract. Historical backfill is `tax_exclusive` — this makes explicit the contract `base_price` has always implicitly carried, it does not introduce a new preference.

The frozen line keeps its commercial terms plus, optionally, a provisional tax computation **clearly identified as provisional**. The definitive tax truth lives in a separate record (§5.4) — the frozen commercial line is never mutated to turn an estimate into fiscal truth.

### 4.4 Identity snapshots — provisional at DRAFT, frozen at freeze()

A draft can live for months; the issuer/customer identity valid at creation may not be the one valid at freezing or accrual. Therefore:

- Snapshots generated at DRAFT creation are **provisional**.
- `freeze()` re-resolves issuer and customer identity and freezes them for the proforma (document identity).
- At accrual/expedition, larabill validates that the applicable fiscal identity is still compatible (via `FiscalChangeDetector`, which since AID-328 compares against persisted snapshots).
- The final invoice carries its **own** fiscal snapshot; it does not blindly copy the proforma's identity when a legally relevant change exists. The original proforma stays intact as the commercial origin.

## 5. Tax resolution — accrual facts, epochs, resolver, determinations

### 5.1 Economic facts (input) vs fiscal judgment (larabill's)

Consumers register **economic facts** through an explicit, audited larabill operation (append-only table; indicative name `billing_economic_facts`): payment received (date, amount), service delivered (date or period), agreed chargeability (exigibilidad) for successive-tract contracts. Each fact carries actor, source and timestamp.

**Larabill determines the accrual** by applying the fiscal rule (art. 75 LIVA semantics for the Spanish region; region-pluggable like the rest of the package): advance payment → accrual at payment date; one-off service → accrual at delivery; successive tract → accrual at chargeability. The consumer never hands over a resolved accrual (type+date); it hands facts.

When legal classification requires human judgment, larabill accepts an **explicit auditable decision** (§5.5), never an opaque date or rate.

### 5.2 Catalog epochs — the provable interval

Timestamp inference (`MAX(updated_at)`) is REJECTED as proof: physical deletes destroy evidence, the pivot has no history, query-builder/SQL/imports may bypass timestamps, and code/strategy/rounding changes never appear in those tables. It would prove informatic catalog stability at best, never juridical validity.

Instead, larabill maintains **explicit catalog epochs** (indicative name `tax_catalog_epochs`):

- **Fields:** revision identifier, `observed_from`, hash of the complete rule set (rates, groups, group↔rate relations, special conditions), resolver algorithm version.
- **Closure:** an epoch closes when ANY relevant fiscal dependency changes — catalog mutation (all mutation paths must flow through larabill or be detected at resolution time by hash mismatch) or a new resolver/rounding code version.
- **Claim it supports:** if the accrual date is on/after the epoch's known start and that same revision is still active, the state larabill observed did not change during the interval.
- **Honest limits (documented):** the first epoch starts when the mechanism is installed — no retroactive reconstruction; it proves system-observed stability, not that the configuration was juridically correct.

### 5.3 `EffectiveTaxRuleResolver` — the dated resolution contract

All tax resolution flows through one resolver (name indicative). It resolves a **complete rule**, not a nominal rate:

- **Input:** the line's frozen fiscal facts (tax classification, group membership, jurisdiction, exemptions/reverse-charge, applicable fiscal profile, `price_tax_mode`) + the accrual date.
- **Output:** an immutable tax snapshot containing at minimum: applied rule with identity/version, base and quota, rounding rule used, requested effective date, resolution moment, **source** (`epoch` | `override`; `temporal` reserved for the future catalog-versioning ticket), and override audit metadata when applicable.
- **Fail-loud contract:** the resolver fails with a specific exception when the requested date falls **outside the interval it can prove** (per §5.2). It never silently reuses the present catalog for a past or future date — even if the rate "probably" did not change, larabill cannot affirm it without history. Conservative by design.

Phase contract (settled): (1) every tax resolution goes through the dated resolver; (2) the initial implementation resolves automatically only the provable present (the active epoch); (3) dates outside it fail explicitly; (4) overrides require an audited decision, never silent; (5) the resulting snapshot is materialized at the accrual fact; (6) later conversion copies the snapshot, never re-resolves; (7) full catalog temporality is designed in its own ticket; (8) that ticket enables automatic historical resolution without changing callers; (9) automatic late registration is NOT declared supported until then.

**Operational gate (measured, not assumed):** override frequency is metered from day one. If late fact registration crossing epoch boundaries turns out frequent, the catalog-versioning ticket ascends to prerequisite. No data exists today to claim it is.

### 5.4 Tax determinations — separate immutable records

The accrual materializes a **tax determination**: a separate, immutable record set (indicative name `tax_determinations`) per proforma line, produced by the resolver at the accrual date. The frozen commercial line is not touched. At conversion, the invoice copies the determination into `InvoiceItem.taxes_applied` (and derived totals). This resolves the impossibility of one frozen row holding both provisional and definitive taxes.

### 5.5 Override — an explicit domain operation

Never `rate => 2100` in an options array. The flow is:

1. Larabill recognizes it lacks history for the requested date → specific exception.
2. An authorized human adopts the fiscal decision.
3. Larabill receives it through an explicit operation that validates its shape and persists actor, reason, documentary source and timestamp.
4. The resulting snapshot records `source = override` with the audit metadata; the invoice keeps it immutably.

### 5.6 Future: full catalog versioning (own ticket)

Adding `valid_from`/`valid_until` columns is insufficient if rows can be edited in place. The complete solution (deferred, designed separately) must guarantee: rules used are never retro-modified; a rate change creates a new version and closes (never overwrites) the previous one; group↔rate relations are historically reconstructible; deleted records remain accessible for fiscal reconstruction; no ambiguous overlaps per rule/jurisdiction/period. Same lesson as AID-328 applied to the tax catalog.

## 6. Invoice documental lifecycle — conversion, expedition, registration

### 6.1 `InvoiceDocumentStatus` — separate document axis for invoices

`PREPARED` does not enter `InvoiceStatus` (it would repeat the axis-mixing just corrected for proformas). A new `InvoiceDocumentStatus` enum backs a new column:

```text
DRAFT ──▶ PREPARED ──issue()──▶ ISSUED   (terminal for this axis)
  │           │
  └───────────┴──▶ CANCELLED   (only BEFORE expedition)
```

- **CANCELLED** is only reachable before expedition. An ISSUED invoice is never cancelled: it is rectified (rectificative domain).
- **Fiscal registration is a separate axis** — `FiscalRegistrationStatus`: `NOT_REQUIRED` | `PENDING` | `REGISTERED` | `FAILED`, driven by the outbox (§6.3). "Distinct transitions" never means an expedited invoice may indefinitely lack its mandatory registration: `PENDING`/`FAILED` are visible, monitorable alarm states.
- `SENT`, `PAID`, `OVERDUE` remain delivery/collection legacy in `InvoiceStatus` until consciously separated (out of scope). `InvoiceStatus` stays in v7 for compatibility but stops being authority for the document cycle.
- `createInvoice()` keeps its combined external behavior in v7 (prepare + issue atomically — it numbers and dates at creation today); the two-step path is what conversion uses and what consumers get via the new primitives.

### 6.2 Conversion (rewritten `convertProformaToInvoice`)

Preconditions: proforma is `FROZEN`; its accrual is materialized (tax determination exists); no prior conversion (idempotent: a second call returns the same invoice).

Atomic sequence (one DB transaction, `lockForUpdate()` on the proforma):

1. Lock the proforma; re-validate state and idempotency under the lock (closes D1).
2. Validate identity compatibility (§4.4) via `FiscalChangeDetector` against persisted snapshots.
3. Create the final invoice as **PREPARED**: complete 1:1 copy of all line columns (`item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata`) with `taxes_applied` and amounts taken from the **tax determination** (§5.4) — never from the live catalog (closes D3/D4; the conversion path must not even hold a reference to catalog models).
4. Persist the selected target series (`prefix`) resolved through `InvoiceSeriesResolver`, indicated per operation (closes D5). The correlative is NOT consumed yet.
5. Derive `service_date` from the accrual facts (advance → payment date; delivery → service date; tract → chargeability period) — closes D10 without any boolean.
6. Write `invoices.proforma_id` on the final invoice (canonical link, closes D2) and dual-write `converted_invoice_id` on the proforma (deprecated mirror).
7. Transition the proforma to `CONVERTED`.

A PREPARED invoice knows its fiscal type, target series, lines, snapshots and `service_date`; it does NOT yet have `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`. It is closed to ordinary modifications even though not fiscally expedited.

### 6.3 Expedition — `issue()`

Atomic local sequence (no external API call inside the DB transaction):

1. Lock the PREPARED invoice.
2. Validate the tax determination is complete.
3. Resolve the series (already persisted; re-validated).
4. Assign the correlative number atomically (`InvoiceNumberingService`, sole owner per AID-390). `fiscal_year` derives from the **real expedition date**, not preparation.
5. Set `invoice_date` and `issued_at`.
6. Mark content fiscally immutable (header + lines).
7. Create the durable **outbox** record for mandatory registration (when compliance requires it) — indicative name `fiscal_registration_outbox`.
8. Transition to `ISSUED`.

Outbox guarantees: an expedited invoice never loses its registration intent; `PENDING`/`FAILED` are visible and monitorable; retries are idempotent; an external failure never reverts nor reuses the fiscal number.

### 6.4 `convertAndIssue()` — convenience with defined atomicity

One call, two distinct internal transitions:

- Failure **before** number assignment → nothing converted (full rollback; proforma untouched, no counter consumed).
- Failure **after** expedition → the invoice stays ISSUED with registration `PENDING`/`FAILED`; expedition is never reverted.
- Two invocations return the same invoice (idempotent), never a second one.

## 7. Schema and migration program

Every migration ships with its `.php.stub`, `$migrationOrder` entry, manifest entry (`in_base: false`), install-test count bump, and its data program + upgrade-path test in the **same PR** (AID-398, AID-412 gates).

### 7.1 `invoices`

- **`proforma_status`** — nullable tinyint, `ProformaStatus` cast. Coherence constraint where the engine supports it: `serie = PROFORMA ⇔ proforma_status IS NOT NULL` (CHECK on MySQL 8; documented invariant + model guard elsewhere).
- **Backfill precedence (conservative, preflight-gated):** (1) valid conversion link → `CONVERTED`; (2) cancelled → `CANCELLED`; (3) `DRAFT` and mutable → `DRAFT`; (4) immutable or any non-editable legacy state (`PAID`, `SENT`, `OVERDUE`, `PENDING`) → `FROZEN` (never auto-`DRAFT` — that would reopen documents); (5) inconsistencies → **fail loud in preflight, never a silent default**, with an upgrade report.
- **Preflight detections (abort without modifying data):** `status=CONVERTED` without linked invoice; `converted_invoice_id` pointing to a non-fiscal-invoice row; two proformas pointing at the same invoice; an invoice whose `proforma_id` contradicts the inverse link.
- **`proforma_id` becomes canonical:** backfill from existing `converted_invoice_id` (inverse direction) — preflight verifies the target exists, has no other proforma, no two proformas share a target, and no existing `proforma_id` is contradicted. Never overwrite an existing relation to "make the migration pass". Then add `unique(proforma_id)` (MySQL: multiple NULLs allowed). FK changes `nullOnDelete` → `restrict` (documentary preservation).
- **`converted_invoice_id`:** deprecated; exact-equivalence dual-write during v7; removed in v8 (fulfils the deprecation cycle).
- **`supersedes_proforma_id`** — nullable self-FK, `restrict`, `unique` (one successor max); no self-reference; cycle prevention in the service.
- **`invoice_document_status`** — new column, `InvoiceDocumentStatus` cast. **Legacy backfill: all existing fiscal invoices → `ISSUED`** (they all carry number, `invoice_date`, `issued_at` because those were mandatory). Classifying them PREPARED would allow altering documents that already consumed fiscal numbering. The upgrade report flags "numbered drafts" (legacy `InvoiceStatus::DRAFT` with a fiscal number) explicitly.
- **`fiscal_registration_status`** — new column, backfilled from existing verifactu state where present, else `NOT_REQUIRED`.
- **Nullable fiscal fields for PREPARED:** `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at` become nullable. **`prefix` stays NOT NULL** — it persists the selected target series before expedition. Unique indexes (`fiscal_number`; `prefix, serie, series_number, fiscal_year`) tolerate NULLs per engine semantics.

### 7.2 `invoice_items`

- **`price_tax_mode`** — enum-backed string/tinyint, backfill `tax_exclusive` (explicit historical contract, §4.3).
- **Immutability guard, with its limit declared:** model events (`saving`, `deleting`) reject changes when the header state forbids them, and every package public operation respects the guard. Bulk `query()->update()/delete()`, direct SQL and external writes are **outside the contract** — documented explicitly (DB-level enforcement is a possible future hardening, not promised here).

### 7.3 New tables (names indicative, fixed at plan time)

- **`billing_economic_facts`** — append-only: proforma FK, fact type (payment/delivery/chargeability), fact date (or period), amount, actor, source, metadata, timestamps.
- **`tax_determinations`** — immutable: proforma line FK, applied rule identity/version, base, quota, rounding rule, effective date requested, resolution moment, source (`epoch`/`override`), override audit (actor, reason, documentary source, timestamp), epoch revision FK.
- **`tax_catalog_epochs`** — revision id, `observed_from`, rule-set hash, resolver algorithm version, `closed_at`.
- **`fiscal_registration_outbox`** — invoice FK, attempt count, last error, next retry, terminal state; idempotency key.

## 8. Public surface and SemVer

- **v7.0.0 major.** Qualified imperative: the D1–D10 forensic table (§1) — fiscal-correctness and integrity defects, not aesthetics. Complies with `STABILITY.md`.
- **`convertProformaToInvoice()` is rewritten in place** with the new contract (preconditions FROZEN + materialized accrual; PREPARED output). The defective semantics are NOT preserved behind a deprecation: keeping alive a path that recalculates against the live catalog, loses tax snapshots, races and breaks the canonical link would be worse than the breaking change. No `legacy_recalculate=true` option exists. Conditions honored: `UPGRADE-7.0.md` explains every new precondition; the CHANGELOG declares old and new behavior explicitly in bold; consumers can mechanically identify call sites needing freeze/facts/determination (each new precondition fails with a specific typed exception naming the missing step).
- **New `@api` operations** (typed commands/DTOs, no `$options` widening): `freezeProforma()`, `supersedeProforma()`, `cancelProforma()`, economic-fact registration, the audited override operation, `issue()`, `convertAndIssue()`.
- **New enums:** `ProformaStatus`, `InvoiceDocumentStatus`, `FiscalRegistrationStatus`. `InvoiceStatus` unchanged (kept for compatibility; authority reduced as per §4.1/§6.1).
- Contract-snapshot gates (AID-412) regenerate via `bin/sync-contract-snapshots` with their CHANGELOG gate; `@api`/`@internal` taxonomy extended to every new class.

## 9. Delivery phases (ordered PRs inside the v7 epic)

Foundations first — `freeze()` cannot bind amounts correctly before `price_tax_mode` exists:

1. **PR-1 — Schema foundations:** `ProformaStatus`, `InvoiceDocumentStatus`, `FiscalRegistrationStatus`, `price_tax_mode`, canonical links (`proforma_id` unique + backfill, `supersedes_proforma_id`), nullable fiscal fields, all preflights and backfills, `InvoiceItem` guard. `UPGRADE-7.0.md` is born here.
2. **PR-2 — Documental lifecycle + tax truth:** freeze/supersede/cancel with guards, economic facts, epochs, resolver, determinations, audited override.
3. **PR-3 — Conversion + expedition:** rewritten conversion (PREPARED), `issue()`, outbox + registration axis, `convertAndIssue()`, concurrency hardening.
4. **PR-4 — Release closure:** CHANGELOG promotion, manifest re-stamp, `UPGRADE-7.0.md` consolidation, tag via `bin/tag-release`.

**Documentation travels in every PR, not at the end:** each PR carries its `[Unreleased]` CHANGELOG entry, its upgrade path, regenerated snapshots when surface changes, timestamped migration + stub + `$migrationOrder`, and its consistency/manifest tests. PR-4 only consolidates; it never reconstructs justification a posteriori.

## 10. Testing contract

The conversion contract moves from 0 tests to a mandatory suite:

- **State machines:** every legal transition and every guard for `ProformaStatus` and `InvoiceDocumentStatus`; cancel/supersede after materialized accrual → typed exception; `CANCELLED` unreachable from `ISSUED`.
- **Backfills (upgrade-path, MySQL):** ambiguous data → preflight fails **without modifying anything**; cancelled+immutable proforma ends `CANCELLED`, not `FROZEN`; `PAID`/`SENT`/`OVERDUE`/`PENDING` proformas end `FROZEN`, never `DRAFT`; legacy numbered invoices end `ISSUED`; link backfill never overwrites an existing `proforma_id`; fresh install AND real upgrade-from-v6 (AID-412 harness), not only isolated backfills.
- **Conversion:** column-by-column copy exactness (dataset over every `invoice_items` column); the conversion path receives a **fake resolver/catalog that throws if invoked** (stronger than query-log assertions); idempotency (second call returns the same invoice); `proforma_id` written; per-operation series honored; `service_date` derived correctly for delivery, chargeability and advance-payment facts.
- **Concurrency (fork pattern AID-390, `RUN_CONCURRENCY_IT=1` + MySQL):** two concurrent conversions → exactly one invoice, loser returns the winner's invoice (defined idempotent result); two concurrent `issue()` → a single number consumed; sensitivity proven against the pre-fix implementation (restore `refresh()` temporarily → test must fail).
- **Price semantics:** `tax_exclusive` preserves the contractual base under a rate change; `tax_inclusive` preserves the gross total and redistributes base/quota with the defined rounding; determination never mutates the frozen line.
- **Resolver/epochs:** in-epoch resolution succeeds; out-of-epoch date → specific exception; override persists full audit (actor, reason, source, timestamp) and marks `source=override`; epoch closes on catalog mutation and on resolver version change.
- **Outbox:** external registration failure leaves a durable outbox row and visible `FAILED` state; retries idempotent; fiscal number never reverted/reused.
- **Immutability limit:** bulk `query()->update()/delete()` on lines — a test documents whether it is blocked or explicitly outside the guarantee (per §7.2 it is outside; the test asserts and documents that boundary).

## 11. Out of scope — follow-up tickets

- **Full temporal catalog versioning** (§5.6). Ascends to prerequisite only if override metrics demand it.
- **Partial advances (1:N):** multiple advance invoices per proforma + final regularization. The 1:1 unique is a safe expansion point.
- **Rectificative domain interactions** post-accrual (corrections after an accrual exists).
- **Separating delivery/collection axes** (`SENT`/`PAID`/`OVERDUE`) out of `InvoiceStatus`.
- **DB-level line immutability enforcement** (triggers/permissions) beyond the model guard.

## 12. Consumer impact and migration (the only section where consumers appear)

- Consumers currently orchestrating conversion locally (e.g. the Vía 1 flow in `clientes`) are **blocked from extending** that duplication and must migrate to the v7 primitives once released, then delete their local conversion/freeze domain. This is SemVer/migration risk information, not design input.
- Existing OSS consumers of `convertProformaToInvoice()` face new preconditions: `UPGRADE-7.0.md` documents the mechanical mapping (freeze first; register facts; handle the typed exceptions). Old call sites fail loud with actionable messages, never silently change meaning.
- The upgrade ritual is the standard one: `composer update` + idempotent `larabill:install` re-run + `php artisan migrate`, with the preflight aborting loudly on ambiguous data before touching anything.

## 13. AID-444 disposition

AID-444 is **closed as superseded by design**:

- Its premise ("the conversion flow does not exist, greenfield") is false — the flow exists as public surface since before v1.0 (§1).
- Its central mechanism (`proforma.align_service_date_on_conversion` boolean) models an issuer convenience, not a fiscal fact, and can write a false date. RD 1619/2012 art. 6.1 requires the operation date or the advance-payment date **when it differs from expedition**; the accrual belongs to the facts (art. 75 LIVA), and the documentary date represents it — it never creates or displaces it. Selecting a config value cannot move VAT across quarters; the economic fact already determined it.
- The UX warning it mandated is therefore also incorrect and is dropped.
- `service_date` in v7 derives from registered economic facts (§6.2 step 5). The advance-paid-June-30-converted-July-2 case yields `service_date = 2026-06-30` — the truth AID-444's default would have falsified.
- New Linear tickets are cut from this spec (the v7 epic and the §11 follow-ups); AID-442's presentation layer (already shipped in v6.3.0) is untouched and becomes correct automatically once `service_date` carries real facts.
