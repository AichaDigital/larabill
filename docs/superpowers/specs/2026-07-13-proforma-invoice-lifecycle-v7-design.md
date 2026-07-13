# Proforma → Invoice Lifecycle Redesign (v7) — Design

- **Date:** 2026-07-13 (rev. 2 — six design gaps closed after internal adversarial pass)
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
| D6 | Conversion and issuance are collapsed (the final invoice is born `PENDING` + immutable + numbered + dated in one step), and fiscal registration is an optional afterthought with no owned state | `InvoiceService.php:398,405` |
| D7 | Conversion does not require the proforma to be frozen or paid; any `serie=PROFORMA` row converts | `InvoiceService.php:348` |
| D8 | `Invoice` immutability does not protect `InvoiceItem`: no update/delete guard exists on the line model — lines of an issued invoice can be edited or deleted | `src/Models/InvoiceItem.php` (no guard) |
| D9 | Contradictory cardinality: `converted_invoice_id` (scalar, 1:1) coexists with `proforma_id` + `convertedInvoices()` (1:N); the database enforces neither | `Invoice.php:79,397`, migration `2024_12_01_000003:45` |
| D10 | `service_date` is populated by no service — nullable, only the factory writes it (randomly); the operation-date row (AID-442) fires only by accident | grep across `src/` |

A green build (26 numbering/tax/drift tests pass) does not cover the conversion contract. These defects are the **qualified, documented imperative** that `STABILITY.md` requires for a breaking release.

## 2. Domain ownership and boundary rules

Larabill is the invoicing engine and the **sole owner** of the proforma domain:

- **Larabill owns:** proforma lifecycle, freezing of lines and amounts, conversion to invoice, fiscal numbering, determination and persistence of the tax snapshot, the traceable proforma→invoice link, fiscal immutability, and the rules around operation date, advance payment and issuance.
- **Consumers own:** when to invoke those operations, their UX, and their commercial domain (acceptance, payment gateways, orders).
- **Consumers may NOT:** clone lines, recalculate or copy taxes, assign fiscal numbers, or implement an alternative conversion. A consumer-side port is legitimate only when it adapts a larabill primitive; if it contains the fiscal algorithm because larabill does not offer it, it is duplicated domain under another name.

**Operative blocking rule:** if a consumer needs to write directly into `invoices`, `invoice_items`, snapshots, totals, series or conversion links, the consumer work is BLOCKED and a capability-gap ticket is opened in larabill first. No local fallback is admitted.

Two questions must never be conflated: domain authority belongs to larabill; real consumer usage only informs SemVer/migration risk (§12 is the only section where consumers appear).

## 3. Settled design decisions (the constitution of this redesign)

1. Larabill owns the full proforma and conversion domain (§2).
2. AID-444 is not implemented as specified; it is superseded by this design (§13).
3. A frozen proforma freezes its **commercial contract**; what is contractually binding depends on the persisted price semantics, with storage semantics fixed in this design, not deferred (§4.3).
4. No tax computation anywhere in the package consults the live catalog outside the dated resolver — conversion, direct invoice creation and provisional proforma estimates included (§5.3, §6.1).
5. The tax snapshot is fixed by a **materialized accrual determination**: larabill determines the accrual from registered economic facts and the frozen contractual nature — the consumer never delivers a resolved accrual (§5).
6. Cardinality is **strict 1:1**: a CONVERTED proforma originates exactly one **issued** invoice; CANCELLED and SUPERSEDED proformas originate zero. Uniqueness of the active link is database-enforced (§7.1); the state machine guarantees "exactly one issued" (§4.1, §6). Future 1:N (partial advances) is a safe expansion — own ticket, out of scope here.
7. `service_date` represents a **fact**, never a configurable preference. `service_date == invoice_date` can only result from both real dates coinciding. No global boolean exists.
8. Conversion, issuance and fiscal registration are **distinct transitions** on distinct state axes (§6).
9. Conversion is atomic, locked and idempotent, backed by database constraints.
10. The invoice keeps a verifiable canonical link to its proforma (`proforma_id`); `converted_invoice_id` is deprecated with an exact-equivalence dual-write during v7 and removal in v8.
11. The target fiscal series is resolved inside larabill (`InvoiceSeriesResolver`) and can be indicated per operation.
12. This redesign is the **evolution of a public API**, not greenfield. Every schema/data change ships its upgrade program in the same PR (AID-398).
13. New operations take **typed commands/DTOs**, not a widened ambiguous `$options` array. No `legacy_recalculate=true` escape hatch exists.
14. The **contractual nature** of each operation (one-off service, successive tract, goods delivery, advance scheme) is explicit frozen data provided by the consumer; larabill maps nature + facts to the accrual rule (§5.1).
15. Every PR in the epic leaves the runtime **coherent with the new schema** (transitional wiring where the final implementation lands later) — "each PR green" is necessary but not sufficient (§9).
16. Consumer references appear only in the impact/migration section (§12).

## 4. Proforma documental lifecycle

### 4.1 `ProformaStatus` — a separate enum and column

Proforma states do NOT enter `InvoiceStatus` (which already mixes document, delivery, collection and process axes). A new `ProformaStatus` enum backs a new nullable `invoices.proforma_status` column, canonical for rows with `serie = PROFORMA`:

```text
DRAFT ──freeze()──▶ FROZEN ──convert()──▶ CONVERTING ──(linked invoice ISSUED)──▶ CONVERTED  (terminal)
  │                    │  ▲                    │
  │                    │  └──(prepared invoice cancelled)──┘
  │                    ├──supersede()──▶ SUPERSEDED  (terminal)
  │                    └──cancel()─────▶ CANCELLED   (terminal)
  └──cancel()──▶ CANCELLED (terminal)
```

- **DRAFT:** editable. Not convertible — `convert()` on a DRAFT fails loud (closes D7). Its identity snapshots are **provisional** (§4.4).
- **FROZEN:** commercial content immutable (§4.3). The only state from which conversion is legal.
- **CONVERTING** (non-terminal): a PREPARED invoice exists and is linked. This closes the dead-end where a cancelled preparation would strand a terminal proforma: while CONVERTING, the proforma cannot be superseded or cancelled directly — the only exits are the linked invoice being issued (→ CONVERTED) or the prepared invoice being cancelled (→ back to FROZEN, §6.4).
- **CONVERTED:** terminal. Means the linked invoice was **issued** — a fiscal document exists. Stamped by `issue()` in the same transaction (§6.3).
- **SUPERSEDED:** terminal; neither editable nor convertible. Design inference from the AEAT traceability requirement (proformas must be preserved and linked to definitive invoices), not a literal prescription of the norm.
- **CANCELLED:** terminal. A frozen proforma may be abandoned without a successor.

**Guards:**

- Once an accrual determination is **materialized** (§5.4), the proforma can no longer be cancelled or superseded to make the obligation disappear: after accrual, an invoice must be issued. A defective determination discovered **before** any issued invoice consumed it is corrected by an audited **re-determination** (§5.4), never by erasing the obligation; corrections after an issued invoice belong to the rectificative domain (typed handoff, §5.6).
- `supersede()` creates/links the successor and closes the predecessor **atomically** in one transaction.
- State transitions are executed and validated by larabill; consumers only request them.

`InvoiceStatus` remains in v7 for compatibility but stops being authority for proforma document state. No general read-compatibility is promised: `FROZEN`, `CONVERTING` and `SUPERSEDED` have no legacy case, so any legacy projection is documented as lossy. Larabill never writes false legacy values (e.g. `DRAFT` for a `FROZEN` proforma) to keep old code running.

### 4.2 Supersession — a single FK

The successor carries `supersedes_proforma_id` (self-referencing FK). The inverse relation is a query from the predecessor — no second physical pointer (avoids reproducing the D9 contradiction).

- `unique(supersedes_proforma_id)` — only one successor may exist.
- Self-reference forbidden; cycle prevention enforced in the service; both sides must be proformas.

### 4.3 What FROZEN freezes — price semantics with fixed storage

A new `price_tax_mode` value (`tax_exclusive` | `tax_inclusive`) is persisted **per line** as part of the frozen contract. Storage semantics are fixed here so two implementers cannot produce incompatible schemas:

- **`unit_price` never changes meaning:** it remains the net unit price in every mode. For `tax_inclusive` lines it is derived/informative (recomputed from the final base at issuance with the documented rounding); for `tax_exclusive` lines it is contractual input, as today.
- **The binding amount is the LINE TOTAL, not the unitary price:** for `tax_exclusive` the binding amount is the line's net base (`taxable_amount`); for `tax_inclusive` it is the line's gross total (`total_amount`). Binding at line level avoids unit×quantity residue ambiguity.
- **Redistribution rule (`tax_inclusive` under a rate change):** `base = round(gross / (1 + rate), 2, HalfUp)`; `quota = gross − base` — the cent residual is absorbed by the quota, per line, deterministically. Each inclusive line binds its own gross total, so no cross-line residual distribution exists.
- **`tax_exclusive` under a rate change:** the net base is preserved; quota and total change. The consumer's receivable/refund delta is commercial follow-up, outside larabill's scope; larabill documents the truth.

It is impossible to promise base, quota and total simultaneously invariant under a rate change; the persisted mode declares which amount is the contract. Historical backfill is `tax_exclusive` — this makes explicit the contract `base_price` has always implicitly carried, it does not introduce a new preference.

**`taxes_applied` disambiguation:** its semantics are fixed by document type — on **proforma** lines it is a **provisional estimate** (including all legacy proforma lines, declared provisional by definition); on **invoice** lines it is the **definitive copy of a tax determination**, with provenance made explicit by a `tax_determination_id` reference on the invoice line (§5.4). PHPDoc and `docs/api-surface.md` stop calling it universally an "immutable tax snapshot".

The frozen commercial line is never mutated to turn an estimate into fiscal truth: the definitive truth lives in the determination records (§5.4).

### 4.4 Identity snapshots — provisional at DRAFT, frozen at freeze()

A draft can live for months; the issuer/customer identity valid at creation may not be the one valid at freezing or accrual. Therefore:

- Snapshots generated at DRAFT creation are **provisional**.
- `freeze()` re-resolves issuer and customer identity and freezes them for the proforma (document identity).
- At accrual/issuance, larabill validates identity compatibility against the **identity change policy matrix** (§5.6).
- The final invoice carries its **own** fiscal snapshot; it does not blindly copy the proforma's identity when a legally relevant change exists. The original proforma stays intact as the commercial origin.

## 5. Tax resolution — economic facts, accrual, epochs, resolver, determinations

### 5.1 Economic facts (input) vs fiscal judgment (larabill's)

Consumers register **economic facts** through an explicit, audited larabill operation (append-only table; indicative name `billing_economic_facts`): payment received (date, amount, currency), service delivered (date), goods delivered (date), chargeability reached (date, for successive-tract contracts). Each fact carries actor, source and timestamp.

**The contractual nature is frozen data, not a fact-time choice.** Choosing to register "chargeability" would otherwise smuggle the successive-tract classification — a fiscal judgment — into the consumer's hands. Therefore each proforma line carries an explicit **`operation_nature`** (one-off service | successive tract | goods delivery | advance scheme; regional strategies may extend the set), provided by the consumer as contractual data and frozen at `freeze()`. Larabill validates that registered facts are consistent with the frozen nature (a chargeability fact against a one-off line is rejected loud) and maps nature + facts to the accrual rule (art. 75 LIVA semantics for the Spanish region; region-pluggable like the rest of the package): advance payment → accrual at payment date; one-off delivery → accrual at delivery; successive tract → accrual at the chargeability date. When the classification requires human judgment, larabill accepts an explicit audited decision (§5.5), never an opaque date or rate.

**Fact correction (append-only needs a correction mechanism):**

- Before accrual materialization: a replacement fact carrying `supersedes_fact_id` substitutes the erroneous one without deleting history; the superseded fact is excluded from determination input.
- After accrual materialization: facts are never rewritten; corrections route to re-determination (pre-issuance, §5.4) or the rectificative domain (post-issuance, §5.6).

**1:1 guards while partial advances are out of scope:**

- A payment fact whose amount does not satisfy the line-level binding totals of the whole proforma (full payment under the supported 1:1 contract) is **rejected explicitly** with a typed exception naming the unsupported partial-advance case and its future ticket.
- Fact amounts carry an explicit currency and must match the proforma's currency.

### 5.2 Catalog epochs — the provable interval

Timestamp inference (`MAX(updated_at)`) is REJECTED as proof: physical deletes destroy evidence, the pivot has no history, query-builder/SQL/imports may bypass timestamps, and code/strategy/rounding changes never appear in those tables. It would prove informatic catalog stability at best, never juridical validity.

Instead, larabill maintains **explicit catalog epochs** (indicative name `tax_catalog_epochs`):

- **Fields:** revision identifier, `observed_from`, hash of the complete rule set (rates, groups, group↔rate relations, special conditions), resolver algorithm version, `closed_at`, integrity state (`intact` | `compromised`).
- **Governed closure:** every catalog mutation performed through larabill closes the active epoch **within the same transactional boundary** as the mutation and opens the next one. A new resolver/rounding code version also opens an epoch.
- **External writes:** prohibited by contract. If the resolver detects a hash mismatch not produced by a governed mutation, it marks the epoch **`compromised`** — it does NOT silently open a new epoch as if the real change date were known. Determinations already emitted under a compromised epoch are flagged for review in an operator-visible report; resolution over a compromised epoch fails loud (override path available).
- **Race closure (resolution ↔ mutation):** the resolver pins the exact revision + hash it used, re-validates the revision is still active at determination write time (optimistic revision check inside the write transaction), and the determination records that revision/hash. A governed mutation racing a resolution loses or wins atomically — never both.
- **Claim it supports:** if the accrual date is on/after the epoch's known start, the epoch is `intact` and still active (or was closed by a governed mutation at a known instant after the date), the state larabill observed did not change during the interval.
- **Honest limits (documented):** the first epoch starts when the mechanism is installed — no retroactive reconstruction; it proves system-observed stability, not that the configuration was juridically correct.

### 5.3 `EffectiveTaxRuleResolver` — the dated resolution contract

All tax resolution flows through one resolver (name indicative). It resolves a **complete rule**, not a nominal rate:

- **Input:** the line's frozen fiscal facts (tax classification, `operation_nature`, group membership, jurisdiction, exemptions/reverse-charge, applicable fiscal profile, `price_tax_mode`) + the accrual date.
- **Output:** an immutable tax snapshot containing at minimum: applied rule with identity/version, base and quota, rounding rule used, requested effective date, resolution moment, epoch revision + hash pinned, **source** (`epoch` | `override`; `temporal` reserved for the future catalog-versioning ticket), and override audit metadata when applicable.
- **Fail-loud contract:** the resolver fails with a specific exception when the requested date falls **outside the interval it can prove** (per §5.2). It never silently reuses the present catalog for a past or future date — even if the rate "probably" did not change, larabill cannot affirm it without history. Conservative by design.
- **Universality:** provisional proforma estimates and direct invoice creation resolve through this same resolver (present epoch); `TaxCalculationService`'s live-catalog reads survive nowhere as a public path (§6.1).

Phase contract (settled): (1) every tax resolution goes through the dated resolver; (2) the initial implementation resolves automatically only the provable present (the active intact epoch); (3) dates outside it fail explicitly; (4) overrides require an audited decision, never silent; (5) the resulting snapshot is materialized at the accrual determination; (6) later conversion copies the determination, never re-resolves; (7) full catalog temporality is designed in its own ticket; (8) that ticket enables automatic historical resolution without changing callers; (9) automatic late registration is NOT declared supported until then.

**Operational gate (measured, not assumed):** override frequency is metered from day one. If late fact registration crossing epoch boundaries turns out frequent, the catalog-versioning ticket ascends to prerequisite. No data exists today to claim it is.

### 5.4 Tax determinations — separate immutable records, correctable pre-issuance

The accrual materializes a **tax determination**: a separate, immutable record set (indicative name `tax_determinations`) per proforma line, produced by the resolver at the accrual date. The frozen commercial line is not touched. At conversion, the invoice copies the determination into `InvoiceItem.taxes_applied` (and derived totals) and stores `tax_determination_id` on the line for provenance.

- **Re-determination (pre-issuance correction):** determinations are immutable but supersede-able. While NO issued invoice has consumed a determination, an audited re-determination (carrying `supersedes_determination_id`, actor, reason) replaces it — the accrual obligation never disappears (§4.1 guard), only its resolution is corrected. A PREPARED invoice whose determination was superseded cannot be issued (validation in `issue()` step 2) — it must be cancelled and re-converted.
- **Post-issuance:** a consumed determination is untouchable; corrections belong to the rectificative domain (§5.6).

### 5.5 Override — an explicit domain operation

Never `rate => 2100` in an options array. The flow is:

1. Larabill recognizes it lacks history for the requested date (or the epoch is compromised) → specific exception.
2. An authorized human adopts the fiscal decision.
3. Larabill receives it through an explicit operation that validates its shape and persists actor, reason, documentary source and timestamp.
4. The resulting snapshot records `source = override` with the audit metadata; the invoice keeps it immutably.

### 5.6 Identity change policy matrix + rectificative handoff

`FiscalChangeDetector` (post-AID-328: snapshot-based) provides the field-level diff; this matrix — not the legacy `force`/`on_changes` options, which are **retired** — defines the outcome. No generic force bypass survives.

| Change detected between freeze and conversion/issuance | Outcome |
|---|---|
| Issuer legal identity (`tax_id`, legal entity) | **BLOCK.** A proforma cannot convert under another issuing entity. Path: supersede the proforma under the new issuer config. |
| Issuer name/address (non-critical) | Proceed; the invoice snapshot carries the current identity; warning recorded on the conversion audit. |
| Customer `tax_id` | **BLOCK by default.** Paths: audited decision (§5.5-style, persisted actor/reason) confirming same legal person (e.g. NIF correction), or supersede. |
| Customer country / ROI / EU-VAT registration / exemption status | Affects determination validity. Accrual NOT yet materialized → re-resolution required with audited acknowledgment. Accrual materialized, pre-issuance → audited re-determination (§5.4). Post-issuance → rectificative domain. |
| Customer name/address (non-critical) | Proceed; current snapshot; warning recorded. |

**Rectificative handoff (fixed in this spec, implemented in its own ticket):** any attempt to correct facts, determinations or identity **after an issued invoice consumed them** throws a dedicated typed exception (indicative name `PostIssuanceCorrectionException`) that names the rectificative path and carries the issued invoice reference. The exception is part of the v7 public surface; the rectificative flow itself is out of scope (§11).

## 6. Invoice documental lifecycle — conversion, issuance, registration

### 6.1 `InvoiceDocumentStatus` — separate document axis for invoices

`PREPARED` does not enter `InvoiceStatus` (it would repeat the axis-mixing just corrected for proformas). A new `InvoiceDocumentStatus` enum backs a new column:

```text
DRAFT ──▶ PREPARED ──issue()──▶ ISSUED   (terminal for this axis)
  │           │
  └───────────┴──▶ CANCELLED   (only BEFORE issuance)
```

- **CANCELLED** is only reachable before issuance. An ISSUED invoice is never cancelled: it is rectified (rectificative domain).
- **Fiscal registration is a separate axis** — `FiscalRegistrationStatus`: `NOT_REQUIRED` | `PENDING` | `REGISTERED` | `FAILED` | `LEGACY_UNTRACKED`, driven by the outbox (§6.5). "Distinct transitions" never means an issued invoice may indefinitely lack its mandatory registration: `PENDING`/`FAILED` are visible, monitorable alarm states.
- `SENT`, `PAID`, `OVERDUE` remain delivery/collection legacy in `InvoiceStatus` until consciously separated (out of scope). `InvoiceStatus` stays in v7 for compatibility but stops being authority for the document cycle.
- **`createInvoice()` keeps its external call shape but is REIMPLEMENTED over the new primitives** — internally: prepare → determination through the dated resolver (present epoch; operation facts/nature accepted as input, defaulting to issuance-date accrual for immediate direct invoicing, explicitly recorded) → issue, atomically. No live-catalog read, no resolver bypass, no unresolved `service_date` survives behind the old signature. The constitution admits no public exception (§3.4).

### 6.2 Conversion (rewritten `convertProformaToInvoice`)

Preconditions: proforma is `FROZEN`; its accrual determination is materialized and not superseded; all lines share **one** header-level accrual date (§6.6); identity matrix (§5.6) allows proceeding; no prior active conversion (idempotent: while CONVERTING or CONVERTED, the call returns the linked invoice).

Atomic sequence (one DB transaction, `lockForUpdate()` on the proforma):

1. Lock the proforma; re-validate state and idempotency under the lock (closes D1).
2. Validate identity compatibility (§5.6) via `FiscalChangeDetector` against persisted snapshots.
3. Create the final invoice as **PREPARED**: complete 1:1 copy of all line columns (`item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata`, `operation_nature`, `price_tax_mode`) with `taxes_applied`, amounts and `tax_determination_id` taken from the **tax determination** (§5.4) — never from the live catalog (closes D3/D4; the conversion path holds no reference to catalog models and receives a resolver double that throws if invoked, §11).
4. Persist the selected target series (`prefix`) resolved through `InvoiceSeriesResolver`, indicated per operation (closes D5). The correlative is NOT consumed yet.
5. Derive `service_date` from the accrual determination (advance → payment date; delivery → delivery date; successive tract → chargeability date) — closes D10 without any boolean.
6. Write `invoices.proforma_id` on the final invoice (canonical link, closes D2) and dual-write `converted_invoice_id` on the proforma (deprecated mirror).
7. Transition the proforma `FROZEN → CONVERTING`.

A PREPARED invoice knows its fiscal type, target series, lines, snapshots and `service_date`; it does NOT yet have `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`. It is closed to ordinary modifications even though not fiscally issued.

### 6.3 Issuance — `issue()`

Atomic local sequence (no external API call inside the DB transaction):

1. Lock the PREPARED invoice (and its proforma when conversion-born).
2. Validate the tax determination is complete **and not superseded** (§5.4).
3. Resolve the series (already persisted; re-validated).
4. Assign the correlative number atomically (`InvoiceNumberingService`, sole owner per AID-390). `fiscal_year` derives from the **real issuance date**, not preparation.
5. Set `invoice_date` and `issued_at`.
6. Mark content fiscally immutable (header + lines).
7. Create the durable **outbox** record for mandatory registration (when compliance requires it) and set `fiscal_registration_status = PENDING` (else `NOT_REQUIRED`, a positive compliance decision).
8. Transition the invoice to `ISSUED`; when conversion-born, stamp the proforma `CONVERTING → CONVERTED` in the same transaction.

Failure taxonomy: transient/infrastructure errors leave the invoice PREPARED (retryable, nothing consumed — the transaction rolled back); domain-invalid discoveries (superseded determination, identity block) are typed exceptions whose remedy is `cancelPreparedInvoice()` + correction + re-conversion (§6.4).

### 6.4 Cancelling a PREPARED conversion invoice — releasing the link

`cancelPreparedInvoice()` (auditable operation) closes the dead-end between CONVERTING and issuance:

1. Lock invoice + proforma; require `PREPARED` (never ISSUED) and conversion-born linkage.
2. Transition the invoice to `CANCELLED`, **retaining** its `proforma_id` as documentary trace of the aborted preparation (a cancelled PREPARED row never carried a fiscal number — it is an aborted preparation, preserved for audit).
3. Transition the proforma `CONVERTING → FROZEN` and clear the deprecated `converted_invoice_id` mirror, atomically. The persisted cancellation audit (actor, reason, timestamp) records the released link.
4. The active-link uniqueness (§7.1) counts only non-cancelled invoices, so re-conversion is possible; the still-valid determination is reused deterministically unless it was superseded.

### 6.5 `convertAndIssue()` — convenience with defined atomicity

One call, two distinct internal transitions:

- Failure **before** number assignment → nothing converted (full rollback; proforma stays FROZEN, no counter consumed).
- Failure **after** issuance → the invoice stays ISSUED with registration `PENDING`/`FAILED`; issuance is never reverted.
- Two invocations return the same invoice (idempotent), never a second one.

**Registration state authority:** the invoice's `fiscal_registration_status` column is **canonical business state**; the outbox row carries delivery mechanics only (attempt count, last error, next retry, idempotency key). Every coordinated update is transactional; the outbox never models `REGISTERED`/`FAILED` as a second authority — the worker that observes the external outcome writes the invoice column and the outbox mechanics in one transaction.

### 6.6 One accrual date per invoice (v7 invariant)

`service_date` is a single header column, and a proforma may mix lines with different delivery dates, periods, natures or chargeabilities. v7 imposes the invariant:

> A convertible proforma groups only lines whose materialized accrual shares one header-level documentary date.

When determinations yield differing accrual dates across lines, **conversion is rejected** with a typed exception — larabill never picks an arbitrary date (no min/max/first). Per-line accrual representation is deferred to its own ticket (§12). For successive tract the accrual date is the **chargeability date**; `service_date_from/to` keeps the service period (presentation/data already shipped in v6.2–v6.3).

## 7. Schema and migration program

Every migration ships with its `.php.stub`, `$migrationOrder` entry, manifest entry (`in_base: false`), install-test count bump, and its data program + upgrade-path test in the **same PR** (AID-398, AID-412 gates).

**Global preflight before any v7 mutation:** the FIRST migration of the v7 chain is a read-only preflight that validates the data assumptions of the WHOLE program (§7.1 detections) and aborts loudly on ambiguity — before any other v7 migration mutates anything. MySQL DDL is non-transactional, so per-migration validation would let earlier migrations apply before a later one fails. The standard ritual (`composer update` + `larabill:install` + `migrate`) stays sufficient; no extra manual command exists.

### 7.1 `invoices`

- **`proforma_status`** — nullable tinyint, `ProformaStatus` cast. Coherence constraint where the engine supports it: `serie = PROFORMA ⇔ proforma_status IS NOT NULL` (CHECK on MySQL 8; documented invariant + model guard elsewhere).
- **Backfill precedence (conservative, preflight-gated):** (1) valid conversion link → `CONVERTED` (legacy converted proformas map to the terminal state directly: their invoice was issued under the old collapsed model); (2) cancelled → `CANCELLED`; (3) `DRAFT` and mutable → `DRAFT`; (4) immutable or any non-editable legacy state (`PAID`, `SENT`, `OVERDUE`, `PENDING`) → `FROZEN` (never auto-`DRAFT` — that would reopen documents); (5) inconsistencies → **fail loud in preflight, never a silent default**, with an upgrade report.
- **Preflight detections (abort without modifying data):** `status=CONVERTED` without linked invoice; `converted_invoice_id` pointing to a non-fiscal-invoice row; two proformas pointing at the same invoice; an invoice whose `proforma_id` contradicts the inverse link.
- **`proforma_id` becomes canonical:** backfill from existing `converted_invoice_id` (inverse direction) — preflight verifies the target exists, has no other proforma, no two proformas share a target, and no existing `proforma_id` is contradicted. Never overwrite an existing relation to "make the migration pass". **Active-link uniqueness:** unique over `proforma_id` restricted to non-cancelled documents — partial unique index on SQLite, functional key part on MySQL 8 (`proforma_id`, expression collapsing non-cancelled to a constant). Guarantees "at most one non-cancelled invoice per proforma" at the engine, while cancelled aborted preparations retain their trace (§6.4). FK changes `nullOnDelete` → `restrict` (documentary preservation).
- **`converted_invoice_id`:** deprecated; exact-equivalence dual-write during v7 (cleared when the preparation is cancelled, §6.4); removed in v8 (fulfils the deprecation cycle).
- **`supersedes_proforma_id`** — nullable self-FK, `restrict`, `unique` (one successor max); no self-reference; cycle prevention in the service.
- **`invoice_document_status`** — new column, `InvoiceDocumentStatus` cast. **Legacy backfill: all existing fiscal invoices → `ISSUED`** (they all carry number, `invoice_date`, `issued_at` because those were mandatory). Classifying them PREPARED would allow altering documents that already consumed fiscal numbering. The upgrade report flags "numbered drafts" (legacy `InvoiceStatus::DRAFT` with a fiscal number) explicitly.
- **`fiscal_registration_status`** — new column. Backfill: rows with existing verifactu state map from it; all other legacy fiscal invoices → **`LEGACY_UNTRACKED`** (absence of registration does not prove it was not mandatory). `NOT_REQUIRED` is reserved for a positive compliance decision going forward.
- **Nullable fiscal fields for PREPARED:** `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at` become nullable. **`prefix` stays NOT NULL** — it persists the selected target series before issuance. Unique indexes (`fiscal_number`; `prefix, serie, series_number, fiscal_year`) tolerate NULLs per engine semantics.

### 7.2 `invoice_items`

- **`price_tax_mode`** — enum-backed, backfill `tax_exclusive` (explicit historical contract, §4.3).
- **`operation_nature`** — enum-backed contractual nature (§5.1). Backfill: existing lines → derived from `item_type` where unambiguous (goods → goods delivery; service → one-off service), reported in the upgrade report; never guessed as successive tract.
- **`tax_determination_id`** — nullable FK on invoice lines (provenance of the definitive `taxes_applied`, §5.4).
- **Immutability guard, with its limit declared:** model events (`saving`, `deleting`) reject changes when the header state forbids them, and every package public operation respects the guard. Bulk `query()->update()/delete()`, direct SQL and external writes are **outside the contract** — documented explicitly (DB-level enforcement is a possible future hardening, not promised here).

### 7.3 New tables (names indicative, fixed at plan time)

- **`billing_economic_facts`** — append-only: proforma FK, fact type (payment/delivery/chargeability), fact date, amount + currency, actor, source, `supersedes_fact_id`, metadata, timestamps.
- **`tax_determinations`** — immutable rows: proforma line FK, applied rule identity/version, base, quota, rounding rule, effective date requested, resolution moment, epoch revision + hash pinned, source (`epoch`/`override`), override audit (actor, reason, documentary source, timestamp), `supersedes_determination_id`.
- **`tax_catalog_epochs`** — revision id, `observed_from`, rule-set hash, resolver algorithm version, `closed_at`, integrity state (`intact`/`compromised`).
- **`fiscal_registration_outbox`** — invoice FK, attempt count, last error, next retry, idempotency key (delivery mechanics only; business state lives on the invoice, §6.5).

## 8. Public surface and SemVer

- **v7.0.0 major.** Qualified imperative: the D1–D10 forensic table (§1) — fiscal-correctness and integrity defects, not aesthetics. Complies with `STABILITY.md`.
- **`convertProformaToInvoice()` is rewritten in place** with the new contract (preconditions FROZEN + materialized accrual; PREPARED output). The defective semantics are NOT preserved behind a deprecation: keeping alive a path that recalculates against the live catalog, loses tax snapshots, races and breaks the canonical link would be worse than the breaking change. No `legacy_recalculate=true` option exists. Conditions honored: `UPGRADE-7.0.md` explains every new precondition; the CHANGELOG declares old and new behavior explicitly in bold; consumers can mechanically identify call sites needing freeze/facts/determination (each new precondition fails with a specific typed exception naming the missing step).
- **`createInvoice()` keeps its signature, loses its internals** (§6.1) — reimplemented over prepare/determine/issue; behavior-compatible for the direct-invoicing case, documented in `UPGRADE-7.0.md`.
- **New `@api` operations** (typed commands/DTOs, no `$options` widening): `freezeProforma()`, `supersedeProforma()`, `cancelProforma()`, `cancelPreparedInvoice()`, economic-fact registration, the audited override operation, the audited re-determination operation, `issue()`, `convertAndIssue()`.
- **New enums:** `ProformaStatus`, `InvoiceDocumentStatus`, `FiscalRegistrationStatus`, `OperationNature`, `PriceTaxMode`. `InvoiceStatus` unchanged (kept for compatibility; authority reduced as per §4.1/§6.1). Legacy `force`/`on_changes` conversion options retired (§5.6).
- **Contract gates extended:** the AID-412 model-surface snapshots regenerate via `bin/sync-contract-snapshots` with their CHANGELOG gate, **plus a new snapshot gate covering the public signatures of services, commands/DTOs and exceptions** (`InvoiceService` and the new operations included) — model snapshots alone do not cover the surface this redesign changes. `@api`/`@internal` taxonomy extended to every new class.

## 9. Delivery phases (ordered PRs inside the v7 epic)

Foundations first — `freeze()` cannot bind amounts correctly before `price_tax_mode` exists. **Every PR leaves the runtime coherent with the new schema (decision 15): transitional wiring ships where the final implementation lands later.**

1. **PR-1 — Schema foundations + transitional coherence:** global preflight migration; `ProformaStatus`, `InvoiceDocumentStatus`, `FiscalRegistrationStatus`, `price_tax_mode`, `operation_nature`, canonical links (`proforma_id` active-unique + backfill, `supersedes_proforma_id`), nullable fiscal fields, all backfills; `InvoiceItem` guard. **Transitional wiring:** creating hooks stamp the new state columns coherently under current behavior (new invoices → `ISSUED` at creation, new proformas → `DRAFT`, `make_immutable` → `FROZEN`); the OLD conversion gains `lockForUpdate()` and writes `proforma_id` (safe, additive fixes of D1/D2 without semantic change). `UPGRADE-7.0.md` is born here.
2. **PR-2 — Documental lifecycle + tax truth:** freeze/supersede/cancel with guards, economic facts (+ corrections + 1:1 guards), epochs, resolver, determinations (+ re-determination), audited override, identity policy matrix. Old conversion untouched beyond PR-1 wiring; `freeze()` exists but is not yet a conversion precondition (coherent: optional until PR-3).
3. **PR-3 — Conversion + issuance:** rewritten conversion (PREPARED, CONVERTING), `issue()`, `cancelPreparedInvoice()`, outbox + registration axis, `convertAndIssue()`, `createInvoice()` reimplementation, concurrency hardening. The FROZEN/determination preconditions activate here, atomically with the rewrite.
4. **PR-4 — Release closure:** CHANGELOG promotion, manifest re-stamp, `UPGRADE-7.0.md` consolidation, tag via `bin/tag-release`.

**Documentation travels in every PR, not at the end:** each PR carries its `[Unreleased]` CHANGELOG entry, its upgrade path, regenerated snapshots when surface changes, timestamped migration + stub + `$migrationOrder`, and its consistency/manifest tests. PR-4 only consolidates; it never reconstructs justification a posteriori.

## 10. Testing contract

The conversion contract moves from 0 tests to a mandatory suite:

- **State machines:** every legal transition and every guard for `ProformaStatus` (including `CONVERTING → FROZEN` on cancelled preparation, and re-conversion reusing the still-valid determination) and `InvoiceDocumentStatus`; cancel/supersede after materialized accrual → typed exception; `CANCELLED` unreachable from `ISSUED`; proforma in CONVERTING cannot supersede/cancel directly.
- **Backfills (upgrade-path, MySQL):** ambiguous data → global preflight fails **before any v7 mutation**; cancelled+immutable proforma ends `CANCELLED`, not `FROZEN`; `PAID`/`SENT`/`OVERDUE`/`PENDING` proformas end `FROZEN`, never `DRAFT`; legacy numbered invoices end `ISSUED`; legacy registration state ends `LEGACY_UNTRACKED` absent a verifactu record; link backfill never overwrites an existing `proforma_id`; fresh install AND real upgrade-from-v6 (AID-412 harness), not only isolated backfills.
- **Conversion:** column-by-column copy exactness (dataset over every `invoice_items` column); the conversion path receives a **resolver/catalog double that throws if invoked** (stronger than query-log assertions); idempotency (second call during CONVERTING/CONVERTED returns the same invoice); `proforma_id` written; per-operation series honored; `service_date` derived correctly for delivery, chargeability and advance-payment natures; differing accrual dates across lines → conversion rejected with the typed exception (no arbitrary date picked).
- **Facts and determinations:** fact inconsistent with frozen `operation_nature` → rejected; partial payment under 1:1 → typed rejection; currency mismatch → rejection; fact correction pre-materialization via `supersedes_fact_id` excludes the superseded fact; post-materialization correction → typed handoff exception; re-determination pre-issuance blocks `issue()` of a stale PREPARED invoice; consumed determination untouchable.
- **Identity matrix:** one test per matrix row (§5.6) — issuer identity blocks, customer `tax_id` blocks without audited decision, country/ROI change routes per accrual phase, non-critical changes warn and proceed.
- **Concurrency (fork pattern AID-390, `RUN_CONCURRENCY_IT=1` + MySQL):** two concurrent conversions → exactly one CONVERTING link, loser returns the winner's invoice; two concurrent `issue()` → a single number consumed; governed catalog mutation racing a resolution → determination pins a consistent revision (optimistic check). The sensitivity check against the pre-fix implementation (restoring `refresh()`) is documented as a **one-off mutation test during development**, not an ordinary CI gate.
- **Price semantics:** `tax_exclusive` preserves the contractual base under a rate change; `tax_inclusive` preserves the gross line total and redistributes with the documented rounding (residual to the quota, per line); determination never mutates the frozen line.
- **Resolver/epochs:** in-epoch resolution succeeds; out-of-epoch date → specific exception; override persists full audit and marks `source=override`; governed mutation closes the epoch transactionally; hash mismatch marks the epoch `compromised` and resolution over it fails loud.
- **Outbox:** external registration failure leaves a durable outbox row and visible `FAILED` on the invoice column (single authority); retries idempotent; fiscal number never reverted/reused.
- **Immutability limit:** a test verifies **no package public operation** mutates lines through the bulk/query bypass, and documents that bulk `query()->update()/delete()` and external SQL are outside the guarantee (§7.2) — the boundary is asserted and documented, not celebrated.

## 11. Out of scope — follow-up tickets

- **Full temporal catalog versioning** (§5.2/§5.3 limits). Ascends to prerequisite only if override metrics demand it.
- **Partial advances (1:N):** multiple advance invoices per proforma + final regularization. The active-link unique is a safe expansion point; the partial-payment typed rejection (§5.1) names this ticket.
- **Per-line accrual representation** (multi-date invoices, §6.6).
- **Rectificative domain** (the flow behind `PostIssuanceCorrectionException`, §5.6).
- **Separating delivery/collection axes** (`SENT`/`PAID`/`OVERDUE`) out of `InvoiceStatus`.
- **DB-level line immutability enforcement** (triggers/permissions) beyond the model guard.

## 12. Consumer impact and migration (the only section where consumers appear)

- Consumers currently orchestrating conversion locally (e.g. the Vía 1 flow in `clientes`) are **blocked from extending** that duplication and must migrate to the v7 primitives once released, then delete their local conversion/freeze domain. This is SemVer/migration risk information, not design input.
- Existing OSS consumers of `convertProformaToInvoice()` face new preconditions: `UPGRADE-7.0.md` documents the mechanical mapping (freeze first; register facts; handle the typed exceptions). Old call sites fail loud with actionable messages, never silently change meaning.
- The upgrade ritual is the standard one: `composer update` + idempotent `larabill:install` re-run + `php artisan migrate`, with the global preflight aborting loudly on ambiguous data before any v7 mutation.

## 13. AID-444 disposition

AID-444 is **closed as superseded by design**:

- Its premise ("the conversion flow does not exist, greenfield") is false — the flow exists as public surface since before v1.0 (§1).
- Its central mechanism (`proforma.align_service_date_on_conversion` boolean) models an issuer convenience, not a fiscal fact, and can write a false date. RD 1619/2012 art. 6.1 requires the operation date or the advance-payment date **when it differs from the issuance date**; the accrual belongs to the facts (art. 75 LIVA), and the documentary date represents it — it never creates or displaces it. Selecting a config value cannot move VAT across quarters; the economic fact already determined it.
- The UX warning it mandated is therefore also incorrect and is dropped.
- `service_date` in v7 derives from the materialized accrual determination (§6.2 step 5). The advance-paid-June-30-converted-July-2 case yields `service_date = 2026-06-30` — the truth AID-444's default would have falsified.
- New Linear tickets are cut from this spec (the v7 epic and the §11 follow-ups); AID-442's presentation layer (already shipped in v6.3.0) is untouched and becomes correct automatically once `service_date` carries real facts.
