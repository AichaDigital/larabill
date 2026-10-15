# Spec — Invoice numbering lifecycle: number only at issuance (ADR-016, major 8.0.0)

> Shorthand title: R1's name. Precisely: numbering at issuance for FISCAL documents; proformas keep birth numbering on their own serie (§3.1, §7.2).

- **Status:** DRAFT — pending adversarial gate before any implementation (roadmap step 2 of `odd/tasks/aid-1627-r1-numbering-lifecycle.md`).
- **Refs:** [v7 design §6](../superpowers/specs/2026-07-13-proforma-invoice-lifecycle-v7-design.md) (approved 2026-07-13, never shipped; v7.0.0 cancelled 2026-07-18), AID-1627 (this ticket, In Progress), AID-1609 (origin, openmizan R1), AID-1628 (consumer side: numbered-deletable drafts), AID-1629 (R2 outbox), AID-1631 (R4 gap report), AID-1634 (lara-verifactu typed outcomes), [ADR-014](../ADR-014-settlement-artifacts-and-unconsumed-value.md) (settlement frontier), [ADR-015](../ADR-015-agreement-anatomy-modality-cadence-lifecycle.md) / [its spec](adr-015-agreement-model.md) (agreement model, major 7.0.0 — execution coordination, §15), [import-support](../import-support.md) (AID-895/AID-896).
- **Decision authority:** operator, sessions of 2026-10-12 (FULL §6 revival, not the minimal variant) and 2026-10-13 (historical-import disposition, §3.4).
- **Baseline:** re-anchored from zero this session (2026-10-13) against `main` @ `1916b3c`. Every `path:line` below was verified in this session; the 2026-10-12 scout baseline is confirmed with two corrections recorded in §3.4 (AID-1628 scope) and §1.2 (ADR-015 partial landing).

## 1. Motivation — the defect in the current tree

### 1.1 Numbering burns at birth, not at issuance

`createInvoice()` allocates the correlative **unconditionally, before the row exists**, and stamps the issuance fields from `now()` in the same breath — regardless of the document's state:

- `src/Services/InvoiceService.php:159` — `$number = $this->invoiceNumbering->generateNumber($series, $serie);` inside the creation transaction, before `Invoice::create` (`:161`).
- `:146` — default status `DRAFT`; a caller asking for a draft gets a **numbered** draft.
- `:162-166` — `fiscal_number`, `series_number`, `fiscal_year` from the counter; `invoice_date = now()`, `issued_at = now()` stamped at creation.
- `:165-168` is the **single** `generateNumber()` call site in `src/` (verified by grep; the service declares the method, `InvoiceNumberingService.php:54`, and `InvoiceSeriesControl.php:164` only references it in prose).

Consequences, each independently grounding the qualified imperative (with art. 6.1.a RD 1619/2012 as the legal context — it obliges the invoice number and consecutive numbering within each serie; it does not expressly forbid reserving numbers on unissued documents — plus the openmizan R1 requirement, AID-1609, as the house policy):

1. **Numbered drafts (fiscal types).** Every DRAFT-status FISCAL row consumes a final correlative of its own type's serie. On openmizan (AID-1609 origin) this is the R1 requirement: numbers must appear only on issuance. Proformas are the deliberate exception (§3.1): their serie is not the final correlative.
2. **The deletion hole.** `Invoice` has no `SoftDeletes` (verified) and no deletion path in `src/`, but rows are DB-deletable; nothing guards deleting a numbered row. A deleted numbered draft is an unexplained gap with no audit trail.
3. **False issuance stamps.** `issued_at` carries `DEFAULT CURRENT_TIMESTAMP` (`2024_12_01_000003_create_invoices_table.php:31`, `useCurrent()`) and `invoice_date` is NOT NULL (`:30`): an unissued row cannot exist on the current schema without falsifying both columns.
4. **Conversion issues prematurely.** `convertProformaToInvoice()` returns a numbered, dated, immutable invoice (`InvoiceService.php:607-643`): conversion and issuance are collapsed (v7 §1 defect D6, the one member of the D-list still open).

### 1.2 Context the spec inherits

- **v7 §6 is the approved design** for the documental lifecycle (statuses DRAFT→PREPARED→ISSUED, numbering inside `issue()` for fiscal documents, conversion not consuming the correlative). v7.0.0 was cancelled as a release (2026-07-18); §6 was never implemented. This spec revives §6 against the current tree.
- **ADR-015 is partially landed**, not wholly pending: the cadence domain (`BillingInterval`, AID-1613), the import columns (`is_historical` on invoices, `origin_key` on agreements, `2026_10_09_000008_add_import_support_columns.php`), the settlement key (`invoices.settlement_operation_key`, AID-015 §3.4, UNIQUE — serialises concurrent settlements by collision-and-adoption) and the guarantee flow (`GuaranteeService`, rectificative via `SettlementService`) are live in `src/`. Its remaining execution is major **7.0.0** (agreement model), a separate release (§15).
- **There is no historical-invoice import.** AID-896 is CANCELLED (operator decision, 2026-10-13): WHMCS invoices stay in the source systems; a future read-only query panel is its own ticket. R1 therefore applies **exclusively to native documents** (§3.4).
- **No continuity tooling exists.** The only console commands are `DiagnosePriceOverlapsCommand` and `LarabillInstallCommand`; the R4 gap-detection report (AID-1631) starts from zero and is a sibling, not part of R1.

## 2. Scope — what revives from v7 §6, what does not

**In scope (operator decision 2026-10-12: FULL §6 lifecycle, not the minimal variant):**

- `InvoiceDocumentStatus` document axis (§5) with bidirectional CHECK-backed coherence (§8).
- Numbering only inside `issue()` for FISCAL documents (the PROFORMA serie keeps its birth numbering — §3.1, §7.2); issuance instant coherence (§6).
- Two-phase `createInvoice()` (§7); conversion rewritten to return an unnumbered PREPARED invoice (§9); `cancelPreparedInvoice()` (§10); `convertAndIssue()` (§11); stalled-preparation visibility (§11.3).
- `FiscalSubmissionStatus` documental column and enum, backfilled (§5.3); the outbox/handler mechanics stay in R2 (§2.1).
- Immutability hardening at issuance: header AND lines, closing the `save()` bypass, plus the deletion guard (§6.5).

**Out of scope, with the named owner:**

| Excluded | Owner / ticket |
| -- | -- |
| Proforma documental lifecycle (FROZEN/supersede/adopt, `ProformaStatus`, §4 of v7) | Future ticket; R1 maps the needed states onto legacy `InvoiceStatus` (§5.2) |
| Tax truth: facts, obligations, epochs, determinations, identity matrix (v7 §5) | ADR-015 execution epic (major 7.0.0) and the v7-§5 revival ticket; R1 names the seams (§14) |
| Outbox, `FiscalSubmissionHandler`, typed submission outcomes, `LEGACY_UNKNOWN` reconciliation op | R2 (AID-1629); AID-1634 (lara-verifactu) blocks R3 |
| Gap/continuity detection report | R4 (AID-1631) |
| Numbered-deletable-drafts design on the consumer | AID-1628 — **correction to the 2026-10-12 note:** its subject is the deletion of numbered drafts, not the import frontier |
| Historical invoice import | AID-896 CANCELLED (§3.4) |
| **Retirement of the 7.0 deprecation shims** (`BillingFrequency`, `CancellationType`, `ServiceStatus`, the two `cancel()` convergences, `toLegacyFrequency()`, …) — promised for 8.0 by ADR-015's Enmienda 1 | **Deferred to the next major (9.0.0)** by operator decision (2026-10-13, recorded in `approvals/majors/larabill-8.md`): 8.0.0's coupling message stays scoped to the R1 lifecycle changes; a minor cannot retire surface, so the deferral moves the window to the next major, not to 8.1 — the shims stay live and warning through all of 8.x |
| Catalog epochs, currency column, multi-currency | v7 §5 revival ticket |

### 2.1 The submission axis split (named for the gate)

v7 §6.1 binds `FiscalSubmissionStatus` and §6.7 the outbox into one lifecycle. R2 (AID-1629) owns the outbox and the handler boundary. The split R1 adopts:

- **R1 ships** the `FiscalSubmissionStatus` enum and the nullable column with its bidirectional CHECK (`ISSUED ⇔ fiscal_submission_status NOT NULL`), the backfill (`LEGACY_UNKNOWN` on every legacy fiscal row), and the `issue()` stamp (`PENDING` when Verifactu compliance is configured, else `NOT_REQUIRED` as an auditable config-derived decision).
- **R2 ships** the outbox table, `FiscalSubmissionHandler`, the handler-bound issuance check, the typed outcomes, the resubmission/reconciliation operations (including the `LEGACY_UNKNOWN` exit) — without changing the column semantics R1 fixes.

The alternative (deferring the whole axis to R2) was rejected: the CHECK set and the issuance contract would fork, and R2 would inherit a moving document axis.

## 3. Dispositions that shape the design

### 3.1 Proformas keep birth numbering on their own serie (unchanged)

Proformas consume **their own serie's** correlative at creation (`createProforma()` → `createInvoice(type: proforma)`, `InvoiceService.php:495-500`) and conversion allocates a fresh invoice-serie correlative. R1 leaves proforma birth numbering untouched: proformas do not consume the final correlative, the WHMCS zero-invoice import path (`docs/import-support.md`) depends on it, and proforma lifecycle belongs to the §4 revival. The **conversion** change (§9) is what stops the final correlative from being consumed prematurely.

### 3.2 No active-link generated column

v7 §7.1's `proforma_id` active-uniqueness (generated column + UNIQUE) exists to serialise multiple conversions of one proforma. That race is already closed in the current tree: the proforma row is re-read under `lockForUpdate()` (AID-554, `InvoiceService.php:536`) and the idempotency check keys on `converted_invoice_id` (AID-555 canonical `proforma_id` + mirror, `:543-551`). R1 keeps the mirror and the lock; the generated-column machinery stays with the §4/§5 revival where `CONVERTING`-aware counting becomes load-bearing.

### 3.3 `convertAndIssue()` is the compatibility vehicle

Conversion stops returning an issued invoice (§9) — the breaking change of this major. `convertAndIssue()` (§11) preserves the old call-and-result shape for consumers that want one-shot conversion, as two resumable transactions.

### 3.4 Historical import: out of scope entirely (AID-896 CANCELLED)

Operator decision (2026-10-13): **historical invoices are not imported into larabill.** They remain in Contacastris/WHMCS; a future read-only consultation panel is a separate ticket. Concretely:

- ADR-016 adopts **none** of the import-numbering options (original-number seam / new-number / deferred): no historical invoice is created through `createInvoice()` or `issue()`, consumes no correlative, and touches no seed.
- R1 applies exclusively to native documents. `is_historical` survives as the provenance marker it is today (`docs/import-support.md`); the only documents the import creates are the legacy zero-invoice PROFORMA traceability rows, whose birth numbering on the proforma serie is untouched (§3.1).
- Rationale on record: converting a historical record into a larabill invoice would be a **false emission** even with the original number preserved; the number, the issuance instant and the fiscal context belong to the system that issued them.

## 4. Re-wiring inventory — every route that births a numbered row today

| Route | Call site | Today | After R1 |
| -- | -- | -- | -- |
| Direct emission | `RecurringBillingService.php:476` (`createInvoiceForService`), v6.10 contract "born sealed" | Numbered + immutable at birth | `createInvoice()` runs two-phase internally (§7): result-compatible, still born ISSUED. The serie-type branch (§7.2) is fiscal-only; the proforma path bypasses it entirely |
| Settlement artifacts (ADR-014/AID-971) | `SettlementService.php:104, :221` (rectificative + refund line; `GuaranteeService` settles through it, phase-1 issuance already an independent transaction, `GuaranteeService.php:169`) | Numbered at birth | Same two-phase; adoption flow extended to resume issuance on a durable unnumbered draft (§7.3) |
| Conversion | `InvoiceService.php:628` | Numbered PREPARED-equivalent returned | Returns unnumbered PREPARED (§9) |
| Proforma creation | `InvoiceService.php:500` (`createProforma`) | Numbered on PRO serie | **Unchanged** (§3.1) |
| Import (zero-invoice proformas) | consumer-side via `createInvoice(type: proforma)` | Numbered on legacy-configured PRO serie | **Unchanged** (§3.1, §3.4) |

## 5. Document status model

### 5.1 New enum `InvoiceDocumentStatus`

```text
DRAFT ──▶ PREPARED ──issue()──▶ ISSUED   (terminal)
  │           │
  └───────────┴──▶ CANCELLED          (only before issuance; §10 for conversion-born)
```

- `DRAFT`: direct-invoice birth state; editable; no number.
- `PREPARED`: conversion-born; content copied verbatim from the proforma (the AID-555/556/559/576/929 hardening retained); no number; not editable as fiscal content.
- `ISSUED`: terminal; numbered; fiscally immutable (§6.5).
- `CANCELLED`: terminal; never numbered; reachable from DRAFT and PREPARED only.

The enum name, cases and `label()` surface follow the house convention (`InvoiceStatus` precedent); final naming is confirmed at TDD time under the existing contract-snapshot gate.

### 5.2 Legacy `InvoiceStatus` mapping (the two axes stay separate)

`InvoiceStatus` keeps existing as the collection/fossil axis; the document axis never merges into it.

- **Fiscal rows:** `issue()` leaves the collection axis at `DRAFT` (unpaid/unset); the consumer moves `SENT`/`PAID`/`OVERDUE` as today. `InvoiceStatus::PENDING` (case 5) is **deprecated for new rows**: its current meaning ("converted → issued in one step") dies with the collapse it encoded. `CONVERTED` (6) remains the proforma terminal state, now stamped by `issue()` instead of by conversion.
- **Proformas:** conversion stamps the new case `InvoiceStatus::CONVERTING` (added); `issue()` stamps `CONVERTED`; `cancelPreparedInvoice()` restores `DRAFT` (§10). Conversion also keeps the proforma `is_immutable = true` (content frozen at conversion, the v7 FROZEN stand-in); cancellation reverts it.
- The `status` input of `createInvoice()` is re-scoped (§7.2).

### 5.3 `FiscalSubmissionStatus`

Six states per v7 §6.1 (`NOT_REQUIRED | PENDING | REGISTERED | ACTION_REQUIRED | CONTENT_REJECTED | LEGACY_UNKNOWN`). R1 writes only `PENDING`, `NOT_REQUIRED` (at issuance) and the backfill `LEGACY_UNKNOWN` (§8.3); the remaining transitions arrive with R2. The column is set at issuance ONLY — bidirectional CHECK (§8.2).

## 6. `issue()` — the new `@api` operation

Adapted from v7 §6.3, without the §5 artifacts. Atomic local sequence, one transaction, no external API inside it; deadlock retry wraps the WHOLE unit (the AID-570 outermost-boundary contract that `createInvoice()`/conversion already honour):

1. Lock the **proforma** row first when conversion-born, then the invoice row (lock order §6.3 — round 1 finding V3 corrected the inverted phrasing of an earlier draft). **Idempotency first, eligibility second** *(round 3, S3: the earlier single-sentence ordering made a retry-after-success hit validation before the early return)*: an already-`ISSUED` invoice returns itself unchanged; only then validate the eligible state — document status is `DRAFT` (direct) or `PREPARED` (conversion-born); not cancelled.
2. Validate fiscal coherence — the emissions requirements that exist today: active `CompanyFiscalConfig`, resolvable `UserTaxProfile` (AID-971 frozen-snapshot rules for rectificatives preserved), fiscal content present on the row, series resolvable. The determination-validation step of v7 (§6.3 step 2) has no counterpart yet: **the seam is named** (§14) and the §5 revival inserts it here without moving the rest.
3. Conversion-born only: re-run `FiscalChangeDetector` (ADR-001 — the identity seam; the v7 §5.6 matrix arrives with the §5 revival).
4. Capture ONE issuance instant; assign the correlative via `InvoiceNumberingService` **passing that instant** (§6.2); the numbering service joins the ambient transaction — no nested `DB::transaction` of its own (today's first-use protection keeps its deadlock-recovery contract; the outermost retry of step 0 covers it).
5. Stamp the issuance fields from the same instant: `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`. The instant governs these five; `prefix` is NOT among them — it is the series identity already selected at creation (§8.1) *(round 2, B6: an earlier draft listed "six values from the instant", contradicting the prefix lifecycle)*. `fiscal_year` is the CONFIGURED fiscal year of the instant (`RegionalContext::getFiscalYear($instant)`, `src/Support/RegionalContext.php:164-178` — the start month/day are configurable, `config/larabill.php:93`), never the calendar year: the coherence invariant is `fiscal_year == RegionalContext::getFiscalYear(invoice_date)` — *(round 1 finding V4: an earlier draft wrote `year(invoice_date)`, which rejects a valid boundary-date invoice under a July 1 fiscal year)*.
6. Stamp header AND lines fiscally immutable (§6.5).
7. Stamp `fiscal_submission_status`: `PENDING` when Verifactu compliance is configured, else `NOT_REQUIRED` (config-derived, recorded on the row; the audited decision operation of v7 §6.1 ships with R2).
8. Document → `ISSUED`; conversion-born: proforma `CONVERTING → CONVERTED`; collection axis stays `DRAFT`.
9. Issuing past a due date is not enforceable in R1 (no obligations): the deadline machinery is the §5 revival's, seam named (§14).

Failure taxonomy: transient (lock/deadlock) → rollback, the invoice stays DRAFT/PREPARED, retryable; domain-invalid → typed exceptions naming the remedy (cancel + recreate for direct; `cancelPreparedInvoice()` + re-conversion for conversion-born).

**Retry ownership is scoped exactly like durability (round 2, B2).** Laravel throws `DeadlockException` instead of retrying when the transaction level is greater than one (`vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:88-114`): the whole-unit deadlock retry is owned by `issue()` only when it is the OUTERMOST transaction. A consumer calling `issue()` inside its own `DB::transaction` owns the retry of its own unit — `UPGRADE-8.0.md` documents this per route, alongside the §7.1 durability scoping.

### 6.1 `InvoiceNumberingService` changes

- Transaction ownership is defined, not implicit *(round 1 finding S1)*: a new internal path (indicative `generateNumberInTransaction()`, `@internal`) performs the counter read/advance assuming the AMBIENT transaction and opens none of its own — this is what `issue()` calls. The public `generateNumber()` keeps its own `DB::transaction` wrapper (direct callers keep their atomicity contract, AID-390) and delegates to the internal path. Both stay documented in the surface taxonomy.
- **The issuance instant governs the control row too** *(round 2, B1; extended round 3, B1-residual)*: the counter lookup/creation, its stored fiscal-year range, and the format placeholders all derive from the passed instant. That includes FIRST USE: `createSeriesControl()` receives the fiscal year but derives its stored range from its own argument-less `getCurrentFiscalYearData()` (`InvoiceNumberingService.php:247` vs the `$fiscalYear` parameter at `:241-246`) — the range must come from `getCurrentFiscalYearData($instant)` like everywhere else, or a boundary-straddling first use keys the counter to the issuance year with a range from the next one. `{{TIMESTAMP}}` formats the instant, not a fresh `now()` (`:222`). Without all of this, a 23:59:59 issuance that reaches numbering after midnight stamps the invoice with the prior year while the counter and the formatted number advance into the new one.
- `generateNumber(string $prefix, int $serie, int|string|null $userId = null)` gains an optional trailing issuance-instant parameter (nullable, default `now()`): `fiscal_year` and the formatted number derive from the passed instant instead of an internal `now()`. Additive, non-breaking at the signature level; the service remains the single numbering owner (AID-390) and stays `@api`.
- **Single in-package call site for FISCAL documents: `issue()`.** `createInvoice()` stops calling the numbering service on its fiscal branch (§7.2): no fiscal row is born numbered. The PROFORMA branch is the documented exception — it keeps the birth call on its own serie, the settled §3.1 behavior *(round 12, V17: an earlier draft stated the single-call-site rule unscoped, making the proforma exception unimplementable — the arch test would fail on the very path the spec preserves)*. The service stays public (it is a primitive), and the scoped lifecycle contract is pinned by the contract snapshot of `InvoiceService` and by tests; enforcement is documented as architectural, not language-level.
- The counter row is NOT touched at DRAFT/PREPARED creation: on FISCAL documents, `invoice_series_control` moves only at issuance (the PROFORMA branch keeps its birth allocation on its own serie — §3.1/§7.2; scoped round 13, V17-residual). Continuity seed (`MAX(series_number)`, AID-390, `InvoiceNumberingService.php:249-259`) unchanged — R1 adds no seed work.

### 6.2 The issuance instant

v7 §6.3 step 4 verbatim: one instant captured in `issue()`; `InvoiceNumberingService` receives it so `fiscal_year`, `invoice_date` and `issued_at` cannot straddle midnight across three independent `now()` reads.

### 6.3 Idempotency and concurrency

- Two concurrent `issue()` on the same invoice: serialized by the invoice row lock; exactly one number.
- Two concurrent conversions of one proforma: serialized by the proforma lock (AID-554, unchanged); conversion consumes no correlative, so the virgin-series first-use deadlock surface shrinks to `issue()` paths (the AID-570 outermost-retry contract moves with them).
- Lock order (v7 §6.8, reduced set): `proforma → invoice → series control`. The epoch axis does not exist in R1; the series-control lock is acquired only inside the numbering service at issuance. No operation acquires these in another order.

### 6.4 What `issue()` does NOT do in R1

No determination validation (§14 seam), no obligation stamping (§14 seam), no handler-bound check (R2), no deadline validation (§14 seam), no rectificative handoff change (ADR-014 surface untouched).

### 6.5 Immutability and deletion guards (closing D8 and the deletion hole)

- Header: the existing `update()` override (`Invoice.php:287-311`) protects immutable rows with conversion/verification field exceptions; the **attribute-assignment + `save()` bypass** v7 D8 names is closed with `saving`/`updating` model-event guards that read the persisted immutable/document state (the `markAsPaidViaGroupedPayment()` pattern, `Invoice.php:256-272`, is preserved explicitly: collection state remains writable on ISSUED rows through a named, deliberate path).
- Lines: `InvoiceItem` gains the same state-driven `saving`/`updating`/`deleting` guards (v7 §7.2): no line mutation on an ISSUED document through any public operation.
- **Deletion:** a `deleting` guard forbids deleting a row whose document status is `ISSUED` (and a PREPARED conversion-born row is deleted only through `cancelPreparedInvoice()`, never deleted). Bulk/query-builder operations and external SQL remain documented outside the guarantee (v7 §10 limit, verbatim).

## 7. `createInvoice()` — two-phase, shape kept, NOT behavior-compatible

### 7.1 The two phases

One call, two transactions (v7 §6.1 adapted; no facts/obligations in phase 1 yet — seam §14):

1. **Durable phase:** commit the DRAFT row — no correlative, all five number/issuance fields NULL (the schema must permit it, §8), fiscal totals computed exactly as today (the live-catalog replacement is the §5 revival's business, not R1's). A later failure never erases the row: the customer's document intent survives.
2. **Issuance phase:** `issue()` as a second transaction (§6).

Result compatibility: the normal path still returns an ISSUED, numbered, immutable invoice — callers that only create-and-issue see identical results. Failure modes change (a phase-2 failure leaves a durable unnumbered DRAFT, resumable by calling `issue()` or cancellable) — documented as the NOT-behavior-compatible delta in `UPGRADE-8.0.md`.

**Durability is scoped to outermost callers** *(round 1 finding V2)*. The durable-phase promise holds only when `createInvoice()` runs outside any caller-owned transaction — the direct/consumer path and the settlement path (`SettlementService.php:104, :221` are not wrapped in an outer transaction; `GuaranteeService` deliberately runs its issuance phase as an independent transaction). The recurring-emission path calls `createInvoice()` INSIDE its own outer transaction (`RecurringBillingService.php:206` wraps the emission boundary; `:476` is reached from within it), and Laravel nests transactions as savepoints — the inner commit does not COMMIT (`vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:26-57`). A phase-2 failure there therefore rolls the draft back together with the caller's transaction. That is CORRECT for the recurring path, not a gap: its v6.10 contract is all-or-nothing (service status + invoice in one unit; a durable draft would desync the agreement state from the emission). The spec bans claiming durability on that path and `UPGRADE-8.0.md` documents the two failure modes per route; the §4 inventory's "result-compatible" for the recurring row means result, never durability.

### 7.2 The `status` input (AID-838 surface re-scoped)

**The proforma exception comes FIRST** *(round 11, V16: `createProforma()` sets `status: draft` and delegates to the generic path, `InvoiceService.php:492-500` — applying the fiscal draft rule would leave proformas unnumbered, violating §3.1 and the C3 CHECK clause)*: when the serie type is `PROFORMA`, the two-phase contract does NOT apply. The proforma path keeps the v6 behavior verbatim — numbered at birth on its own serie, no `issue()`, document axis NULL, `make_immutable` as today. `createInvoice()` branches on the resolved serie type before any phase runs; the branches never mix (a proforma can never reach phase 2, a fiscal row can never be born numbered).

For FISCAL types (`INVOICE`, `SIMPLIFIED`, `RECTIFICATIVE`):- `status: draft` explicitly requested → phase 2 does not run; the call returns an **unnumbered DRAFT**. This is the intended replacement for today's numbered drafts and is the seam AID-1628 designs against.
- Collection statuses (`SENT`, `PAID`, `OVERDUE`) remain accepted as the post-issuance collection stamp: phase 2 issues, then stamps the collection axis.
- `PENDING`, `CONVERTED`, `CANCELLED` **and `CONVERTING`** as caller input are rejected with a typed exception (they are lifecycle outcomes, not inputs) *(round 9, V14: `CANCELLED` was missing from the rejection list while the current mapper accepts enum case names, `InvoiceService.php:674-696` — a caller could otherwise birth an invoice already marked cancelled; round 10, V15: `CONVERTING` — the case this spec itself adds — was missing too, and the mapper normalizes both strings and ints, `:683-696`, so the rejection is on the NORMALIZED state, never just the literal)*; today they reach `mapStatusToEnum()` and produce contradictory rows. Cancellation is a transition on a DRAFT/PREPARED row (§5.1, §10), never a birth state: a caller wanting a cancelled document creates the DRAFT and cancels it. With this list the `InvoiceStatus` enum is exhausted: `DRAFT` accepted (unnumbered), `SENT/PAID/OVERDUE` accepted as post-issuance collection stamps, the four lifecycle outcomes rejected.

### 7.3 Settlement adoption resume (ADR-014 interplay) — keyed routes only

`settlement_operation_key` (UNIQUE) serialises concurrent settlements by collision-and-adoption. With durable unnumbered drafts, a phase-2 failure leaves a DRAFT carrying the settlement key; the adopting retry must detect the existing DRAFT by key and **resume issuance** (`issue()`) instead of treating it as a completed artifact. The adoption flow gains this branch; the key itself, its UNIQUE index and the claim semantics are untouched.

**The resume promise is scoped to KEYED routes** *(round 2, B3)*: the guarantee-settlement route is keyed (the rectificative is found by its own key, `GuaranteeService`), but `SettlementService::settleUnconsumed()` accepts and stamps NO key today (`SettlementService.php:190-221` measured) — a phase-2 failure there leaves a draft no retry can deterministically find, and a retry mints a second artifact. Disposition: PR-2 adds an optional key input to `settleUnconsumed()` (additive surface); until it lands, that route carries NO resume promise and `UPGRADE-8.0.md` documents the limitation verbatim.

## 8. Schema and migration program

House discipline in every PR: `.php` + `.php.stub` (derived, `bin/sync-migration-stubs`) + `$migrationOrder` entry + manifest + install-test bump + data program + upgrade-path test in the same PR (AID-398/412). First migration is a read-only **preflight**; each data migration re-validates its preconditions; every DDL operation is introspection-guarded (`hasColumn`/`hasIndex`) so a partially-applied failure re-runs cleanly (v7 §7 execution model, adopted).

### 8.1 Column changes on `invoices`

| Change | Detail |
| -- | -- |
| ADD `invoice_document_status` `unsignedTinyInteger` NULLABLE | `InvoiceDocumentStatus` cast. NULL on proforma rows (the document axis exists only for fiscal rows — the CHECK, §8.2); backfilled (§8.3). *(Round 1 finding V1: an earlier draft declared it NOT NULL, making proforma rows impossible under the spec's own invariant.)* |
| ADD `fiscal_submission_status` `unsignedTinyInteger` NULLABLE | `FiscalSubmissionStatus` cast. Backfill `LEGACY_UNKNOWN` on legacy fiscal rows (§8.3). Set at issuance only (§5.3). |
| MODIFY `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at` → nullable | MySQL `MODIFY` (in-place, metadata-only on 8.4). NULL exists only on DRAFT/PREPARED/CANCELLED fiscal rows (CHECK, §8.2). |
| MODIFY `issued_at` — DROP `DEFAULT CURRENT_TIMESTAMP` | The `useCurrent()` default (`2024_12_01_000003:31`) would stamp every unnumbered draft with a false issuance instant; it must die with the nullability flip. |
| ADD `document_cancelled_at` `timestamp NULL`, `document_cancelled_by` `string NULL` | The §10 audit trail (v7 §6.4 "audit records the release", minimal form). Written by ANY transition that sets the document axis to CANCELLED — `cancelPreparedInvoice()` for conversion-born (with actor), the consumer cancellation path for direct drafts (actor NULL). |
| `prefix` stays NOT NULL | Selected at creation (per-call `series` option, AID-576, retained) for both direct and conversion paths; only the number waits for `issue()`. |

New tables: **none.** The outbox is R2's; facts/obligations/epochs/determinations are the §5 revival's. The invoices-table CHECK work is the whole schema surface of R1.

### 8.2 CHECK-backed coherence (v7 §7.3 engine-split amendment, adopted verbatim)

Real CHECK on MySQL (the schema contract's engine) **plus** an application-level enforcer on every engine (model guard shipping in the same PR as the constraint). SQLite accepts a CHECK only inside `CREATE TABLE`, and this is an ALTER of an existing table — the app enforcer is its only SQLite enforcement; hand-written per-engine DDL forks are exactly the drift ADR-007 forbids.

The row-level CHECK (MySQL, named constraint; expressed over one row's columns) — including an explicit domain clause on the document status *(round 2, B4: the biconditionals already reject an out-of-enum value with uniform nullity, but a mixed-nullity out-of-enum row slipped through; the domain clause closes the hole and states the intent)*:

- `invoice_document_status IS NULL OR invoice_document_status IN (DRAFT, PREPARED, ISSUED, CANCELLED)` — enum domain, database-enforced on MySQL.
- `serie IN (0, 1, 2, 3)` — the `InvoiceSerieType` domain *(round 6, V11: the status columns had domain clauses and `serie` — stored as an unsigned tinyint, `2024_12_01_000003:25` — did not; a `serie = 255` row passed the whole set)*. The four cases are `PROFORMA/INVOICE/SIMPLIFIED/RECTIFICATIVE` (`src/Enums/InvoiceSerieType.php:20-25`).
- `serie = PROFORMA ⇔ invoice_document_status IS NULL` — the document axis exists only for fiscal rows; proforma rows keep their five number/issuance fields NOT NULL and are invisible to the document axis. **The proforma side is enforced, not prose** *(round 3, V7: with the five fields nullable and the coherence rule scoped to fiscal rows, an unnumbered proforma row had no clause rejecting it; round 5, V10: the biconditional first written here rejected every issued fiscal row — `serie ≠ PROFORMA` with all five fields NOT NULL makes it false — so it is a ONE-DIRECTIONAL implication)*: `serie = PROFORMA ⇒ fiscal_number/series_number/fiscal_year/invoice_date/issued_at are ALL NOT NULL`.
- For fiscal rows — guarded by the NULL-safe antecedent `invoice_document_status IS NOT NULL ⇒` *(round 7, V12: without the guard these biconditionals evaluate UNKNOWN on proforma rows — `NULL ⇔ FALSE` — and an application enforcer requiring all-TRUE would reject a valid proforma; with the guard every clause below is deterministic TRUE/FALSE for every row shape)*: `invoice_document_status = ISSUED ⇔ fiscal_number/series_number/fiscal_year/invoice_date/issued_at are ALL NOT NULL` AND `invoice_document_status IN (DRAFT, PREPARED, CANCELLED) ⇔ all five ARE NULL`; `invoice_document_status = CANCELLED ⇒ document_cancelled_at IS NOT NULL` (never numbered is subsumed by the five-NULL rule).
- **Submission axis, fiscal rows only, NULL-safe** *(round 7, V12)*: `invoice_document_status IS NOT NULL ⇒ (invoice_document_status = ISSUED ⇔ fiscal_submission_status IS NOT NULL)`. **With an explicit domain clause** *(round 4, V9: the document status had one, the submission status did not — an `ISSUED` row with `fiscal_submission_status = 255` passed)*: `fiscal_submission_status IS NULL OR fiscal_submission_status IN (the six states of §5.3)`.
- **Proforma hygiene, stated as an implication with a NULL-safe antecedent** *(round 5, S5: with `invoice_document_status` NULL on proformas, the ISSUED biconditional above evaluates UNKNOWN under MySQL three-valued CHECK semantics and passes anything)*: `invoice_document_status IS NULL ⇒ (fiscal_submission_status IS NULL AND document_cancelled_at IS NULL AND document_cancelled_by IS NULL)`.
- `document_cancelled_by IS NOT NULL ⇒ document_cancelled_at IS NOT NULL` — **one-directional** *(round 5, S4-residual: the round-4 symmetric pair rejected the specified direct-draft cancellation, which stamps the instant with actor NULL by design, §8.1/§10; an actor without an instant remains the rejected misleading shape)* — and the inverse of the cancellation rule *(round 8, V13: a non-CANCELLED fiscal row with a populated `document_cancelled_at` passed the whole set — the metadata claimed a cancellation that never happened)*: `document_cancelled_at IS NOT NULL ⇒ invoice_document_status = CANCELLED`. Together the two implications plus the fiscal-row CANCELLED rule close the cancellation metadata cycle; both reinforced by the application-level enforcer on every engine.

`fiscal_number` stays UNIQUE: multiple NULLs are admitted by every engine's unique index; the numbering service keeps collision-safety at issuance.

**The clause set as one system — intended row shapes** *(added round 6, after a reviewer misread the set and reported two malformed rows as passing that C2 rejects; corrected round 7, V12: the fiscal-row and submission clauses are NULL-safe implications, so EVERY clause is deterministic TRUE/FALSE for every row shape — no UNKNOWN path remains, and an application enforcer implemented as "all clauses TRUE" accepts exactly the intended shapes; the truth table below is the contract the TDD implementation tests)*. MySQL CHECK semantics: a row passes iff EVERY clause is TRUE (or, equivalently, none is FALSE — with no UNKNOWN path left, both formulations coincide):

| Row shape | C1 domain | C2 axis | C3 proforma fields | C4/C5 fiscal coherence | Verdict |
| -- | -- | -- | -- | -- | -- |
| Proforma (serie=0, doc NULL, five NOT NULL) | ✓ | ✓ | ✓ | n/a | passes |
| Fiscal DRAFT/PREPARED (five NULL) | ✓ | ✓ | n/a | ✓ | passes |
| Fiscal ISSUED (five NOT NULL, submission set) | ✓ | ✓ | n/a | ✓ | passes |
| CANCELLED conversion-born (five NULL, instant+actor) | ✓ | ✓ | n/a | ✓ | passes |
| CANCELLED direct (five NULL, instant, actor NULL) | ✓ | ✓ | n/a | ✓ | passes |
| Proforma + doc=ISSUED | ✓ | **✗** | — | — | rejected |
| Fiscal row + doc NULL | ✓ | **✗** | — | — | rejected |
| Mixed field nullity on any fiscal doc status | ✓ | ✓ | — | **✗** | rejected |
| Out-of-domain doc status / submission status / serie | **✗** | — | — | — | rejected |
| Actor without cancellation instant | — | — | — | — | rejected (C10) |
| Cancellation instant on a non-CANCELLED row | — | — | — | — | rejected (V13 inverse) |
| Submission/cancellation fields on doc-NULL row | — | — | — | — | rejected (C9) |

### 8.3 Backfill program (in one transaction; the ritual is documented maintenance)

The legacy tree has exactly one issuance-like state shape: **every numbered fiscal row was born with `issued_at = now()` at creation**. There is no data signal separating "issued" from "numbered draft" — v7 resolved this by classifying every numbered legacy row as ISSUED so no legacy exception is needed; R1 adopts the same rule and relies on the preflight report for operator visibility.

Precedence:

1. Proforma rows (`serie = PROFORMA`) → `invoice_document_status = NULL`, fiscal fields untouched.
2. Numbered fiscal rows (all five fields non-NULL — which is every fiscal row today) → `ISSUED`, `fiscal_submission_status = LEGACY_UNKNOWN`... with one carve-out: rows whose legacy `status = CANCELLED` keep the legacy status on the collection axis; the document axis records the historical truth (the number WAS consumed) as `ISSUED`. The preflight report lists them as "numbered cancelled legacy rows" so the operator sees the population (v7's own resolution: "the backfill classifies every numbered legacy row as ISSUED so no legacy exception is needed"). **Legacy `PENDING` fiscal rows** *(round 2, B5 — conversion births them, `InvoiceService.php:606-608`)*: the document axis classifies them ISSUED like every numbered row, and their collection `status` maps to `DRAFT` (the truthful collection value — `PENDING` encoded the collapsed conversion/issuance lifecycle, which dies; the case stays in the enum for backward-compatible reads). Covered by the upgrade test.
3. Unnumbered fiscal rows — **must not exist**; preflight fails loud if found (a mutable unnumbered row has no legacy producer; an immutable one would contradict §1.1's evidence).
4. `fiscal_submission_status`: every legacy fiscal row → `LEGACY_UNKNOWN` (no verifactu-state mapping is attempted in R1; the exit is R2's reconciliation operation).

### 8.4 Preflight (first migration, read-only)

- Count and list numbered fiscal rows with legacy `status = CANCELLED` (report; classified ISSUED by §8.3).
- Count and list legacy `status = DRAFT` and `status = PENDING` fiscal rows *(round 3, V8: PENDING was mapped in §8.3 but invisible to the preflight report)* (numbered drafts and converted-pending — the populations AID-1628 cares about; report, classified ISSUED with PENDING remapped to DRAFT).
- Fail on any unnumbered fiscal row (§8.3 case 3).
- Fail on rows violating the intended CHECK invariants in any way the backfill would not heal.
- EUR attestation / currency: N/A (out of scope, §2).
- The full v7 write-gate (advisory lock refusing package writes during the upgrade) is judged disproportionate for a metadata-only ALTER + transactional backfill; the documented ritual (stop workers + maintenance, as v7 §12 prescribes) is adopted, and the recurring-emission cron is explicitly named in it. The gate returns if the adversarial review disagrees.

### 8.5 Transitional coherence per PR (v7 constitution 16, adopted)

- **PR-1 (schema):** after the backfill, `createInvoice()` keeps its current behavior (numbered births). Transitional stamping: the creation hook writes `invoice_document_status = ISSUED` on every new numbered fiscal row — and stamps `fiscal_submission_status` coherently (`PENDING`/`NOT_REQUIRED` per config), because the bidirectional CHECK demands it on ISSUED rows — the same transitional lie v7 PR-1 used. The CHECK holds; the lie is documented and flipped in PR-2. Proforma rows and the proforma path stamp `NULL` on both axes.
- **PR-2 (lifecycle):** `issue()`/`cancelPreparedInvoice()`/`convertAndIssue()` land; `createInvoice()` is rewired to two-phase (the transitional stamping dies); the conversion path returns PREPARED; guards close D8 + deletion.
- **PR-3 (closure):** CHANGELOG promotion, `UPGRADE-8.0.md` consolidation, manifest re-stamp, tag — under the registry record (§15).

## 9. Conversion — `convertProformaToInvoice()` rewritten in place

Adapted from v7 §6.2 against the already-hardened 6.x conversion (AID-554/555/556/559/576/929 retained verbatim):

- Preconditions: proforma is a PROFORMA-serie row (the D7 freeze guard stays out — §4 revival), not already converted (mirror + lock idempotency unchanged), identity detection allowed (ADR-001, `force`/`on_changes` semantics preserved).
- Creates the invoice as **PREPARED**: verbatim contractual clone (current `items` mapping, `InvoiceService.php:572-599`), target series resolved per operation (`prefix` stamped), **no correlative consumed**, `invoice_date`/`issued_at`/`fiscal_number`/`series_number`/`fiscal_year` NULL, `fiscal_submission_status` NULL, document status PREPARED.
- Proforma: `CONVERTING` (new case), `is_immutable = true`, `proforma_id` canonical + `converted_invoice_id` mirror written at conversion (unchanged), `converted_at` = the conversion instant (unchanged).
- Typed command in / typed result out: the `Invoice|array` union dies — the `warnings` channel (`on_changes: warn`) moves into the typed result (v7 §6.2; naming gated by the contract snapshot).
- Return: the PREPARED invoice. The caller decides: `issue()`, `convertAndIssue()`, or leave prepared.

## 10. `cancelPreparedInvoice()` — audited release

Adapted from v7 §6.4: lock proforma + invoice; require PREPARED and conversion-born; invoice → `CANCELLED` **retaining** `proforma_id` (aborted preparation, documentary trace, never numbered); `document_cancelled_at/by` stamped (§8.1); proforma `CONVERTING → DRAFT`, `is_immutable` reverted, mirror (`converted_invoice_id`, `converted_at`) cleared; re-conversion possible. No determination to reuse (§5 revival's concern). Direct DRAFT cancellation keeps the existing consumer path (legacy `status = CANCELLED` + document `CANCELLED`, `document_cancelled_at` stamped with actor NULL).

## 11. `convertAndIssue()` — two sequential transactions, resumable

Adapted from v7 §6.5: conversion transaction commits first (`CONVERTING` + PREPARED); issuance transaction second. Issuance failure leaves CONVERTING + PREPARED — **legal and resumable** (retry `issue()` or cancel); conversion failure leaves nothing; failure after issuance commit leaves ISSUED with the submission axis pending. Re-invocation resumes idempotently — never a second invoice.

### 11.1 New `@api` surface

`issue()`, `cancelPreparedInvoice()`, `convertAndIssue()`, the stalled-preparations scope and report (§11.3), plus the `InvoiceNumberingService` instant parameter (§6.1).

### 11.2 The v7 §6.6 mapping — deferred with the seam named

The full operation-date mapping (advance/delivery/completion/chargeability) derives from materialized accruals: §5 territory. R1 keeps the current contract — `service_date` is dragged verbatim at conversion and never invented (AID-559). The one-accrual-date rejection has no subject in R1; the seam is named (§14).

### 11.3 Stalled preparations are visible (v7 Codex 58, adopted)

The stalled CONVERTING+PREPARED state is NEW failure territory R1 creates. A queryable scope (`Invoice::stalledPreparations()` or equivalent naming) plus an artisan report (`larabill:stalled-preparations`, indicative name) listing stalled aggregates is part of PR-2. The deeper deadline reporting is R4's (AID-1631).

## 12. Public surface and SemVer

- **v8.0.0 major**; qualified imperative: the openmizan R1 requirement (AID-1609) + the deletion hole (§1.1.2) + the audit burden of numbers burned on unissued documents (§1.1.1) — with art. 6.1.a RD 1619/2012 as the legal context (numbering and consecutive-within-serie obligations attach to the issued invoice; the article does not expressly forbid reservations, so the design rationale is house policy, stated as such) — `STABILITY.md` contract honoured: the imperative is documented here; the major is auto-upgradeable from 7.0.0 (§15).
- **Rewritten in place, no deprecation of defective semantics** (v7 §8 stance): `createInvoice()` (two-phase, §7) and `convertProformaToInvoice()` (PREPARED-returning, §9) change behavior without shims that would preserve the defect. Every new precondition fails typed; `UPGRADE-8.0.md` maps the mechanical changes.
- **Deprecated (non-defective displacement):** `InvoiceStatus::PENDING` for new rows (§5.2); the `warnings` array channel of conversion (§9).
- **New enums:** `InvoiceDocumentStatus`, `FiscalSubmissionStatus`; `InvoiceStatus` gains `CONVERTING`.
- **Contract gates extended:** `Invoice.json` model snapshot regenerated (new fillable columns) via `bin/sync-contract-snapshots` with the CHANGELOG gate. The service-signature surface of `InvoiceService`/`InvoiceNumberingService` is a NEW explicit deliverable of PR-2 — the existing machinery snapshots the seven models only (`tests/Contract/snapshots/`, `STABILITY.md:30-33`; verified round 1, finding V5: an earlier draft falsely claimed an existing service snapshot gate). PR-2 ships the signature-snapshot file(s) for the two services (methods, parameter types, return types) as part of its contract-gate work.

## 13. Testing contract (adapted from v7 §10, R1 subset)

- **State machines:** every transition and guard of `InvoiceDocumentStatus`; the proforma legacy mapping (`CONVERTING → CONVERTED` by `issue()`, `→ DRAFT` by cancellation); collection transitions still legal on ISSUED rows (grouped-payment pattern preserved).
- **Numbering:** no number on DRAFT/PREPARED birth (fiscal rows); `issue()` burns exactly one; midnight coherence (instant propagated; `fiscal_year == RegionalContext::getFiscalYear(invoice_date)`; **the fiscal-year-boundary crossing**: 23:59:59 instant + slow numbering selects the counter row and `{{TIMESTAMP}}` from the instant, with a non-calendar fiscal-year fixture); proforma serie numbering unchanged **including its birth call site inside `createInvoice()`'s PROFORMA branch — the arch test asserts the fiscal branch has no numbering call, not that the service is unreachable** *(round 12, V17)*; conversion consumes NO final correlative; the internal transaction-less numbering path asserts an ambient transaction.
- **Concurrency (fork, `RUN_CONCURRENCY_IT=1` + MySQL, sensitivity proof per house discipline):** two `issue()` → one number; two conversions → one link; the settlement adoption race (§7.3); lock-order compliance across convert/issue/cancel.
- **Backfill (MySQL upgrade-path):** numbered legacy → ISSUED with the bidirectional CHECK satisfied; unnumbered-impossible fail; preflight report contents; chain resumability including mid-DDL; write-during-maintenance ritual documented.
- **Guards:** `save()` bypass closed (attribute-assignment mutation on immutable/ISSUED rows throws); `deleting` guard on ISSUED; lines guarded; bulk operations documented outside the guarantee.
- **Surface:** `createInvoice()` issued-path result compatibility; explicit-draft path returns unnumbered; `PENDING`/`CONVERTED`/`CANCELLED`/`CONVERTING` inputs rejected typed — all four, via BOTH the string and the integer form of the normalized state *(round 10, V15: the enum's rejection list must be exhausted — CONVERTING is this spec's own new case)*; conversion returns PREPARED and proforma reads `CONVERTING`; `convertAndIssue()` resumability; stalled report.
- **Engine split:** CHECK enforced on MySQL; app enforcer asserted on SQLite (both in CI).

## 14. Named seams for the successors (what the §5/§4 revivals insert without re-plumbing)

1. **Determination validation** — `issue()` step 2 (§6).
2. **Obligation `FULFILLED` stamping** — `issue()` step 8-equivalent (§6).
3. **Identity matrix** — replaces the `FiscalChangeDetector` re-run at `issue()` step 3.
4. **Deadline validation and the due-past breach record** — `issue()` step 9 (§6.4).
5. **Phase-1 facts/obligations** — `createInvoice()` phase 1 (§7.1).
6. **Accrual-date coherence and the full `service_date` mapping** — §11.2.
7. **`FiscalSubmissionHandler` + outbox + typed outcomes + `LEGACY_UNKNOWN` reconciliation** — R2 (§2.1).
8. **Proforma `ProformaStatus`/freeze and the active-link generated column** — §4 revival (§3.2).
9. **Catalog epochs replacing live-catalog reads** — §5 revival (§7.1).

## 15. Governance and coordination

- **Registry:** executing this major opens `approvals/majors/larabill-8.md` in the central registry (house rule; the file does not exist yet — verified 2026-10-13).
- **Sequencing with the agreement-model major (ADR-015, 7.0.0):** this spec is written against the current 6.x tree and is self-contained; it does not consume ADR-015 surface. If 7.0.0 executes first, R1 rebases onto it (the settlement-adoption resume of §7.3 and the guarantee flow are the only touchpoints); if R1 executes first, 7.0.0 inherits the document axis. Either order is compatible; the registry records which ran.
- **Consumer impact:** `clientes` (AID-1628) and openmizan (AID-1609 origin) are informed through the sibling tickets; §12's breaking deltas land with the UPGRADE guide, and R2/R3/R4 proceed independently on their own tickets.

## 16. Gate adjudication — round 1 (2026-10-13, codex gpt-6-luna, session `01a133ac`)

Method judgment: **real, not cosmetic**. Six findings (5 VERIFIED, 1 SUSPECTED); every one measured against the tree and accepted. Landing:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V1 | `invoice_document_status` declared NOT NULL while the CHECK and backfill require NULL on proformas — proformas impossible under the spec's own invariant | Accepted | §8.1 column is now NULLABLE |
| V2 | The "durable draft" is not durable on the recurring path: `createInvoice()` runs inside `RecurringBillingService`'s outer transaction (`:206`, `:476`); Laravel nests as savepoints (`ManagesTransactions.php:26-57`), so a phase-2 failure rolls the draft back | Accepted — and re-adjudicated: for the recurring path all-or-nothing is the CORRECT contract (v6.10 atomic boundary; a durable draft would desync agreement state) | §7.1 scopes durability to outermost callers; §4 recurring row documents result-compatibility, never durability |
| V3 | `issue()` step 1 locked invoice-then-proforma, contradicting the declared order `proforma → invoice` and risking deadlock against `cancelPreparedInvoice()` | Accepted | §6 step 1 locks proforma first |
| V4 | `fiscal_year == year(invoice_date)` assumes calendar years; the package supports configurable fiscal-year start (`RegionalContext.php:164-178`, `config/larabill.php:93`) | Accepted | §6 step 5 and §13 use `RegionalContext::getFiscalYear()` |
| V5 | The claimed existing service-signature snapshot gate does not exist (only 7 model snapshots; `STABILITY.md:30-33`) | Accepted | §12 makes the signature snapshot an explicit PR-2 deliverable |
| S1 | `generateNumber()` opens its own transaction (`:62`); `issue()` needs an ambient-transaction path without a nested one, but direct callers need the wrapper | Accepted | §6.1 defines conditional ownership: internal transaction-less path + public wrapper |

Reviewer-only attribution: `Reviewed-by: Codex <noreply@openai.com>` (the reviewer wrote no code). The gate expires on further changes to this artifact; the next round audits these six landings.

### Round 2 (2026-10-13, codex gpt-6-luna, session `01a133b3`)

Method judgment: real; **not yet ready for TDD at that revision**. Part A: all six round-1 landings audited as correct. Part B: six VERIFIED + one SUSPECTED; all measured against the tree and accepted. Landing:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| B1 | The instant did not explicitly govern the counter row: `getCurrentFiscalYearData()` called with no argument (`InvoiceNumberingService.php:59`, method accepts `?Carbon $date` at `:283`) and `{{TIMESTAMP}}` reads a fresh `now()` (`:222`) — a 23:59:59 issuance crossing midnight stamps prior-year invoice with new-year counter | Accepted | §6.1 instant governs control row + placeholders; §13 boundary-crossing test with non-calendar fixture |
| B2 | The whole-unit deadlock retry is impossible inside an ambient transaction: Laravel throws `DeadlockException` at level > 1 (`ManagesTransactions.php:88-114`) | Accepted | §6 retry ownership scoped like §7.1 durability; UPGRADE documents per route |
| B3 | The adoption-resume promise exceeded the keyed surface: `settleUnconsumed()` accepts/stamps no key (`SettlementService.php:190-221`) — a phase-2 failure leaves an unfindable draft | Accepted | §7.3 scoped to keyed routes; PR-2 optional key input; no-resume documented until then |
| B4 | The CHECK did not state an enum-domain clause; reviewer's repro (out-of-enum + uniform nullity) was WRONG — the biconditionals reject it — but the weakened form holds: a mixed-nullity out-of-enum row passes | Accepted with corrected repro (finding survives in weak form; fix is cheap) | §8.2 explicit domain clause |
| B5 | Legacy `PENDING` fiscal rows unmapped by the backfill (conversion births them, `InvoiceService.php:606-608`); preflight listed only DRAFT/CANCELLED | Accepted | §8.3 maps collection status to `DRAFT`; upgrade test covers |
| B6 | "All six from the same instant" contradicted the prefix lifecycle (selected at creation, §8.1) | Accepted (wording) | §6 step 5: five fields from the instant; prefix excluded |
| S2 | Legal rationale stated more strongly than art. 6.1.a supports (BOE text obliges number + consecutive within serie; does not expressly forbid reservations) | Accepted | §1.1 and §12 restate the imperative as house policy (openmizan R1 + deletion hole + audit burden) with the article as context |

The gate expires on further changes; the next round audits these seven landings.

### Round 3 (2026-10-13, codex gpt-6-luna, session `01a133b8`)

Method judgment: not ready for TDD at that revision; density serving the design. Part A: six of seven round-2 landings verified, B1 partial (first-use control creation). Part B: two VERIFIED + one SUSPECTED, all measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| B1-r | `createSeriesControl()` derives its stored range from an argument-less `getCurrentFiscalYearData()` (`:247`) while receiving `$fiscalYear` (`:241-246`) — boundary-straddling first use keys one year with the other's range | Accepted | §6.1 first use derives the range from the instant |
| V7 | Proforma rows' five-fields-NOT-NULL was prose, not CHECK: with nullable columns and the coherence rule scoped to fiscal rows, an unnumbered proforma passed | Accepted | §8.2 proforma biconditional clause |
| V8 | Backfill maps legacy PENDING (§8.3) but the preflight report omitted the population | Accepted | §8.4 lists DRAFT and PENDING |
| S3 | `issue()` step 1 ordered validation before the idempotent ISSUED early-return — a retry-after-success would be rejected | Accepted (ordering) | §6 step 1: idempotency first, eligibility second |

Trajectory: 6 → 7 → 3 findings, the last round wording-level plus two small schema gaps. Round 4 audits these four landings; if it returns only wording-level findings or none, the gate closes with its coverage statement.

### Round 4 (2026-10-13, codex gpt-6-luna, session `01a133bc`)

Method judgment: lifecycle coherent, not ready for TDD on one CHECK gap. Part A: all four round-3 landings verified. Part B: one VERIFIED + one SUSPECTED, both measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V9 | Submission-status enum domain not CHECK-enforced: `fiscal_submission_status = 255` on an ISSUED row passed every stated clause | Accepted | §8.2 explicit domain clause on the submission axis |
| S4 | `document_cancelled_by` without `document_cancelled_at` permitted on a non-cancelled row — misleading audit value; ownership (CHECK vs enforcer) unstated | Accepted | §8.2 symmetric NULL-pair clause + app enforcer |

Trajectory: 6 → 7 → 3 → 1(+1 suspected). Round 5 audits these two landings AND repeats the round-4 prompt identically (the single-run-variance rule: two runs over the same tree, union taken) — its verdict closes the gate with the coverage statement.

### Round 5 (2026-10-13, codex gpt-6-luna, session `01a133bf`)

Method judgment: real, not ready — the CHECK rules contained contradictions. Part A: V9 verified; S4 landed textually but broke a specified path. Part B: two VERIFIED + one SUSPECTED, all measured and accepted — two were defects this gate itself had introduced in earlier rounds, caught by exhaustive truth-table work:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V10 | The round-3 proforma clause was a BICONDITIONAL (`serie = PROFORMA ⇔ five fields NOT NULL`) — it rejects every issued fiscal row (`serie ≠ PROFORMA` + fields NOT NULL ⇒ clause false) | Accepted | §8.2 proforma clause is now a one-directional implication |
| S4-r | The round-4 symmetric NULL-pair rejected the specified direct-draft cancellation (instant stamped, actor NULL by design, §8.1/§10) | Accepted | §8.2 one-directional: actor ⇒ instant |
| S5 | With `invoice_document_status` NULL on proformas, the ISSUED biconditional evaluates UNKNOWN under MySQL three-valued CHECK semantics — a proforma with `fiscal_submission_status = PENDING` passes | Accepted | §8.2 proforma-hygiene implication (NULL-safe antecedent) |

Counter call sites re-verified by the reviewer: `:59`, `:247`, `:222` — no residual instant-threading gap. Round 6 audits these three landings; a clean or wording-only verdict closes the gate.

### Round 6 (2026-10-13, codex gpt-6-luna, session `01a133c3`)

Method judgment: not ready — the CHECK set still admitted malformed rows. Part A: S4-residual and S5 verified; V10 verified as landed with a presentational caveat. Part B: two VERIFIED, measured — one accepted, one rejected with proof:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V11 | `serie` has no domain clause: `serie = 255` + a fiscal DRAFT shape passes the whole set (`InvoiceSerieType` defines 0-3, `src/Enums/InvoiceSerieType.php:20-25`; column is unsigned tinyint, `2024_12_01_000003:25`) | Accepted | §8.2 `serie IN (0,1,2,3)` domain clause |
| — | Reviewer's two "boundary rows pass" (proforma+doc=ISSUED; fiscal+doc=NULL) | **Rejected after measurement**: both are rejected by the C2 axis biconditional (`serie = PROFORMA ⇔ doc IS NULL`, §8.2 second bullet) — both operands are non-nullable booleans, no UNKNOWN path; truth table run over the full clause set (row shapes + malformed combinations) confirms | §8.2 gains the intended-row-shape table so the enforced set is unambiguous for implementers and reviewers |

Truth table recorded in §8.2. Round 7 audits the V11 landing and the table; a clean or wording-only verdict closes the gate.

### Round 7 (2026-10-13, codex gpt-6-luna, session `01a133c6`)

Method judgment: mostly coherent, one enforcement-contract contradiction left. Part A: V11 verified; the C2 rejections of the round-6 disputed rows independently re-derived and confirmed; legit shapes per document status verified. Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V12 | §8.2's "no clause can evaluate UNKNOWN" claim was false: the submission biconditional on a proforma row evaluates `UNKNOWN ⇔ FALSE` = UNKNOWN, so an application enforcer requiring all-TRUE would reject a valid proforma | Accepted | Fiscal-row and submission clauses are now NULL-safe implications (antecedent `doc IS NOT NULL`); every clause deterministic; enforcer = all-TRUE, stated |

Trajectory: 6 → 7 → 3 → 2 → 3 → 2 → 1 → 1; the last rounds are single-clause CHECK refinements. Round 9 audits the V13 landing; a clean or wording-only verdict closes the gate with the coverage statement.

### Round 8 (2026-10-13, codex gpt-6-luna, session `01a133c9`)

Method judgment: V12's NULL-safe fix verified with a full row-shape derivation (all twelve requested shapes resolve correctly). Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V13 | The cancellation rule had no inverse: a non-CANCELLED fiscal row (even ISSUED) with a populated `document_cancelled_at` passed every clause — metadata claiming a cancellation that never happened | Accepted | §8.2 inverse implication `document_cancelled_at IS NOT NULL ⇒ doc = CANCELLED`; row-shape table gains the row |

Round 9 audits the V13 landing; a clean or wording-only verdict closes the gate.

### Round 9 (2026-10-13, codex gpt-6-luna, session `01a133cc`)

Method judgment: CHECK design coherent for the listed shapes; one input case undefined. Part A: V13 verified with a complete derivation of all fourteen shapes — the clause set is closed. Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V14 | `createInvoice(status: CANCELLED)` undefined in §7.2 while the current mapper accepts enum case names (`InvoiceService.php:674-696`) — an implementer could birth an invoice already marked cancelled | Accepted | §7.2: `CANCELLED` joins the rejected-inputs list; cancellation is a transition, never a birth state |

Round 10 audits the V14 landing; a clean or wording-only verdict closes the gate.

### Round 10 (2026-10-13, codex gpt-6-luna, session `01a133cf`)

Method judgment: CHECK design not the blocker; one creation-time state omitted. Part A: V14 verified, with the observation that the rejection must cover the NORMALIZED state (mapper accepts strings and ints, `InvoiceService.php:683-696`). Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V15 | `CONVERTING` — the enum case this spec itself adds — was missing from the rejected create-time inputs and the test contract; a caller could birth a direct invoice with collection status CONVERTING | Accepted | §7.2 rejection list exhausted (DRAFT / SENT-PAID-OVERDUE accepted, four lifecycle outcomes rejected, normalized-state semantics); §13 test cases cover both string and integer forms |

With V15 the `InvoiceStatus` enumeration is exhausted in §7.2. Round 11 audits the V15 landing; a clean or wording-only verdict closes the gate.

### Round 11 (2026-10-13, codex gpt-6-luna, session `01a133d1`)

Method judgment: substantial, not cosmetic; one real conflict in the proforma path. Part A: V15 verified — with the reviewer correctly flagging that the round prompt's enum list diverged from the checked-in source (case 6 is `CONVERTED`; `CONVERTING` exists only as this spec's new case); the prompt error was this gate's, not the spec's. Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V16 | `createProforma()` rides the generic `status: draft` path (`InvoiceService.php:492-500`) — the new fiscal draft rule would leave proformas unnumbered, contradicting §3.1 and the C3 CHECK clause | Accepted | §7.2: serie-type branch BEFORE the two-phase contract — PROFORMA keeps the v6 numbered-birth path verbatim; branches never mix; §4 inventory row annotated |

Round 12 audits the V16 landing; a clean or wording-only verdict closes the gate.

### Round 12 (2026-10-13, codex gpt-6-luna, session `01a133d4`)

Method judgment: V16 lands locally but collides with the numbering call-site contract. Part A: V16 verified against `createProforma()`, the C3 clause, the §4 inventory AND the PR-1 transitional stamping — all coherent under the branch. Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V17 | "Single numbering call site: `issue()`" was stated UNSCOPED while §7.2's proforma branch requires a birth call — the two contracts are unsatisfiable together | Accepted | §6.1 call-site rule scoped to fiscal documents; §13 arch test asserts the fiscal branch has no numbering call, not service unreachability |

Round 13 audits the V17 landing; a clean or wording-only verdict closes the gate.

### Round 13 (2026-10-13, codex gpt-6-luna, session `01a133d7`)

Method judgment: not yet — residual unscoped statements of the same rule. Part A: V17 lands in §6.1/§13. Part B: one VERIFIED, measured and accepted:

| # | Finding (measured) | Verdict | Where it landed |
| -- | -- | -- | -- |
| V17-r | §2 ("Numbering only inside `issue()`") and §6.1 ("the counter moves only at issuance") remained unscoped — an implementer following them would fail or remove the proforma branch's birth call, breaking the WHMCS zero-invoice import route (`docs/import-support.md:48-69`) | Accepted | Both statements scoped to fiscal documents |

Round 14 audits the V17-residual scoping; a clean or wording-only verdict closes the gate.

### Round 14 — GATE CLOSED (2026-10-13, codex gpt-6-luna, session `01a133db`)

**Method judgment: the spec is coherent enough for TDD.** Part A: both round-13 scopings verified; the whole-spec search found no residual contradicting clause; the proforma path verified end to end (route, CHECK, inventory, import). Part B: **no substantive findings.** The three residual unqualified summary spots (title, §1.1, §1.2) were non-blocking and are scoped in this revision for accuracy.

**Gate closure and coverage statement.** Fourteen rounds over five revisions (c870761 → 58b4ea2 → 67080c9 → aee1180 → dcb2412 → 21ba4da → d90093d → 54b5761 → a88ef2f → b164d93 → 250ebfc → 8e48cba → ccd5419 → this one); finding trajectory 6 → 7 → 3 → 2 → 3 → 2 → 1 → 1 → 1 → 1 → 1 → 1 → 0 substantive. Union of runs taken; two reviewer findings rejected after measurement with recorded derivations (round-2 B4 repro; round-6 boundary rows). Rounds 1-10 covered the design surface (statuses, numbering, issuance, conversion, schema, backfill, concurrency, SemVer); rounds 11-13 were the proforma exception rippling through absolute statements; round 14 closed the gate. Every finding is adjudicated in the tables above with its landing place. The gate is evidence about THIS revision; any further change to this artifact re-opens it, and the implementation gate before merge/tag is separate and still owed.

