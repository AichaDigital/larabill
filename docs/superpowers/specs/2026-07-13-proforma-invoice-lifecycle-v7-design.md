# Proforma → Invoice Lifecycle Redesign (v7) — Design

- **Date:** 2026-07-13 (rev. 3 — Codex adversarial round 1 incorporated: 40 findings triaged, 30 accepted, 4 arbitrated by the operator, 3 partially rebutted with boundary clarifications)
- **Status:** Draft — pending re-verification (Codex round 2) before implementation planning
- **Supersedes:** AID-444 (rejected as specified — see §13), reframes AID-442's data layer
- **Related:** ADR-001 (fiscal config freezing), ADR-003 (user/customer unification), AID-307 (fiscal series vs type), AID-328 (snapshot vs live-row comparison), AID-390 (numbering owner), `STABILITY.md`
- **Target release:** v7.0.0 (major — qualified imperative documented in §1)

## 1. Motivation — forensic findings on the current conversion

`InvoiceService::convertProformaToInvoice()` exists since before v1.0, is public `@api` surface (`docs/api-surface.md`), and has **zero tests**. A forensic review (2026-07-13) confirmed the following defects, each independently a fiscal-correctness or integrity problem:

| # | Defect | Evidence |
|---|--------|----------|
| D1 | No row locking: `refresh()` instead of `lockForUpdate()`; the idempotency check is TOCTOU — two concurrent conversions can produce two final invoices | `src/Services/InvoiceService.php:346,353` |
| D2 | The canonical link is never written: `invoices.proforma_id` exists and `convertedInvoices(): HasMany` reads it, but conversion only writes `converted_invoice_id`; the bidirectional link is broken from birth | `src/Models/Invoice.php:397`, `InvoiceService.php:409-415` |
| D3 | Line reconstruction copies only 4 fields (`article_id`, `description`, `quantity`, `base_price`); it discards `item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata`, and the `taxes_applied` snapshot | `InvoiceService.php:391-396` |
| D4 | Taxes are RECALCULATED against the live catalog (`Article::find()->tax_group_id`, `TaxGroup::find()->with('taxRates')`) — the amounts the customer saw on the proforma can silently change | `src/Services/TaxCalculationService.php:98-103` |
| D5 | No target fiscal series can be selected at conversion, although `createInvoice()` supports explicit series | `InvoiceService.php:388-402` |
| D6 | Conversion and issuance are collapsed (the final invoice is born `PENDING` + immutable + numbered + dated in one step), and fiscal registration is an optional afterthought with no owned state | `InvoiceService.php:398,405` |
| D7 | Conversion does not require the proforma to be frozen or paid; any `serie=PROFORMA` row converts | `InvoiceService.php:348` |
| D8 | `Invoice` immutability does not protect `InvoiceItem` (no line guard), and the header guard itself only overrides `update()` — attribute assignment + `save()` bypasses it | `src/Models/InvoiceItem.php`, `Invoice.php:255,279` |
| D9 | Contradictory cardinality: `converted_invoice_id` (scalar, 1:1) coexists with `proforma_id` + `convertedInvoices()` (1:N); the database enforces neither | `Invoice.php:79,397`, migration `2024_12_01_000003:45` |
| D10 | `service_date` is populated by no service — nullable, only the factory writes it (randomly); the operation-date row (AID-442) fires only by accident | grep across `src/` |

A green build (26 numbering/tax/drift tests pass) does not cover the conversion contract. These defects are the **qualified, documented imperative** that `STABILITY.md` requires for a breaking release.

## 2. Domain ownership and boundary rules

Larabill is the invoicing engine and the **sole owner** of the proforma domain:

- **Larabill owns:** proforma lifecycle, freezing of lines and amounts, conversion to invoice, fiscal numbering, determination and persistence of the tax snapshot, the traceable proforma→invoice link, fiscal immutability, issuance deadlines, and the rules around operation date, advance payment and issuance.
- **Consumers own:** when to invoke those operations, their UX, and their commercial domain (acceptance, payment gateways, orders).
- **Consumers may NOT:** clone lines, recalculate or copy taxes, assign fiscal numbers, or implement an alternative conversion. A consumer-side port is legitimate only when it adapts a larabill primitive; if it contains the fiscal algorithm because larabill does not offer it, it is duplicated domain under another name.
- **`lara-verifactu` owns (sibling package, NOT larabill):** the Verifactu chain, the fiscal registration record and its hash, the immutable submission/annulment records, the AEAT submission semantics, and chain ordering/serialization. Larabill owns only the issued immutable invoice, the durable intent to hand it to the fiscal integration, the observable state of that handoff, and the correlation with the external acknowledgment (§6.7).

**Operative blocking rule:** if a consumer needs to write directly into `invoices`, `invoice_items`, snapshots, totals, series or conversion links, the consumer work is BLOCKED and a capability-gap ticket is opened in larabill first. No local fallback is admitted.

Two questions must never be conflated: domain authority belongs to larabill; real consumer usage only informs SemVer/migration risk (§12 is the only section where consumers appear).

## 3. Settled design decisions (the constitution of this redesign)

The constitution states **release-boundary invariants of v7.0.0** — the shipped release must satisfy all of them; individual epic PRs are transitional and documented as such (§9).

1. Larabill owns the full proforma and conversion domain (§2); the Verifactu registration record and chain belong to `lara-verifactu` (§6.7).
2. AID-444 is not implemented as specified; it is superseded by this design (§13).
3. A frozen proforma freezes its **commercial contract** — including the contractual unit price and its tax mode (`contract_unit_price` + `price_tax_mode`) as columns distinct from the fiscal net `unit_price`, whose semantics never change (§4.3).
4. At the v7.0.0 release boundary, no tax computation anywhere in the package consults the live catalog outside the dated resolver — conversion, direct invoice creation and provisional proforma estimates included (§5.3, §6.1).
5. The tax snapshot is fixed by a **materialized accrual determination**: larabill determines the accrual from registered economic facts and the frozen contractual nature — the consumer never delivers a resolved accrual (§5).
6. Cardinality is **strict 1:1** for automatic conversion: a CONVERTED proforma originates exactly one **issued** invoice; CANCELLED and SUPERSEDED proformas originate zero. Uniqueness of the active link is database-enforced (§7.1). v7 supports automatic conversion **only after full payment**; partial payments exist, are always recorded, and produce a visible blocked obligation (§5.1) — they are never denied.
7. `service_date` carries the **operation date of RD 1619/2012 art. 6.1.i**, derived per operation rule from the materialized accrual (§6.6), never a configurable preference. Equal dates can only result from the real dates coinciding. No global boolean exists.
8. Conversion, issuance and fiscal submission are **distinct transitions** on distinct state axes (§6).
9. Conversion is atomic, locked and idempotent, backed by database constraints, under the global lock order of §6.8.
10. The invoice keeps a verifiable canonical link to its proforma (`proforma_id`); `converted_invoice_id` is deprecated as a **mirror of the ACTIVE conversion only** (cleared when a preparation is cancelled — the two columns are deliberately not equivalent for cancelled preparations), dual-written during v7, removed in v8.
11. The target fiscal series is resolved inside larabill (`InvoiceSeriesResolver`) and can be indicated per operation; the correlative counter scope is the ISSUER (nil-UUID `GLOBAL_SCOPE` sentinel), the AID-390 model.
12. This redesign is the **evolution of a public API**, not greenfield. Every schema/data change ships its upgrade program in the same PR (AID-398).
13. New operations take **typed command objects and return typed results** (§8); no `$options` arrays, no `legacy_recalculate=true` escape hatch, and the legacy `force`/`on_changes` conversion options are retired.
14. The **contractual nature** of each operation is explicit frozen data provided by the consumer, from a **closed enum owned by larabill** (`GOODS_DELIVERY` | `ONE_OFF_SERVICE` | `SUCCESSIVE_TRACT`); an advance is a payment FACT over one of those natures, never a nature itself. Extending the set is a minor release of the package; consumers never register nature classes/closures; human-judgment cases use the audited decision operation.
15. **Currency is explicit:** ISO-4217 persisted on proformas/invoices (backfill `EUR`), copied on conversion, carried by facts and determinations, with equality validated across the whole aggregate. The v7 resolver only admits `EUR`; any other currency fails loud — explicit persistence without pretending multi-currency support.
16. Every PR in the epic leaves the runtime **coherent with the new schema** (transitional wiring where the final implementation lands later) — "each PR green" is necessary but not sufficient (§9).
17. Consumer references appear only in the impact/migration section (§12).

## 4. Proforma documental lifecycle

### 4.1 `ProformaStatus` — a separate enum and column

Proforma states do NOT enter `InvoiceStatus`. A new `ProformaStatus` enum backs a new nullable `invoices.proforma_status` column, canonical for rows with `serie = PROFORMA`:

```text
DRAFT ──freeze()──▶ FROZEN ──convert()──▶ CONVERTING ──(linked invoice ISSUED)──▶ CONVERTED  (terminal)
  │                    │  ▲                    │
  │                    │  └──(prepared invoice cancelled)──┘
  │                    ├──supersede()──▶ SUPERSEDED  (terminal)
  │                    └──cancel()─────▶ CANCELLED   (terminal)
  └──cancel()──▶ CANCELLED (terminal)

LEGACY ──adopt() (audited)──▶ FROZEN        (migration-only entry state, §7.1)
```

- **DRAFT:** editable. Not convertible — `convert()` on a DRAFT fails loud (closes D7). Its identity snapshots are **provisional** (§4.4).
- **FROZEN:** commercial content immutable (§4.3). The only state from which conversion is legal.
- **CONVERTING** (non-terminal): a PREPARED invoice exists and is linked. While CONVERTING, the proforma cannot be superseded or cancelled directly — the only exits are the linked invoice being issued (→ CONVERTED) or the prepared invoice being cancelled (→ back to FROZEN, §6.4).
- **CONVERTED:** terminal. Means the linked invoice was **issued**. Stamped by `issue()` in the same transaction (§6.3).
- **SUPERSEDED:** terminal; neither editable nor convertible. Design inference from the AEAT traceability requirement, not a literal prescription of the norm.
- **CANCELLED:** terminal. A frozen proforma may be abandoned without a successor — unless protected (guard below).
- **LEGACY:** migration-only state for pre-v7 immutable proformas that lack a genuine freeze-time identity, audited `operation_nature` and frozen tax classification (§7.1). A LEGACY proforma cannot determine or convert; an audited `adopt()` operation supplies the missing frozen data (actor, source, timestamp) and transitions it to FROZEN. Backfilling those rows straight to FROZEN would be an affirmative fiscal guess.

**Guards:**

- **Protection triggers at FACT registration, not at determination.** From the moment ANY accrual-creating economic fact is registered (payment — full or partial —, delivery, chargeability reached), the proforma can no longer be cancelled or superseded: the fiscal obligation was born with the fact, even while its resolution is pending, blocked on a compromised epoch, or awaiting an override. A defective determination discovered **before** any issued invoice consumed it is corrected by an audited re-determination (§5.4); corrections after an issued invoice belong to the rectificative handoff (§5.7).
- `supersede()` creates/links the successor and closes the predecessor **atomically** in one transaction.
- State transitions are executed and validated by larabill; consumers only request them.

**Legacy `InvoiceStatus` projection (honest, lossy):** the old `status` column is **frozen as a historical, non-authoritative value at migration time** — larabill neither updates it for proforma document transitions nor writes new values into it (`FROZEN`, `CONVERTING`, `SUPERSEDED`, `LEGACY` have no legacy representation, and inventing one — e.g. keeping `DRAFT` alive on a frozen proforma — would be a false value). `docs/api-surface.md` and `UPGRADE-7.0.md` document that for proformas the column is a pre-v7 fossil; `proforma_status` is the only authority.

### 4.2 Supersession — a single FK

The successor carries `supersedes_proforma_id` (self-referencing FK). The inverse relation is a query from the predecessor — no second physical pointer (avoids reproducing the D9 contradiction).

- `unique(supersedes_proforma_id)` — only one successor may exist.
- Self-reference forbidden; cycle prevention enforced in the service; both sides must be proformas.

### 4.3 What FROZEN freezes — commercial truth vs fiscal representation

Two prices with distinct semantics coexist on every line; conflating them was rejected:

- **`contract_unit_price`** (new column) — the agreed commercial unit price, in the mode declared by **`price_tax_mode`** (`tax_exclusive`: net | `tax_inclusive`: gross). Frozen at `freeze()`, never mutated. Historical backfill: `contract_unit_price := unit_price`, `price_tax_mode := tax_exclusive` (the implicit historical contract made explicit).
- **`unit_price`** — keeps ONE semantics forever: the fiscal **net** unit price (a complete invoice must display the unit price without tax). For `tax_exclusive` lines it equals the contractual price; for `tax_inclusive` lines the final invoice's `unit_price` is produced by the tax determination (net derived from the bound gross), while `contract_unit_price` + `price_tax_mode` preserve the original commercial truth.
- **Binding amount is the LINE TOTAL:** `tax_exclusive` binds the line net base; `tax_inclusive` binds the line gross total. Binding at line level avoids unit×quantity residue ambiguity.
- **"1:1 copy" means exact copy of the CONTRACTUAL terms** (`contract_unit_price`, `price_tax_mode`, quantity, description, nature, codes, unit, periods, metadata) — never blind reuse of derived fiscal columns, which come from the determination (§5.4, §6.2).

**Multi-component redistribution (`tax_inclusive`):** the redistribution operates over the FULL component set the determination resolves (VAT, recargo de equivalencia, any additional components) — `gross / (1 + rate)` is only the degenerate single-component case and is NOT the rule. The algorithm is fixed at plan time under these **binding invariants**: (a) net base + Σ component quotas == bound gross, exactly, per line; (b) each displayed component quota derives from the displayed base and its displayed rate under the documented rounding (RD 1619/2012 art. 6.1.g–h: rate and separately stated quota must be consistent); (c) deterministic; (d) per line, no cross-line residue. When (a) and (b) admit no simultaneous solution for a given gross (cent-level corner cases, negative lines), the documented fallback adjusts the largest component quota by the minimal residual and records the adjustment in the determination metadata — never silently.

**`taxes_applied` disambiguation:** semantics fixed by document type — on **proforma** lines a **provisional estimate** (all legacy proforma lines declared provisional by definition); on **invoice** lines the **definitive copy of a tax determination**, with provenance via `tax_determination_id` (§5.4). PHPDoc and `docs/api-surface.md` stop calling it universally an "immutable tax snapshot".

The frozen commercial line is never mutated to turn an estimate into fiscal truth.

### 4.4 Identity snapshots — provisional at DRAFT, frozen at freeze(), validated at determination and issuance

- Snapshots generated at DRAFT creation are **provisional**.
- `freeze()` re-resolves issuer and customer identity and freezes them for the proforma (document identity).
- Identity compatibility is validated against the policy matrix (§5.6) at **determination time** AND again inside **`issue()`** (§6.3) — a change between preparation and issuance must not be issued stale. True historical identity resolution for late-registered facts is subject to the same provable-interval limits as tax rules; where larabill cannot prove, the audited decision path applies (§5.5).
- The final invoice carries its **own** fiscal snapshot; the original proforma stays intact as the commercial origin.

## 5. Tax resolution — economic facts, accrual, epochs, resolver, determinations

### 5.1 Economic facts (input) vs fiscal judgment (larabill's)

Consumers register **economic facts** through an explicit, audited larabill operation (append-only table; indicative name `billing_economic_facts`): payment received (date, amount, **currency**), goods delivered (date), service completed (date), chargeability reached (date). Each fact carries actor, source and timestamp. **No fact is ever rejected or deleted for being inconvenient — facts are historical truth.**

**Contractual nature is frozen data** (constitution 14): each proforma line carries `operation_nature` (`GOODS_DELIVERY` | `ONE_OFF_SERVICE` | `SUCCESSIVE_TRACT`), provided by the consumer as contractual data and frozen at `freeze()`. Larabill validates fact/nature consistency (a chargeability fact against a one-off line is rejected loud — the FACT type is inconsistent, which is different from rejecting an inconvenient true fact) and maps nature + facts to the accrual rule (art. 75 LIVA semantics for the Spanish region; strategies live inside the package): payment before the operation → accrual on the collected amount at payment date (art. 75.Dos); goods → delivery/putting-at-disposal; one-off service → completion; successive tract → the agreed chargeability date.

**Successive tract requires the frozen chargeability schedule.** `SUCCESSIVE_TRACT` lines must carry the agreed due schedule at `freeze()` (frozen data — larabill cannot derive exigibility it was never told). The art. 75.Uno.7.º fallbacks are implemented: no agreed exigibility, or exigibility spanning more than 12 months → accrual on 31 December of each year for the proportional part. When the schedule yields MORE than one accrual date, the one-date-per-invoice invariant (§6.6) blocks automatic conversion with a typed exception — larabill never picks a date.

**Partial payments — always recorded, honestly blocked (operator arbitration, Codex finding 1):**

- A payment fact whose amount does not cover the proforma's full bound total is **registered normally** (art. 75.Dos accrues VAT on every amount actually collected; "out of scope" does not suspend the law).
- Registration immediately protects the proforma (§4.1 guard) and sets the **fiscal obligation axis** (§5.8) to `BLOCKED_PARTIAL_ADVANCE`: a visible, alarmed state declaring that a legal accrual exists which v7 cannot yet resolve into an advance invoice (1:N capability). Determination and conversion over that proforma fail with a typed exception naming the missing 1:N capability and its ticket.
- Larabill does NOT invent a proportional allocation of the partial amount across lines/taxes — that allocation is 1:N domain.
- The 1:N ticket is **High priority and a prerequisite for any consumer that can accept partial payments**. The v7 operating restriction reads "only automatic conversion after full payment is supported", never "partial payments do not exist".

**Fact correction:**

- Before any determination: a replacement fact carrying `supersedes_fact_id` (unique) substitutes the erroneous one without deleting history.
- After determination: facts are never rewritten; corrections route to re-determination (pre-issuance, §5.4) or the rectificative handoff (post-issuance, §5.7).

**Currency:** fact amounts carry ISO-4217 currency and must equal the proforma's persisted currency (constitution 15); mismatch fails loud.

### 5.2 Catalog epochs — the provable interval

Timestamp inference (`MAX(updated_at)`) is REJECTED as proof: physical deletes destroy evidence, the pivot has no history, query-builder/SQL/imports may bypass timestamps, and code/strategy/rounding changes never appear in those tables.

Larabill maintains **explicit catalog epochs** (indicative name `tax_catalog_epochs`):

- **Fields:** revision identifier, `observed_from`, hash of the complete rule set (rates, groups, group↔rate relations, special conditions; canonical serialization fixed at plan time), `closed_at`, integrity state (`intact` | `compromised`).
- **Governed closure:** every catalog mutation performed through larabill closes the active epoch **within the same transactional boundary** as the mutation and opens the next one.
- **Resolver version is pinned per DETERMINATION, not per epoch** (rolling deployments make "a code version opens an epoch" non-atomic across nodes): each determination records the resolver algorithm version that produced it; epochs describe catalog state only.
- **External writes:** prohibited by contract. A hash mismatch not produced by a governed mutation marks the epoch **`compromised`** — never a silently opened new epoch pretending to know the real change date. Determinations already emitted under a compromised epoch are flagged in an operator-visible report; resolution over a compromised epoch fails loud (override path available).
- **Race closure (resolution ↔ mutation):** the resolver takes a **shared lock on the active epoch row** for the duration of the determination write transaction; a governed catalog mutation takes an exclusive lock to close it. Locking (not merely optimistic re-reading) serializes the race — a mutation and a determination cannot interleave (Codex finding 16).
- **Operating constraint (documented, until the catalog-versioning ticket):** the catalog must reflect ONLY currently-effective rules; entering a future rule before its effective date breaks the "intact epoch = applicable law" claim (the epoch would prove stability of a wrong catalog). The upgrade guide and config docs state this explicitly.
- **Honest limits:** the first epoch starts when the mechanism is installed — no retroactive reconstruction; epochs prove system-observed stability, not juridical correctness of the configuration.

### 5.3 `EffectiveTaxRuleResolver` — the dated resolution contract

All tax resolution flows through one resolver. It resolves a **complete rule**, not a nominal rate:

- **Input:** the line's FROZEN fiscal facts — which v7 makes real columns, not aspirations (§7.2): frozen tax classification (`tax_group_id` frozen at freeze, jurisdiction, exemption/reverse-charge flags, applicable fiscal profile reference), `operation_nature`, `price_tax_mode` — plus the accrual date and the aggregate currency (EUR-only in v7, constitution 15).
- **Output:** an immutable determination containing at minimum: applied rule identity/version, the **full component set** (each component: rate identity, rate, base, quota — multi-component by design, finding 11), rounding rule used, requested effective date, resolution moment, epoch revision + hash pinned, resolver algorithm version, **source** (`epoch` | `override`; `temporal` reserved for the catalog-versioning ticket), and override audit metadata when applicable.
- **Fail-loud contract:** a requested date outside the provable interval (per §5.2) fails with a specific exception. The resolver never silently reuses the present catalog for a past or future date.
- **Universality (release-boundary):** provisional proforma estimates and direct invoice creation resolve through this same resolver; `TaxCalculationService`'s live-catalog reads survive nowhere as a public path at v7.0.0 (§6.1, §9).

Phase contract (settled): (1) every tax resolution goes through the dated resolver; (2) the initial implementation resolves automatically only the provable present (the active intact epoch); (3) dates outside it fail explicitly; (4) overrides require an audited decision, never silent; (5) the resulting determination is materialized at the accrual; (6) later conversion copies the determination, never re-resolves; (7) full catalog temporality is designed in its own ticket; (8) that ticket enables automatic historical resolution without changing callers; (9) automatic late registration is NOT declared supported until then.

**Operational gate (measured, not assumed):** override frequency is metered from day one. If late fact registration crossing epoch boundaries turns out frequent, the catalog-versioning ticket ascends to prerequisite.

### 5.4 Tax determinations — separate immutable records, schema-enforced integrity

The accrual materializes a **tax determination** per proforma line (indicative name `tax_determinations` + component rows), produced by the resolver at the accrual date. The frozen commercial line is not touched. At conversion, the invoice line receives the determination's fiscal values (`taxable_amount`, component set into `taxes_applied`, derived net `unit_price`) and stores `tax_determination_id` for provenance.

**Integrity is schema-enforced, not conventional (finding 14):**

- Exactly one ACTIVE (non-superseded) determination per proforma line — unique index over the active dimension.
- `supersedes_determination_id` unique (no branching), self-reference forbidden, cycle prevention in the service.
- A determination is consumable by at most one invoice line (unique on the consuming side).
- Rows are immutable by guard + documented DB posture; currency persisted (constitution 15).

**Re-determination (pre-issuance correction):** while NO issued invoice consumed a determination, an audited re-determination (actor, reason, `supersedes_determination_id`) replaces it — the obligation never disappears (§4.1 guard). A PREPARED invoice whose determination was superseded cannot be issued (`issue()` step 2) — it must be cancelled and re-converted. Post-issuance: the consumed determination is untouchable; corrections go to the rectificative handoff (§5.7).

**Concurrency (finding 15):** facts, determinations and re-determinations serialize on the **proforma row lock as aggregate root** (global lock order §6.8); `issue()` validates "not superseded" while holding both the invoice and proforma locks, so no successor can be inserted concurrently.

### 5.5 Override — an explicit domain operation

Never `rate => 2100` in an options array. The flow is:

1. Larabill recognizes it lacks history for the requested date, or the epoch is compromised, or identity cannot be proven for a late fact → specific exception.
2. An authorized human adopts the fiscal decision.
3. Larabill receives it through an explicit operation that validates its shape and persists actor, reason, documentary source and timestamp.
4. The resulting determination records `source = override` with the audit metadata; the invoice keeps it immutably.

### 5.6 Identity change policy matrix

`FiscalChangeDetector` (post-AID-328: snapshot-based) provides the field-level diff; this matrix — not the retired `force`/`on_changes` options — defines the outcome. **The detector is extended in v7 to cover every field the matrix needs** (issuer legal entity type, issuer ROI as per-matrix severity, customer ROI/EU-VAT registration, customer exemption as blocking-per-phase — today's critical/warning field sets do not match the matrix and are re-specified, finding 9).

| Change detected between freeze and conversion/issuance | Outcome |
|---|---|
| Issuer legal identity (`tax_id`, legal entity) | **BLOCK.** A proforma cannot convert under another issuing entity. Path: supersede under the new issuer config. |
| Issuer name/address (non-critical) | Proceed; invoice snapshot carries current identity; warning recorded. |
| Customer `tax_id` | **BLOCK by default.** Paths: audited decision confirming same legal person, or supersede. |
| Customer country / ROI / EU-VAT registration / exemption status | Affects determination validity. Accrual NOT yet materialized → re-resolution required with audited acknowledgment. Materialized, pre-issuance → audited re-determination (§5.4). Post-issuance → rectificative handoff (§5.7). |
| Customer name/address (non-critical) | Proceed; current snapshot; warning recorded. |

The matrix runs at conversion (§6.2), at determination (§4.4) and again at issuance (§6.3).

### 5.7 Rectificative handoff — minimal but wired in v7

Larabill already ships the rectificative base (`InvoiceSerieType::RECTIFICATIVE`, `rectifies_invoice_id`, original-invoice relations). v7 does NOT redesign rectificatives, but the handoff is concrete, wired and tested — not an abstract sentence:

- Any prohibited post-issuance correction (facts, determinations, identity, amounts) throws a dedicated typed exception (indicative name `PostIssuanceCorrectionException`) that references the issued invoice and requires the rectificative path.
- The handoff declares the data it delivers to the rectificative flow: original invoice reference, offending correction intent, the consumed determination, and the accrual facts involved.
- The handoff is **tested against the existing rectificative capability** (create a RECTIFICATIVE invoice referencing the original) and verified NOT to cancel, reopen or mutate the original.
- When the existing capability cannot represent the concrete correction, larabill fails loud and the specialized ticket (§11: full proforma/accrual-aware rectificative workflow) captures it. Sole ownership + a tested escape hatch — no operational dead end (finding 38, arbitrated).

### 5.8 Fiscal obligation axis and issuance deadline

A per-proforma **fiscal obligation axis** (indicative name `FiscalObligationStatus`; NOT part of `ProformaStatus` — it describes accrual resolution, not the document): `NONE` (no accrual-creating facts) → `PENDING` (facts registered, determination not materialized) → `DETERMINED` | `BLOCKED_PARTIAL_ADVANCE` (§5.1) | `BLOCKED_RESOLUTION` (out-of-epoch/compromised, awaiting override). Blocked states are alarmed and operator-visible.

**Issuance deadline (finding 5):** when the obligation reaches `DETERMINED`, larabill computes and persists `issuance_due_at` per the regional strategy (Spain: RD 1619/2012 art. 11 — B2B before the 16th of the month following accrual; same-day baseline otherwise). Breach does not need a scheduler in v7: an overdue flag is computed, surfaced in an operator report and exposed as a queryable scope — visible, monitorable, never silent.

## 6. Invoice documental lifecycle — conversion, issuance, submission

### 6.1 `InvoiceDocumentStatus` and `FiscalSubmissionStatus`

`PREPARED` does not enter `InvoiceStatus`. A new `InvoiceDocumentStatus` enum backs a new column:

```text
DRAFT ──▶ PREPARED ──issue()──▶ ISSUED   (terminal for this axis)
  │           │
  └───────────┴──▶ CANCELLED   (only BEFORE issuance)
```

- **CANCELLED** only before issuance. An ISSUED invoice is never cancelled: it is rectified (§5.7).
- **Fiscal submission is a separate axis** — **`FiscalSubmissionStatus`** (renamed from "registration": it models the handoff to the sibling integration, whose confirmed outcome it projects): `NOT_REQUIRED` | `PENDING` | `REGISTERED` | `FAILED` | `LEGACY_UNTRACKED`, with an explicit machine (finding 35): `PENDING → REGISTERED` only on reconciled external confirmation (§6.7); `PENDING → FAILED` only on **permanent business rejection** (transient delivery failures stay `PENDING` with attempt mechanics in the outbox); `FAILED → PENDING` via an explicit audited resubmission after correction. `NOT_REQUIRED` is a positive compliance decision; `LEGACY_UNTRACKED` marks pre-v7 rows whose obligation status is unknown (§7.1).
- `SENT`, `PAID`, `OVERDUE` remain delivery/collection legacy in `InvoiceStatus` until consciously separated (out of scope). `InvoiceStatus` stays in v7 for compatibility but stops being authority for the document cycle.
- **`createInvoice()` keeps its call shape, is reimplemented over the new primitives, and is NOT claimed behavior-compatible** (finding 33): internally prepare → determination through the dated resolver → issue, atomically. **Operation facts and nature are REQUIRED explicit input** — larabill never defaults an accrual to the issuance date (that would invent facts, finding 4); a direct sale invoiced at the operation moment simply declares that fact. Observable changes (numbering moment, status semantics, verification timing) are documented in `UPGRADE-7.0.md`.

### 6.2 Conversion (rewritten `convertProformaToInvoice`)

Preconditions: proforma `FROZEN`; obligation `DETERMINED` (active, non-superseded determination; §5.8 blocked states fail typed); all lines share **one** accrual date (§6.6); identity matrix (§5.6) allows proceeding; no prior active conversion (idempotent: while CONVERTING or CONVERTED, the call returns the linked invoice).

Atomic sequence (one DB transaction, locks per §6.8):

1. Lock the proforma; re-validate state and idempotency under the lock (closes D1).
2. Validate identity compatibility (§5.6) against persisted snapshots.
3. Create the final invoice as **PREPARED**: exact copy of the CONTRACTUAL line terms (§4.3) — `contract_unit_price`, `price_tax_mode`, quantity, description, `operation_nature`, `item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata` — with fiscal values (`unit_price` net, `taxable_amount`, `taxes_applied` components, totals) taken from the **tax determination** (§5.4), `tax_determination_id` linked; the conversion path holds no reference to catalog models and receives a resolver double that throws if invoked (§10). Currency copied (constitution 15).
4. Persist the selected target series (`prefix`) resolved through `InvoiceSeriesResolver`, indicated per operation (closes D5). The correlative is NOT consumed yet.
5. Derive `service_date` (operation date, art. 6.1.i) from the materialized accrual per the §6.6 mapping — closes D10 without any boolean.
6. Write `invoices.proforma_id` (canonical link, closes D2) and dual-write `converted_invoice_id` on the proforma (active-conversion mirror).
7. Transition the proforma `FROZEN → CONVERTING`.

**Public signature (finding 34):** a typed command in, a typed result out — indicatively `convert(ConvertProforma $command): ConversionResult` where `ConversionResult` exposes the invoice and any recorded warnings; no `Invoice|array` union survives. Exact naming at plan time; the shape is contractual and gated by the new signature snapshot (§8).

A PREPARED invoice knows its fiscal type, target series, lines, snapshots, currency and `service_date`; it does NOT yet have `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`. It is closed to ordinary modifications even though not fiscally issued.

### 6.3 Issuance — `issue()`

Atomic local sequence (no external API call inside the DB transaction; locks per §6.8):

1. Lock the PREPARED invoice and its proforma (when conversion-born).
2. Validate the tax determination is complete and **not superseded** (§5.4).
3. **Re-run the identity matrix** (§5.6) — a change between preparation and issuance must not issue stale (finding 30).
4. Capture ONE issuance instant; resolve the series (persisted; re-validated) and assign the correlative through `InvoiceNumberingService` **passing that instant** — `fiscal_year`, `invoice_date` and `issued_at` all derive from the same moment, so a midnight cross cannot split them (finding 27). The numbering service **joins the ambient transaction** when one is active — its internal `DB::transaction(..., attempts: 3)` retry is unsafe nested inside a poisoned outer transaction; the deadlock retry wraps the whole `issue()` unit instead (finding 26). Counter scope: issuer, `GLOBAL_SCOPE` sentinel (constitution 11).
5. Set `invoice_date` and `issued_at` from the captured instant.
6. Mark content fiscally immutable — header AND lines, via `saving`/`deleting` model event guards (the current `update()`-only override is bypassable by attribute assignment + `save()`, finding 31 / D8).
7. Validate `issuance_due_at` (§5.8) — an issuance past due proceeds (issuing late is better than not issuing) but records the breach in the audit.
8. Create the durable **outbox** record for mandatory submission (when compliance requires it) and set `fiscal_submission_status = PENDING` (else `NOT_REQUIRED`).
9. Transition the invoice to `ISSUED`; when conversion-born, stamp the proforma `CONVERTING → CONVERTED` in the same transaction.

Failure taxonomy: transient/infrastructure errors roll back and leave the invoice PREPARED (retryable, nothing consumed); domain-invalid discoveries (superseded determination, identity block) are typed exceptions whose remedy is `cancelPreparedInvoice()` + correction + re-conversion (§6.4).

### 6.4 Cancelling a PREPARED conversion invoice — releasing the link

`cancelPreparedInvoice()` (auditable operation):

1. Lock invoice + proforma per §6.8; require `PREPARED` (never ISSUED) and conversion-born linkage.
2. Transition the invoice to `CANCELLED`, **retaining** its `proforma_id` as documentary trace (a cancelled PREPARED row never carried a fiscal number — an aborted preparation, preserved for audit).
3. Transition the proforma `CONVERTING → FROZEN` and clear the `converted_invoice_id` mirror (active-conversion semantics, constitution 10), atomically; the cancellation audit (actor, reason, timestamp) records the released link.
4. The active-link uniqueness (§7.1) counts only non-cancelled documents, so re-conversion is possible; a still-valid determination is reused deterministically.

### 6.5 `convertAndIssue()` — two sequential transactions, resumable

One call, **two sequential local transactions** (finding 29 — the earlier "full rollback before number assignment" wording was incoherent with the state model and is corrected):

- The conversion transaction commits first (proforma CONVERTING, invoice PREPARED).
- The issuance transaction runs second. Failure in issuance leaves **CONVERTING + PREPARED — a legal, resumable state** (retry `issue()`, or `cancelPreparedInvoice()`); it does NOT roll back the conversion.
- Failure inside the conversion transaction leaves nothing converted (single-transaction rollback; proforma stays FROZEN, no counter consumed).
- Failure **after** issuance commits → the invoice stays ISSUED with submission `PENDING`/`FAILED`; issuance is never reverted.
- Idempotency: re-invocation resumes — returns the linked invoice, issuing it if still PREPARED; never a second invoice.

### 6.6 One accrual date per invoice — and the operation-date mapping

`service_date` is a single header column. v7 invariant:

> A convertible proforma groups only lines whose materialized accrual shares one header-level date.

Differing accrual dates across lines → conversion rejected with a typed exception (no min/max/first). Per-line representation is deferred (§11).

**`service_date` semantics are redefined as the RD 1619/2012 art. 6.1.i operation date** (column kept — renaming a stable column buys nothing; comment and docs updated), with an explicit per-rule mapping from the materialized accrual (finding 6 — equality with "accrual date" is not asserted globally, it is derived per rule):

| Nature / fact | `service_date` (operation date) |
|---|---|
| Advance payment (payment before the operation) | payment date (art. 6.1.i second clause) |
| Goods delivery | delivery / putting-at-disposal date |
| One-off service | completion date |
| Successive tract | the agreed chargeability date of the period invoiced (`service_date_from/to` keeps the service period) |

Cases the mapping cannot express fail loud — larabill never writes an approximated operation date.

### 6.7 Boundary contract with `lara-verifactu` (findings 36/37, arbitrated)

Larabill does NOT model the Verifactu chain, registration record, hash or AEAT semantics — those belong to `lara-verifactu` (§2). The handoff contract:

1. `issue()` creates invoice + outbox row locally, in the issuance transaction.
2. The outbox row carries an **immutable payload or a reference to an immutable version/hash** of the issued invoice — what was issued is what gets submitted.
3. A **stable idempotency key** identifies the submission.
4. `lara-verifactu` processes idempotently: a retry with the same key returns the existing registration.
5. If the external submission succeeded but the local process crashed before commit, the retry **reconciles via the same idempotency key** — no duplicate registration, no lost acknowledgment.
6. Chain ordering/serialization belongs to `lara-verifactu`.
7. Larabill stores the external acknowledgment/identifier; it never replicates the chain.
8. The local transition to `REGISTERED` happens only on reconciled confirmation.

**Single authority:** the invoice's `fiscal_submission_status` column is canonical business state; the outbox row holds delivery mechanics only (attempts, last error, next retry, idempotency key). Coordinated updates are transactional; the outbox never models `REGISTERED`/`FAILED` as a second authority.

### 6.8 Global lock order (finding 26)

All multi-row operations acquire locks in ONE order — `proforma row → invoice row → invoice_series_control → epoch row` — no operation acquires them in any other order. Facts/determinations lock the proforma (aggregate root) first; conversion locks proforma then creates the invoice; `issue()`/`cancelPreparedInvoice()` lock proforma then invoice (note: proforma FIRST, even though the invoice is the operation's subject); numbering and epoch locks come last. Deadlock-retry policy wraps whole operation units, never nested sub-transactions.

## 7. Schema and migration program

Every migration ships with its `.php.stub`, `$migrationOrder` entry, manifest entry (`in_base: false`), install-test count bump, and its data program + upgrade-path test in the **same PR** (AID-398, AID-412 gates).

**Upgrade execution model (finding 24):** the FIRST migration of the v7 chain is a read-only **global preflight** validating the data assumptions of the WHOLE program before any v7 mutation (MySQL DDL is non-transactional). Because a read-only check cannot protect against concurrent application writes, the upgrade contract additionally requires: the v7 migration chain runs **under a write gate** (application in maintenance mode or an advisory lock the migrations acquire), each data migration **re-validates its own preconditions** immediately before mutating, and the chain is **resumable** (each migration idempotent, so a failed run re-executes from the failure point). The standard ritual (`composer update` + `larabill:install` + `migrate`, documented to run inside `php artisan down` for this major) stays the only thing the operator runs.

### 7.1 `invoices`

- **`proforma_status`** — nullable tinyint, `ProformaStatus` cast. Coherence: `serie = PROFORMA ⇔ proforma_status IS NOT NULL` (CHECK on MySQL 8 + model guard).
- **Cross-axis coherence invariants (finding 21), CHECK-backed where the engine supports them:** `invoice_document_status` and `fiscal_submission_status` are NULL on proformas and NOT NULL on fiscal rows; `ISSUED ⇒ fiscal_number/series_number/fiscal_year/invoice_date/issued_at NOT NULL`; `PREPARED ⇒ all five NULL`; `CANCELLED` document ⇒ never carries a fiscal number.
- **Backfill precedence (conservative, preflight-gated):** (1) valid conversion link → `CONVERTED`; (2) cancelled → `CANCELLED`; (3) `DRAFT` and mutable → `DRAFT`; (4) immutable or any non-editable legacy state (`PAID`, `SENT`, `OVERDUE`, `PENDING`) → **`LEGACY`** (finding 20 — NOT `FROZEN`: those rows lack freeze-time identity, audited nature and frozen classification; the audited `adopt()` operation promotes them); (5) inconsistencies → fail loud in preflight, with an upgrade report.
- **Preflight detections (abort before any v7 mutation):** `status=CONVERTED` without linked invoice; `converted_invoice_id` pointing to a non-fiscal-invoice row; two proformas pointing at the same invoice; an invoice whose `proforma_id` contradicts the inverse link; **multiple invoices already sharing one `proforma_id`** (the 1:N corruption the current schema permits — finding 23).
- **`proforma_id` canonical:** backfill from `converted_invoice_id` (preflight-verified, never overwriting an existing relation). **Active-link uniqueness — exact DDL (finding 25):** MySQL 8: stored generated column `active_proforma_link` = `IF(invoice_document_status <> CANCELLED AND proforma_id IS NOT NULL, proforma_id, NULL)` + `UNIQUE(active_proforma_link)` (NULLs coexist — cancelled preparations and non-conversion invoices never collide); SQLite (dev suite): `CREATE UNIQUE INDEX ... ON invoices(proforma_id) WHERE invoice_document_status <> CANCELLED AND proforma_id IS NOT NULL`. Document-status NULL semantics cannot slip through: fiscal rows have the column NOT NULL by the coherence invariant above. FK `nullOnDelete` → `restrict`.
- **`converted_invoice_id`:** deprecated mirror of the ACTIVE conversion (constitution 10); dual-write during v7; removed in v8.
- **`supersedes_proforma_id`** — nullable self-FK, `restrict`, `unique`; no self-reference; cycle prevention in the service.
- **`invoice_document_status`** — legacy backfill: all existing fiscal invoices → `ISSUED` (they all carry number and dates). The upgrade report flags "numbered drafts" (legacy `InvoiceStatus::DRAFT` with a fiscal number) explicitly.
- **`fiscal_submission_status`** — backfill: rows with existing verifactu state map from it; all other legacy fiscal invoices → `LEGACY_UNTRACKED` (absence of registration does not prove it was not mandatory); `NOT_REQUIRED` reserved for positive compliance decisions going forward.
- **`currency`** — ISO-4217 char(3), backfill `EUR`, copied proforma→invoice (constitution 15).
- **Nullable fiscal fields for PREPARED:** `fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at` nullable. **`prefix` stays NOT NULL** (persists the selected target series). Unique indexes tolerate NULLs per engine semantics.
- **Obligation axis:** `fiscal_obligation_status` + `issuance_due_at` (§5.8) on proforma rows (placement — column vs side table — fixed at plan time; the axis and its states are contractual).

### 7.2 `invoice_items`

- **`contract_unit_price`** — backfill `:= unit_price`; **`price_tax_mode`** — backfill `tax_exclusive` (§4.3). `unit_price` keeps its single net semantics.
- **`operation_nature`** — nullable on LEGACY-proforma lines (supplied by `adopt()`); on already-issued invoice lines backfilled from `item_type` as a **documentary approximation flagged in the upgrade report** (those lines are never re-determined); on new lines required at freeze.
- **Frozen fiscal classification (finding 7 — the resolver's inputs become real columns):** frozen `tax_group_id`, jurisdiction code, exemption/reverse-charge flags and applicable fiscal profile reference, stamped at `freeze()`; the resolver reads ONLY these frozen values, never the live article/group.
- **`tax_determination_id`** — nullable FK on invoice lines (provenance, §5.4), unique (a determination is consumed once).
- **Immutability guard, with its limit declared:** `saving`/`deleting` model events reject changes when the header state forbids them (lines AND header, §6.3 step 6), and every package public operation respects the guard. Bulk `query()->update()/delete()`, direct SQL and external writes are **outside the contract** — documented explicitly.

### 7.3 New tables (names indicative, fixed at plan time)

- **`billing_economic_facts`** — append-only: proforma FK, fact type (payment/delivery/completion/chargeability), fact date, amount + currency, actor, source, `supersedes_fact_id` (unique), metadata, timestamps.
- **`tax_determinations`** (+ component rows) — immutable: proforma line FK, rule identity/version, per-component (rate identity, rate, base, quota), rounding rule, effective date requested, resolution moment, epoch revision + hash, resolver algorithm version, source (`epoch`/`override`), override audit, `supersedes_determination_id` (unique), currency; unique active-per-line index.
- **`tax_catalog_epochs`** — revision id, `observed_from`, rule-set hash, `closed_at`, integrity (`intact`/`compromised`).
- **`fiscal_submission_outbox`** — invoice FK, immutable payload reference/hash, idempotency key (unique), attempt count, last error, next retry (delivery mechanics only; business state lives on the invoice, §6.7).
- **Successive-tract schedules** — frozen chargeability schedule rows per `SUCCESSIVE_TRACT` line (§5.1).

## 8. Public surface and SemVer

- **v7.0.0 major.** Qualified imperative: the D1–D10 forensic table (§1). Complies with `STABILITY.md`.
- **`convertProformaToInvoice()` is rewritten in place** with the new contract and a **specified typed signature** (§6.2): command object in, `ConversionResult` out — the `array $options` / `Invoice|array` union dies. No deprecation of the defective semantics; `UPGRADE-7.0.md` explains every new precondition; the CHANGELOG declares old and new behavior in bold; each missing precondition fails with a specific typed exception naming the step (mechanical call-site identification).
- **`createInvoice()` keeps its call shape, is reimplemented, and is documented as NOT behavior-compatible** (finding 33): facts/nature become required input; numbering/status/verification timing changes listed in `UPGRADE-7.0.md`.
- **New `@api` operations** (typed commands/results): `freezeProforma()`, `adopt()` (legacy promotion), `supersedeProforma()`, `cancelProforma()`, `cancelPreparedInvoice()`, economic-fact registration, the audited override, the audited re-determination, `issue()`, `convertAndIssue()`, the audited resubmission (`FAILED → PENDING`).
- **New enums:** `ProformaStatus`, `InvoiceDocumentStatus`, `FiscalSubmissionStatus`, `FiscalObligationStatus`, `OperationNature` (closed, constitution 14), `PriceTaxMode`. `InvoiceStatus` unchanged; legacy `force`/`on_changes` retired.
- **Contract gates extended:** AID-412 model snapshots regenerate via `bin/sync-contract-snapshots` with their CHANGELOG gate, **plus a new snapshot gate covering public signatures of services, command/result objects and exceptions** — model snapshots alone do not cover the surface this redesign changes.

## 9. Delivery phases (ordered PRs inside the v7 epic)

Foundations first. **Constitution invariants bind the v7.0.0 release boundary; each PR leaves the runtime coherent with the new schema via transitional wiring (constitution 16), and the PR list below names its own transitional exceptions explicitly (finding 32).**

1. **PR-1 — Schema foundations + transitional coherence:** global preflight migration + write-gate contract; `ProformaStatus` (incl. `LEGACY`), `InvoiceDocumentStatus`, `FiscalSubmissionStatus`, obligation axis columns, `currency`, `contract_unit_price` + `price_tax_mode`, `operation_nature`, frozen fiscal classification columns, canonical links (active-unique DDL, `supersedes_proforma_id`), nullable fiscal fields, all backfills; header + line immutability guards (`saving`/`deleting`). Transitional wiring: creating hooks stamp the new state columns coherently under current behavior; the OLD conversion gains `lockForUpdate()` and writes `proforma_id` (additive D1/D2 fixes, no semantic change). `UPGRADE-7.0.md` is born here.
2. **PR-2 — Documental lifecycle + tax truth:** freeze/adopt/supersede/cancel with guards, economic facts (+ corrections, partial-payment blocking, currency guards), obligation axis + issuance deadline, epochs, resolver, determinations (+ re-determination, integrity constraints), audited override, identity matrix + detector extension. **Transitional exception (explicit):** old conversion and `createInvoice()` still ride `TaxCalculationService` until PR-3 — constitution 4 is a release-boundary invariant, and this window is named here, not discovered later.
3. **PR-3 — Conversion + issuance + boundary:** rewritten conversion (typed command, PREPARED, CONVERTING), `issue()` (identity re-check, single instant, ambient-transaction numbering), `cancelPreparedInvoice()`, `convertAndIssue()` (two-transaction model), outbox + submission axis + lara-verifactu boundary contract, rectificative handoff wired + tested, `createInvoice()` reimplementation, `service_date` mapping, concurrency hardening under the global lock order. The FROZEN/determination preconditions activate here, atomically with the rewrite; `TaxCalculationService` live reads end here.
4. **PR-4 — Release closure:** CHANGELOG promotion, manifest re-stamp, `UPGRADE-7.0.md` consolidation, tag via `bin/tag-release`.

**Documentation travels in every PR:** `[Unreleased]` CHANGELOG entry, upgrade path, regenerated snapshots on surface change, timestamped migration + stub + `$migrationOrder`, consistency/manifest tests. PR-4 only consolidates.

## 10. Testing contract

The conversion contract moves from 0 tests to a mandatory suite:

- **State machines:** every legal transition and guard for `ProformaStatus` (incl. `LEGACY → adopt() → FROZEN`, `CONVERTING → FROZEN` on cancelled preparation with determination reuse), `InvoiceDocumentStatus`, `FiscalSubmissionStatus` (transient stays PENDING; permanent → FAILED; audited resubmission) and `FiscalObligationStatus`; cancel/supersede after ANY accrual-creating fact → typed exception (guard at fact, not determination); `CANCELLED` unreachable from `ISSUED`.
- **Backfills (upgrade-path, MySQL):** ambiguous data → global preflight fails before any v7 mutation, including the **multi-invoice-per-proforma** corruption; cancelled+immutable → `CANCELLED`; non-editable legacy proformas → `LEGACY`, never `FROZEN`/`DRAFT`; numbered legacy invoices → `ISSUED`; missing verifactu state → `LEGACY_UNTRACKED`; `contract_unit_price`/`price_tax_mode`/`currency` backfills; link backfill never overwrites; fresh install AND real upgrade-from-v6 under the write gate; chain resumability (kill mid-chain, re-run).
- **Facts and obligations:** partial payment fact IS registered, protects the proforma, sets `BLOCKED_PARTIAL_ADVANCE`, raises the alarm and blocks determination/conversion with the typed 1:N exception; currency mismatch rejected; fact/nature inconsistency rejected; successive tract without frozen schedule rejected; art. 75.Uno.7.º fallback (no exigibility / >12 months → Dec 31 proportional) produces the expected accrual dates and multi-date schedules block conversion; `issuance_due_at` computed per art. 11 and overdue surfaced.
- **Determinations:** unique active per line; unique `supersedes_determination_id`; single consumption; re-determination pre-issuance blocks `issue()` of a stale PREPARED; post-issuance correction → `PostIssuanceCorrectionException` carrying the declared handoff data, and the handoff **creates a RECTIFICATIVE invoice against the original without cancelling/reopening/mutating it**.
- **Conversion:** contractual-terms copy exactness (dataset over every column, `contract_unit_price` included); fiscal values sourced from the determination; the conversion path receives a **resolver/catalog double that throws if invoked**; idempotency; `proforma_id` written; per-operation series honored; `service_date` mapping per nature (advance/delivery/completion/chargeability); differing accrual dates rejected.
- **Price semantics:** `tax_exclusive` preserves the contractual net; `tax_inclusive` preserves the bound gross and redistributes over the FULL component set (multi-component dataset incl. recargo de equivalencia) honoring the §4.3 invariants — property test: base + Σ quotas == gross exactly AND each quota derives from displayed base × rate under documented rounding, fallback recorded in metadata when triggered; determination never mutates the frozen line; invoice `unit_price` stays net.
- **Resolver/epochs:** in-epoch resolution; out-of-epoch → specific exception; override audited; governed mutation closes the epoch transactionally; hash mismatch → `compromised` + resolution fails loud + affected determinations reported; shared-lock serialization (mutation racing a determination cannot interleave — fork test).
- **Concurrency (fork pattern AID-390, `RUN_CONCURRENCY_IT=1` + MySQL):** two concurrent conversions → one CONVERTING link, loser gets the winner's invoice; two concurrent `issue()` → single number; lock-order compliance (no deadlock across convert/issue/cancel/facts interleavings); midnight-cross test: `fiscal_year` == year of `invoice_date` always. The sensitivity check against the pre-fix implementation is a **one-off mutation test during development**, not a CI gate.
- **Boundary (lara-verifactu):** external-success/local-crash → retry reconciles via idempotency key, no duplicate registration; transient failure leaves PENDING + outbox mechanics; permanent rejection → FAILED; `REGISTERED` only on reconciled confirmation; fiscal number never reverted/reused.
- **Immutability limit:** no package public operation mutates issued headers/lines (incl. the attribute-assignment + `save()` bypass — closed); bulk/query and external SQL documented as outside the guarantee.

## 11. Out of scope — follow-up tickets

- **Partial advances (1:N)** — **HIGH priority; prerequisite for any consumer that can accept partial payments** (§5.1). v7 records the facts and blocks honestly; the 1:N ticket delivers per-payment advance invoices, allocation across lines/taxes and final regularization. The move to 1:N is a **designed expansion point, not a free one** (active-unique index, mirror removal, `CONVERTED` semantics and the whole-payment guard all encode 1:1 and are the ticket's surgery list).
- **Full temporal catalog versioning** (§5.2/§5.3 limits; ascends to prerequisite if override metrics demand it).
- **Real multi-currency support** (currency is persisted, resolver is EUR-only; exchange-rate-at-accrual, conversion rules and quota representation are that ticket's scope).
- **Per-line accrual representation** (multi-date invoices, §6.6).
- **Full proforma/accrual-aware rectificative workflow** (the tested minimal handoff ships in v7, §5.7).
- **Separating delivery/collection axes** (`SENT`/`PAID`/`OVERDUE`) out of `InvoiceStatus`.
- **DB-level line immutability enforcement** beyond the model guards.

## 12. Consumer impact and migration (the only section where consumers appear)

- Consumers currently orchestrating conversion locally (e.g. the Vía 1 flow in `clientes`) are **blocked from extending** that duplication and must migrate to the v7 primitives once released, then delete their local conversion/freeze domain. Any consumer that can accept partial payments must treat the 1:N ticket as its prerequisite (§11).
- Existing OSS consumers of `convertProformaToInvoice()`/`createInvoice()` face new required inputs and preconditions: `UPGRADE-7.0.md` documents the mechanical mapping (freeze first; register facts; supply nature; handle the typed exceptions) and the documented behavior changes of `createInvoice()`.
- The upgrade ritual: `composer update` + idempotent `larabill:install` re-run + `php artisan migrate`, executed under the documented write gate (`php artisan down`), with the global preflight aborting loudly on ambiguous data before any v7 mutation.

## 13. AID-444 disposition

AID-444 is **closed as superseded by design**:

- Its premise ("the conversion flow does not exist, greenfield") is false — the flow exists as public surface since before v1.0 (§1).
- Its central mechanism (`proforma.align_service_date_on_conversion` boolean) models an issuer convenience, not a fiscal fact, and can write a false date. RD 1619/2012 art. 6.1.i requires the operation date or the advance-payment date **when it differs from the issuance date**; the accrual belongs to the facts (art. 75 LIVA), and the documentary date represents it — it never creates or displaces it.
- The UX warning it mandated is therefore also incorrect and is dropped.
- `service_date` in v7 derives from the materialized accrual per the §6.6 mapping. The advance-paid-June-30-converted-July-2 case yields `service_date = 2026-06-30` — the truth AID-444's default would have falsified.
- New Linear tickets are cut from this spec (the v7 epic and the §11 follow-ups); AID-442's presentation layer (shipped in v6.3.0) becomes correct automatically once `service_date` carries real facts.
