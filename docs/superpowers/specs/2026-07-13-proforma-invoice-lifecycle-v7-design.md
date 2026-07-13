# Proforma → Invoice Lifecycle Redesign (v7) — Design

- **Date:** 2026-07-13 (rev. 5 — Codex round 3: rounds 1–2 fully re-verified RESOLVED except three declared accepted limitations (midnight catalog window pending temporal versioning ×2, rectificative residual with ticket exit); new findings 71–81 incorporated)
- **Status:** Draft — pending Codex round 4 confirmation, then implementation planning
- **Supersedes:** AID-444 (rejected as specified — see §13), reframes AID-442's data layer
- **Related:** ADR-001 (fiscal config freezing), ADR-003 (user/customer unification), AID-307 (fiscal series vs type), AID-328 (snapshot vs live-row comparison), AID-390 (numbering owner), `STABILITY.md`
- **Target release:** v7.0.0 (major — qualified imperative documented in §1)
- **Plan deliverables (explicitly deferred, not gaps):** exact DDL literals, physical types/collations, index/constraint names and `down()` strategies for every new index (incl. the active-unique generated columns, whose TECHNIQUE is fixed here); final class/command/result naming (shapes are contractual here, names gated by the §8 signature snapshot); the canonical serialization of the epoch hash is fixed here (§5.2), its implementation is plan work.

## 1. Motivation — forensic findings on the current conversion

`InvoiceService::convertProformaToInvoice()` exists since before v1.0, is public `@api` surface (`docs/api-surface.md`), and has **zero tests**. A forensic review (2026-07-13) confirmed the following defects, each independently a fiscal-correctness or integrity problem:

| # | Defect | Evidence |
|---|--------|----------|
| D1 | No row locking: `refresh()` instead of `lockForUpdate()`; the idempotency check is TOCTOU — two concurrent conversions can produce two final invoices | `src/Services/InvoiceService.php:346,353` |
| D2 | The canonical link is never written: `invoices.proforma_id` exists and `convertedInvoices(): HasMany` reads it, but conversion only writes `converted_invoice_id`; the bidirectional link is broken from birth | `src/Models/Invoice.php:397`, `InvoiceService.php:409-415` |
| D3 | Line reconstruction copies only 4 fields; it discards `item_type`, `internal_code`, `unit_measure_id`, `service_date_from/to`, `metadata`, and the `taxes_applied` snapshot | `InvoiceService.php:391-396` |
| D4 | Taxes are RECALCULATED against the live catalog — the amounts the customer saw on the proforma can silently change | `src/Services/TaxCalculationService.php:98-103` |
| D5 | No target fiscal series can be selected at conversion, although `createInvoice()` supports explicit series | `InvoiceService.php:388-402` |
| D6 | Conversion and issuance are collapsed (invoice born `PENDING` + immutable + numbered + dated in one step); fiscal registration is an optional afterthought with no owned state | `InvoiceService.php:398,405` |
| D7 | Conversion does not require the proforma to be frozen or paid; any `serie=PROFORMA` row converts | `InvoiceService.php:348` |
| D8 | `Invoice` immutability does not protect `InvoiceItem` (no line guard), and the header guard only overrides `update()` — attribute assignment + `save()` bypasses it | `src/Models/InvoiceItem.php`, `Invoice.php:255,279` |
| D9 | Contradictory cardinality: `converted_invoice_id` (scalar) coexists with `proforma_id` + `convertedInvoices()` (1:N); the database enforces neither | `Invoice.php:79,397`, migration `2024_12_01_000003:45` |
| D10 | `service_date` is populated by no service — nullable, only the factory writes it (randomly); the operation-date row (AID-442) fires only by accident | grep across `src/` |

A green build does not cover the conversion contract. These defects are the **qualified, documented imperative** that `STABILITY.md` requires for a breaking release.

## 2. Domain ownership and boundary rules

- **Larabill owns:** proforma lifecycle, freezing of lines and amounts, conversion, fiscal numbering, tax determination and its persistence, the traceable proforma→invoice link, fiscal immutability, fiscal obligations and issuance deadlines, and the rules around operation date, advance payment and issuance.
- **Consumers own:** when to invoke those operations, their UX, and their commercial domain (acceptance, payment gateways, orders).
- **Consumers may NOT:** clone lines, recalculate or copy taxes, assign fiscal numbers, or implement an alternative conversion. A consumer-side port is legitimate only when it adapts a larabill primitive.
- **`lara-verifactu` owns (sibling package):** the Verifactu chain, the registration record and its hash, immutable submission/annulment records, AEAT semantics, chain ordering, and the **typed classification of submission outcomes** (§6.7). Larabill owns the issued immutable invoice, the durable handoff intent, the observable submission state and the acknowledgment correlation.

**Operative blocking rule:** if a consumer needs to write directly into `invoices`, `invoice_items`, snapshots, totals, series or conversion links, the consumer work is BLOCKED and a capability-gap ticket is opened in larabill first. No local fallback.

Domain authority belongs to larabill; real consumer usage only informs SemVer/migration risk (§12 is the only section where consumers appear).

## 3. Settled design decisions (the constitution — release-boundary invariants of v7.0.0)

Individual epic PRs are transitional and name their exceptions (§9).

1. Larabill owns the full proforma and conversion domain (§2); the Verifactu registration record and chain belong to `lara-verifactu` behind an **executable interface + composer version constraint** (§6.7).
2. AID-444 is not implemented as specified; superseded by this design (§13).
3. A frozen proforma freezes its **commercial contract**: `contract_unit_price`, `contract_line_total` and `price_tax_mode` per line, distinct from the fiscal net `unit_price`, whose semantics never change; arithmetic reconciliation is explicit via `unit_price_base_adjustment` (§4.3).
4. At the v7.0.0 release boundary, no tax computation anywhere in the package consults the live catalog outside the dated resolver (§5.3, §6.1).
5. Tax truth is fixed by **materialized accrual determinations**: larabill derives accruals from registered economic facts, the frozen contractual nature and the frozen chargeability schedule — the consumer never delivers a resolved accrual, and the schedule is the SOLE authority for chargeability (§5.1).
6. **Fiscal obligations are rows, not a scalar** (§5.8): one row per concrete accrual, idempotently created, each with amount, currency, accrual date, deadline, determination and state. Cardinality of automatic conversion stays **strict 1:1**: it requires the complete `DETERMINED` obligation set sharing one accrual date and jointly covering the whole contract (coverage classified AFTER determination, §5.1); partial payments always create their recorded, deadline-bearing, blocked obligation — never denied.
7. `service_date` carries the **operation date of RD 1619/2012 art. 6.1.i**, derived per rule from the materialized accrual (§6.6), never a configurable preference.
8. Conversion, issuance and fiscal submission are **distinct transitions** on distinct state axes (§6).
9. Conversion is atomic, locked and idempotent, backed by database constraints, under the global lock order `proforma → invoice → epoch → series control` (§6.8).
10. `proforma_id` is canonical; `converted_invoice_id` is a deprecated mirror of the ACTIVE conversion only, dual-written in v7, removed in v8.
11. The target fiscal series is resolved inside larabill and can be indicated per operation; counter scope is the ISSUER (`GLOBAL_SCOPE` sentinel, AID-390).
12. Evolution of a public API, not greenfield: every schema/data change ships its upgrade program in the same PR (AID-398).
13. Typed command objects in, typed results out (§8); no `$options` arrays, no `legacy_recalculate`, legacy `force`/`on_changes` retired.
14. **Contractual nature is a closed enum owned by larabill** (`GOODS_DELIVERY` | `ONE_OFF_SERVICE` | `SUCCESSIVE_TRACT`); an advance is a payment FACT, never a nature. Extension = minor release; consumers never register nature classes; human judgment uses the audited decision operation.
15. **Currency is explicit:** ISO-4217 on proformas/invoices/facts/obligations/determinations (backfill `EUR` with operator attestation in the upgrade report), equality validated across the aggregate; the v7 resolver admits only `EUR` and fails loud otherwise.
16. Every PR leaves the runtime **coherent with the new schema** (transitional wiring); "each PR green" is necessary but not sufficient (§9).
17. Consumer references appear only in §12.

## 4. Proforma documental lifecycle

### 4.1 `ProformaStatus`

```text
DRAFT ──freeze()──▶ FROZEN ──convert()──▶ CONVERTING ──(linked invoice ISSUED)──▶ CONVERTED  (terminal)
  │                    │  ▲                    │
  │                    │  └──(prepared invoice cancelled)──┘
  │                    ├──supersede()──▶ SUPERSEDED  (terminal)
  │                    └──cancel()─────▶ CANCELLED   (terminal)
  └──cancel()──▶ CANCELLED (terminal)

LEGACY ──adopt() (audited)──▶ FROZEN        (migration-only entry state, §7.1)
```

- **DRAFT:** editable; not convertible (closes D7); identity snapshots provisional (§4.4).
- **FROZEN:** commercial content immutable (§4.3); the only state from which conversion is legal.
- **CONVERTING** (non-terminal): a PREPARED invoice exists and is linked; no direct supersede/cancel — exits are issuance (→ CONVERTED) or `cancelPreparedInvoice()` (→ FROZEN, §6.4).
- **CONVERTED:** terminal; the linked invoice was issued; stamped by `issue()` in the same transaction.
- **SUPERSEDED / CANCELLED:** terminal; supersession is atomic and single-successor (§4.2).
- **LEGACY:** migration-only state for pre-v7 immutable proformas lacking freeze-time identity, audited nature, frozen classification and known fact history. A LEGACY proforma cannot determine or convert. **`adopt()` (audited) atomically requires either an attested declaration of "no pre-v7 economic facts" or the imported list of historical facts (each audited), supplies the missing frozen data, computes the obligation rows and deadlines immediately, and transitions to FROZEN** — a legacy proforma with a real historical payment cannot be adopted "clean" and then cancelled (Codex 46/47). Until adoption its obligation projection is `UNKNOWN`, never `CLEAR` (Codex 70).

**Guards:**

- **Protection triggers at FACT registration:** from the first EFFECTIVE accrual-creating fact, plain `cancel()` is forbidden entirely and plain `supersede()` is forbidden. What remains available is **guarded supersession** (audited): it TRANSFERS the effective facts and born obligations to the successor with a full audit trail — obligations never disappear, they move with the contract. Guarded supersession is the vehicle for legitimate post-fact commercial modifications (schedule changes before a chargeability date, the audited absorption of a rate difference, §5.1) — so the remedies this spec prescribes are reachable exactly when needed (Codex 76).
- **Fact correction is unified (Codex 77):** a replacement fact with `supersedes_fact_id` (audited) exists BOTH before and after an obligation was born, as long as no determination was consumed by an ISSUED invoice; it voids/recomputes the derived obligations under the proforma aggregate lock, and if NO effective facts remain the protection lifts — auditable, never silent (Codex 45). After an issued invoice consumed the truth: re-determination is closed and everything routes to the rectificative handoff (§5.7).
- Transitions are executed and validated by larabill; consumers only request them.

**Legacy `InvoiceStatus` projection:** the old `status` column is frozen at migration time as a historical, non-authoritative fossil for proformas; larabill never writes new proforma states into it. Documented in `UPGRADE-7.0.md` and `docs/api-surface.md`.

### 4.2 Supersession — a single FK

Successor carries `supersedes_proforma_id` (self-FK, `restrict`, `unique`); inverse is a query. Self-reference forbidden; cycle prevention in the service; both sides proformas.

### 4.3 What FROZEN freezes — commercial truth, fiscal representation, explicit reconciliation

- **`contract_unit_price`** + **`contract_line_total`** + **`price_tax_mode`** (`tax_exclusive` net | `tax_inclusive` gross) — the frozen commercial contract per line. The BINDING amount is `contract_line_total` (line level — unit×quantity residue never decides the contract, Codex 48). Backfill: `contract_unit_price := unit_price`, `contract_line_total := taxable_amount` (exclusive) and `price_tax_mode := tax_exclusive`; preflight detects historical lines where `taxable_amount ≠ round(quantity × unit_price)` and reports them with the declared precedence: **the line total wins** (Codex 49).
- **`unit_price`** keeps ONE semantics: the fiscal **net** unit price, scale 2. For exclusive lines it is contractual input; for inclusive lines it is derived from the determination.
- **Arithmetic reconciliation is explicit, never a false equality (operator arbitration D3):**

  ```text
  taxable_amount = round(quantity × unit_price) + unit_price_base_adjustment
  ```

  The determination's `taxable_amount` is canonical. `unit_price_base_adjustment` (signed, persisted per line) is exactly the reconciliation the scale-2 derived unit price cannot express — it may exceed one cent for large quantities and is then NOT called "rounding"; PDF/API present it coherently as a base adjustment. For `tax_exclusive` lines it is normally zero (non-zero historical cases surface in preflight). For `tax_inclusive` lines the promise is that the full equation reproduces the base — never the unit price alone.
- **Multi-component redistribution (`tax_inclusive`):** operates over the FULL component set of the determination. **v7 closed composition algebra: additive components over the same base** (VAT + recargo de equivalencia); withholdings, cascading or different-base compositions fail loud with their own ticket (Codex 52). Binding invariants: (a) net base + Σ component quotas == bound gross, exactly, per line; (b) each displayed quota derives from displayed base × displayed rate under the documented rounding, **except for at most one explicit, persisted rounding-adjustment field of at most one cent on one component** — the adjustment is part of the determination record and of the displayed breakdown, so invariant (b) is formally relaxed, not silently violated (Codex 50/12); (c) deterministic; (d) per line.
- **`taxes_applied` semantics by document type:** provisional estimate on proforma lines (all legacy proforma lines provisional by definition); definitive determination copy on invoice lines with `tax_determination_id` provenance.

The frozen commercial line is never mutated to turn an estimate into fiscal truth.

### 4.4 Identity — provisional at DRAFT, frozen at freeze(), validated at determination and issuance

- DRAFT snapshots provisional; `freeze()` re-resolves and freezes document identity.
- The identity matrix (§5.6) runs at determination AND again inside `issue()`.
- **v7 has NO historical identity provider:** identity for late-registered facts is validated against persisted snapshots + current state; where larabill cannot prove, the audited decision path applies — stated as a limit, not a promise (Codex 8).
- The final invoice carries its own fiscal snapshot; the proforma stays intact as commercial origin.

## 5. Tax resolution — facts, obligations, epochs, resolver, determinations

### 5.1 Economic facts, contractual nature, payment coverage

Consumers register **economic facts** (append-only; indicative `billing_economic_facts`): payment received (date, amount, currency), goods delivered (date), service completed (date). Facts carry actor, source, timestamp and a **unique `source_event_key`** — required for machine-registered facts, so a retried payment webhook returns the EXISTING fact instead of minting a duplicate fact-and-obligation (registration itself is idempotent, Codex 73). Each fact belongs to exactly ONE billing aggregate — a proforma OR a direct invoice (Codex 71; §6.1). Facts are **document-scoped in v7** with line scope schema-ready: scenarios that genuinely need per-line mapping (mixed or partial deliveries across lines) fail typed naming the per-line accrual ticket (Codex 72). **Facts are historical truth: never rejected for being inconvenient, never deleted.** Correction: per the unified §4.1 rule (`supersedes_fact_id`, audited, pre- AND post-obligation while unconsumed by an issued invoice; afterwards, rectificative handoff).

**Chargeability has ONE authority (operator arbitration D4):** the frozen chargeability schedule of `SUCCESSIVE_TRACT` lines. There is NO "chargeability reached" external fact. Larabill evaluates the schedule with its fiscal clock, **materializes the obligation idempotently when each date is reached** (catch-up capable if evaluation did not run that day), applies the art. 75.Uno.7.º fallback (no agreed exigibility, or exigibility beyond 12 months → 31 December accrual of the proportional part, computed by day-count with HalfUp rounding), and records when it materialized — a consequence, never a second authority. Contract changes before a chargeability date require superseding the proforma (the schedule is frozen content); after a date is reached, no rewrite removes the born obligation. An optional external confirmation may exist as evidence only; discrepancy raises an alarm, never overrides the schedule.

**Nature validation:** each line's `operation_nature` is frozen at `freeze()`; larabill validates fact/nature consistency (a delivery fact against a `SUCCESSIVE_TRACT`-only proforma is inconsistent input — distinct from rejecting a true fact) and maps nature + facts to the accrual rule (art. 75; region strategies in-package): payment before the operation → accrual on the collected amount at payment date (art. 75.Dos); goods → delivery; one-off service → completion; successive tract → schedule.

**Payment coverage is classified AFTER determination — never against the provisional gross (operator arbitration D2):**

1. Register the payment fact (always).
2. Resolve the effective rule at the payment date (resolver, §5.3; out-of-epoch → the obligation is born `BLOCKED_RESOLUTION`).
3. Compute the **effective contractual gross**: `tax_inclusive` → Σ `contract_line_total` (gross is the contract); `tax_exclusive` → contractual base + all components determined at the accrual date.
4. Compare cumulative effective payments against that gross.
5. Classify **full / partial / overpayment**.

**Overpayment (Codex 79):** the contract-covering obligation is `DETERMINED` and conversion proceeds; the EXCESS is recorded as an **unallocated payment surplus** — visible and alarmed, never silently treated as consideration. Its resolution (refund, credit) is the consumer's commercial domain; any credit-note issuance belongs to the rectificative/1:N domain. Larabill records the surplus; it never invoices money not linked to consideration.

The frozen provisional gross is the collection-request tool — evidence that the customer paid what was asked — never authority that the contract was fiscally satisfied. Canonical scenario (rate rise, `tax_exclusive`, customer paid the old provisional gross): the fact is kept; the obligation for the received amount is born; the remainder is a recorded pending commercial difference; 1:1 conversion is blocked (no full payment under the exclusive contract); if the issuer absorbs the difference, that is an **auditable commercial modification** (supersession path), never a retroactive reinterpretation of the mode.

**Partial payments:** always recorded; each creates its obligation row (§5.8) in `BLOCKED_PARTIAL_ADVANCE` — **with its own legal issuance deadline** (the VAT accrued; the technical block does not remove the clock, Codex 43) — alarmed and operator-visible. Larabill invents no allocation across lines/taxes (1:N domain). Determination-for-conversion and conversion over a proforma with blocked obligations fail typed, naming the 1:N ticket (**HIGH; prerequisite for any consumer that can accept partial payments**).

**Multi-period successive tract (Codex 75):** a proforma whose schedule has reached MORE than one chargeability date holds several obligations that strict 1:1 conversion cannot document in one invoice. v7 converts a successive-tract proforma only while the reached obligations share ONE accrual date (§5.8); further periods belong to recurring invoicing (existing package capability) or the per-line/1:N tickets — blocked typed, named, and deadline-visible, never invisible.

**Currency:** fact amounts carry ISO-4217 and must equal the proforma's currency (constitution 15).

### 5.2 Catalog epochs — the provable interval

Timestamp inference is REJECTED as proof (deletes destroy evidence; pivots have no history; imports bypass timestamps; code changes never appear in tables).

**Explicit catalog epochs** (indicative `tax_catalog_epochs`):

- **Fields:** revision id, `observed_from`, rule-set hash, `closed_at`, integrity (`intact` | `compromised`), optional `declared_effective_at` annotation on the closing mutation (for the report).
- **Canonical hash serialization (fixed here):** SHA-256 over a JSON document with deterministic ordering — rule rows (`tax_rates`, `tax_groups`, pivot, special conditions) sorted by primary key, fields in fixed declared order, no whitespace variance (Codex 17).
- **Single active epoch is schema-enforced:** exactly one row with `closed_at IS NULL` (generated-column unique on MySQL 8 / partial unique on SQLite — same technique as §7.1); the mechanism's install migration bootstraps the first epoch; zero-or-two-active is detected and repaired by a documented recovery operation (Codex 54).
- **Governed closure:** catalog mutations through larabill close the active epoch in the SAME transaction and open the next.
- **Resolver algorithm version is pinned per DETERMINATION** (rolling deployments make per-epoch code versions non-atomic).
- **External writes:** prohibited by contract; a hash mismatch not caused by a governed mutation marks the epoch `compromised` (never a silently opened epoch). The compromised-marking path **releases its shared lock and acquires the exclusive lock with retry** (no shared→exclusive upgrade deadlock, Codex 55). Determinations under a compromised epoch are reported; resolution over it fails loud (override available).
- **Race closure:** the resolver holds a **shared lock on the active epoch row** for the determination write transaction; governed mutations take the exclusive lock.
- **Operating constraint (documented + mitigated):** the catalog must reflect only currently-effective rules. The midnight window is real and unsolvable without effective-dating: governed mutations SHOULD execute at effect time, MAY be annotated with `declared_effective_at` for the audit report, and determinations resolved in a wrong window are correctable via audited re-determination (pre-issuance) or the rectificative handoff (post) — the honest remedy until the catalog-versioning ticket (Codex 53/18).
- **Honest limits:** first epoch starts at install; epochs prove system-observed stability, not juridical correctness.

### 5.3 `EffectiveTaxRuleResolver` — the dated resolution contract

- **Input:** the line's FROZEN fiscal facts — real columns (§7.2): frozen `tax_group_id`, jurisdiction, exemption/reverse-charge flags, fiscal profile reference — plus `operation_nature`, `price_tax_mode`, the accrual date, and the aggregate currency (EUR-only in v7).
- **Output:** an immutable determination: rule identity/version, full component set (per component: rate identity, rate, base, quota), the explicit rounding-adjustment field when used (§4.3), rounding rule, requested effective date, resolution moment, epoch revision + hash, resolver algorithm version, source (`epoch` | `override`), override audit metadata.
- **Fail-loud:** dates outside the provable interval fail with a specific exception; the present catalog is never silently reused for past/future dates.
- **Universality (release boundary):** provisional proforma estimates and direct invoice creation resolve through this resolver; `TaxCalculationService` live reads survive nowhere public at v7.0.0.

Phase contract: (1) all resolution through the dated resolver; (2) initial implementation resolves only the provable present; (3) outside → explicit failure; (4) overrides audited, never silent; (5) determinations materialize at the accrual; (6) conversion copies, never re-resolves; (7) full catalog temporality in its own ticket; (8) that ticket extends resolution without changing callers; (9) automatic late registration not declared supported until then. **Override frequency is metered; if crossing-epoch late facts are frequent, the versioning ticket ascends to prerequisite.**

### 5.4 Tax determinations — immutable, schema-enforced, correctable pre-issuance

One determination set per obligation (per affected line), produced by the resolver at the accrual date. The frozen commercial line is untouched; the invoice line receives the determination's fiscal values and `tax_determination_id`.

- **Integrity (schema-enforced; active-unique uses the §7.1 generated-column technique — plan fixes literals):** one ACTIVE determination per line per obligation; `supersedes_determination_id` unique; no self-reference/cycles; consumable by at most one invoice line (unique on the consuming side); rows immutable by guard; currency persisted.
- **Re-determination (pre-issuance):** audited replacement while no issued invoice consumed it; a PREPARED invoice whose determination was superseded cannot issue — cancel + re-convert.
- **Post-issuance:** untouchable; corrections via the rectificative handoff (§5.7).
- **Concurrency:** facts, obligations, determinations and re-determinations serialize on the proforma row lock (aggregate root, §6.8); `issue()` re-validates under invoice+proforma locks.

### 5.5 Override — an explicit domain operation

1. Larabill recognizes it cannot prove (date outside epoch, compromised epoch, unprovable identity) → specific exception. 2. An authorized human decides. 3. Larabill receives the decision through an explicit operation persisting actor, reason, documentary source, timestamp. 4. The determination records `source = override`.

### 5.6 Identity change policy matrix

`FiscalChangeDetector` (snapshot-based, AID-328) provides the diff; the matrix defines outcomes; the detector is **extended in v7** to cover every matrix field (issuer legal entity, issuer ROI, customer ROI/EU-VAT, customer exemption — today's critical/warning sets are re-specified).

| Change between freeze and conversion/issuance | Outcome |
|---|---|
| Issuer legal identity (`tax_id`, legal entity) | **BLOCK**; path: supersede under the new issuer config. |
| Issuer name/address | Proceed; current snapshot; warning recorded. |
| Customer `tax_id` | **BLOCK by default**; audited same-person decision, or supersede. |
| Customer country / ROI / EU-VAT / exemption | Pre-obligation → re-resolution with audited acknowledgment; post-obligation pre-issuance → audited re-determination; post-issuance → rectificative handoff. |
| Customer name/address | Proceed; current snapshot; warning recorded. |

Runs at conversion, at determination, and again at issuance.

### 5.7 Rectificative handoff — minimal but wired and tested in v7

Larabill ships the rectificative base (`InvoiceSerieType::RECTIFICATIVE`, `rectifies_invoice_id`). v7 wires the handoff concretely:

- Prohibited post-issuance corrections throw `PostIssuanceCorrectionException` (indicative) carrying: original invoice reference, correction intent, consumed determination, involved facts/obligations.
- Tested against the existing capability (create a RECTIFICATIVE referencing the original) and verified NOT to cancel/reopen/mutate the original.
- When the existing capability cannot represent the concrete correction, larabill fails loud and the specialized ticket captures it — the residual dead end is declared, and its exit is that ticket (Codex 38, accepted residual).

### 5.8 Fiscal obligations — rows, deadlines, projection (operator arbitration D1)

**`billing_fiscal_obligations` (indicative):** one row per concrete accrual — never per proforma:

- References the originating effective facts (and/or the schedule entry); **idempotency key derived from source** (fact id / schedule entry + period) so the same fact or chargeability date can never create two obligations; schedule evaluation is idempotent and catch-up capable (§5.1).
- Carries amount, currency, accrual date, `issuance_due_at`, determination FK, and state: `PENDING` (born, determination outstanding) → `DETERMINED` | `BLOCKED_PARTIAL_ADVANCE` | `BLOCKED_RESOLUTION` (out-of-epoch/compromised, exits to `DETERMINED` via re-resolution or audited override) → `FULFILLED` (an issued invoice documents exactly this obligation — stamped by `issue()`); voided-with-audit when its facts are superseded (§4.1). Overdue is computed against `issuance_due_at`, surfaced per row.
- **Every obligation gets its deadline at birth** — including blocked ones: the VAT accrued; the technical block does not remove the legal clock (RD 1619/2012 art. 11; Spain B2B: before the 16th of the month following accrual). Deadline semantics live in the regional strategy: fiscal timezone (Europe/Madrid for ES), end-of-day limit instant, B2B/B2C from the frozen customer profile (Codex 69).
- Scheduled FUTURE chargeability dates are calendar, not obligations — the two concepts stay separate; obligations exist only for reached accruals.

**Proforma-level projection (derived, never persisted authority):** an explicit health value — `CLEAR` (no obligations) | `PENDING` | `BLOCKED` | `OVERDUE` | `FULFILLED` — computed from the rows with per-state counts and amounts exposed (a proforma can hold fulfilled AND blocked obligations simultaneously; the projection never hides that). LEGACY proformas project `UNKNOWN` until adopted.

**v7 automatic conversion requires the complete set of `DETERMINED` obligations sharing ONE accrual date and jointly covering the whole contract** — per-line schedule entries with the same chargeability date legitimately produce multiple rows, and the same-date AGGREGATE, not an arbitrary single row, is the conversion unit (Codex 74); any other configuration fails typed. The issued invoice stamps `FULFILLED` on exactly that set.

**Materialization trigger (Codex 81):** schedule evaluation has a guaranteed executor — a package artisan command (indicative `larabill:materialize-obligations`) documented as the scheduler entry point, AND mandatory catch-up evaluation on every read path that depends on obligations (conversion preconditions, projection computation, deadline reports). Reached obligations and their deadlines exist even on installations with no scheduler configured.

## 6. Invoice documental lifecycle — conversion, issuance, submission

### 6.1 `InvoiceDocumentStatus` and `FiscalSubmissionStatus`

```text
InvoiceDocumentStatus:  DRAFT ──▶ PREPARED ──issue()──▶ ISSUED   (terminal)
                          │           │
                          └───────────┴──▶ CANCELLED   (only BEFORE issuance)
```

**`FiscalSubmissionStatus` (operator arbitration D5 — failure classes separated):** `NOT_REQUIRED` | `PENDING` | `REGISTERED` | `ACTION_REQUIRED` | `CONTENT_REJECTED` | `LEGACY_UNKNOWN`.

- Timeout/network/external outage → stays `PENDING`; failed attempts live in the outbox.
- Local crash after external success → retry with the same idempotency key reconciles → `REGISTERED`.
- Credentials/endpoint/operational cause → `ACTION_REQUIRED`; after fixing the CAUSE (never the invoice), back to `PENDING` with the SAME payload. Metadata may be corrected only when it is not fiscal content and does not change the registered operation.
- Fiscal content rejected → `CONTENT_REJECTED`, terminal for this submission: the exit is the annulment/rectification operation in `lara-verifactu` plus larabill's rectificative handoff — never a resubmission with altered data over an immutable invoice.
- **The failure classification arrives as a TYPED result from `lara-verifactu`** (part of the boundary interface, §6.7); larabill never infers it by parsing messages.
- `NOT_REQUIRED` records the positive compliance decision (what config/state justified it, audited); `LEGACY_UNKNOWN` marks pre-v7 rows and exits only through an explicit operator reconciliation operation (Codex 63).
- `SENT`/`PAID`/`OVERDUE` remain delivery/collection legacy in `InvoiceStatus` (authority reduced; separation out of scope).

**`createInvoice()`** keeps its call shape, is reimplemented, and is documented NOT behavior-compatible: **two-phase** — (1) a durable phase commits the DRAFT invoice row (the billing aggregate that OWNS the direct facts, Codex 71) together with the registered facts and their obligation rows, so a later failure never erases economic truth (Codex 57); (2) atomic determine (dated resolver) → issue. **The one-operation-date guard applies to direct invoices exactly as to conversions** (§6.6): facts whose accruals do not share one date fail typed (Codex 80). **Facts and nature are REQUIRED explicit input** — larabill never defaults an accrual to the issuance date; a direct sale invoiced at the operation moment declares that fact. Observable changes documented in `UPGRADE-7.0.md`.

### 6.2 Conversion (rewritten `convertProformaToInvoice`)

Preconditions: proforma `FROZEN`; the complete same-date `DETERMINED` obligation set covering the whole contract (§5.8); all lines share one accrual date (§6.6); identity matrix allows; no prior active conversion (idempotent while CONVERTING/CONVERTED → returns the linked invoice).

Atomic sequence (one transaction, locks §6.8): lock proforma → validate state/idempotency (D1) → validate identity (§5.6) → create PREPARED invoice: exact copy of CONTRACTUAL terms (`contract_unit_price`, `contract_line_total`, `price_tax_mode`, quantity, description, nature, codes, unit, periods, metadata) with fiscal values (`unit_price` net, `unit_price_base_adjustment`, `taxable_amount`, components, totals) from the determination, `tax_determination_id` linked, currency copied; the conversion path holds no catalog reference and receives a resolver double that throws if invoked → persist target series (`prefix`) via `InvoiceSeriesResolver` per operation (D5-defect closed); correlative NOT consumed → derive `service_date` per the §6.6 mapping (D10 closed) → write `proforma_id` (D2 closed) + dual-write mirror → proforma `FROZEN → CONVERTING`.

**Signature:** typed command in, typed result out (indicatively `convert(ConvertProforma $c): ConversionResult`); the `Invoice|array` union dies. Final naming is a plan deliverable gated by the §8 signature snapshot.

### 6.3 Issuance — `issue()`

Atomic local sequence (no external API inside the transaction; locks §6.8):

1. Lock PREPARED invoice + proforma (conversion-born).
2. Validate determination complete and not superseded.
3. Re-run the identity matrix.
4. Capture ONE issuance instant; assign the correlative via `InvoiceNumberingService` **passing that instant** (fiscal_year/invoice_date/issued_at coherent across midnight); the numbering service **joins the ambient transaction** (no nested retry; deadlock retry wraps the whole `issue()` unit); issuer scope.
5. Set `invoice_date`/`issued_at` from the instant.
6. Mark header AND lines fiscally immutable via `saving`/`deleting` guards (D8 closed, incl. the `save()` bypass).
7. Validate `issuance_due_at`; issuing past due proceeds (late beats never) and records the breach.
8. **When compliance requires submission, verify a submission handler is BOUND (§6.7) — no handler → typed failure BEFORE issuing** (Codex 60); create the outbox record; `fiscal_submission_status = PENDING` (else `NOT_REQUIRED`, audited).
9. Invoice → `ISSUED`; conversion-born: proforma `CONVERTING → CONVERTED`; the covered obligation(s) → `FULFILLED`, in the same transaction.

Failure taxonomy: transient → rollback, invoice stays PREPARED; domain-invalid (superseded determination, identity block) → typed exceptions, remedy `cancelPreparedInvoice()` + correction + re-conversion.

### 6.4 Cancelling a PREPARED conversion invoice

`cancelPreparedInvoice()` (audited): lock proforma + invoice; require PREPARED conversion-born; invoice → CANCELLED **retaining** `proforma_id` (aborted preparation, documentary trace, never numbered); proforma `CONVERTING → FROZEN`; mirror cleared; audit records the release. Active-link uniqueness counts non-cancelled documents, so re-conversion is possible; a still-valid determination is reused.

### 6.5 `convertAndIssue()` — two sequential transactions, resumable

Conversion transaction commits first (CONVERTING + PREPARED); issuance transaction second. Issuance failure leaves **CONVERTING + PREPARED — legal and resumable** (retry `issue()` or cancel); conversion-transaction failure leaves nothing converted; failure after issuance commit leaves ISSUED with submission PENDING/ACTION_REQUIRED. Re-invocation resumes idempotently — never a second invoice. **Stalled preparations are visible:** a queryable scope + operator report lists CONVERTING+PREPARED aggregates with their obligations' `issuance_due_at` (Codex 58).

### 6.6 One accrual date per invoice — operation-date mapping

> A convertible proforma groups only lines whose materialized accrual shares one header-level date.

Differing dates → typed rejection (no min/max/first). Per-line accrual is deferred (§11). `service_date` = RD 1619/2012 art. 6.1.i operation date (column kept; semantics redefined; comment/docs updated):

| Nature / fact | `service_date` |
|---|---|
| Advance payment | payment date |
| Goods delivery | delivery date |
| One-off service | completion date |
| Successive tract | the reached chargeability date (`service_date_from/to` keeps the period) |

Unmappable cases fail loud.

### 6.7 Boundary contract with `lara-verifactu` — executable, not documental

The eight-point handoff (issue creates invoice+outbox; immutable payload/hash reference; stable idempotency key; idempotent processing returning the existing registration on retry; crash-after-external-success reconciled by the same key; chain ordering owned by `lara-verifactu`; larabill stores the acknowledgment, never the chain; `REGISTERED` only on reconciled confirmation) is enforced by an **executable contract**: larabill publishes a PHP interface (indicative `FiscalSubmissionHandler`: submit/reconcile operations returning a **typed outcome** — registered / transient / action-required / content-rejected — plus the acknowledgment), `lara-verifactu` implements it, and the composer version constraint guarantees an implementing version (Codex 59). The outbox enforces **`unique(invoice_id, operation_type)`** in addition to the unique idempotency key — two intents for the same invoice+operation are impossible (Codex 61).

**Single authority:** the invoice column is canonical business state; the outbox holds delivery mechanics; coordinated updates transactional.

### 6.8 Global lock order

`proforma → invoice → epoch → series control` (Codex 56 — epoch precedes series: `createInvoice()`'s atomic phase determines under the epoch shared lock before numbering). Facts/obligations/determinations lock the proforma first; `issue()`/`cancelPreparedInvoice()` lock proforma then invoice. No operation acquires locks in any other order; deadlock retry wraps whole operation units, never nested sub-transactions.

## 7. Schema and migration program

Every migration ships `.php.stub` + `$migrationOrder` + manifest entry + install-test bump + data program + upgrade-path test in the same PR (AID-398/412).

**Upgrade execution model:** first migration = read-only **global preflight** (below). Because a read-only check cannot stop concurrent writes: the v7 chain runs under a **write gate = an advisory lock the migration chain holds and larabill's own write operations check** (operations refuse while the upgrade lock is held — `php artisan down` alone is documented as insufficient: it stops HTTP, not workers/cron/CLI; the operator instruction is stop workers + maintenance + the package-side lock enforces the rest, Codex 64). Each data migration **re-validates its preconditions** before mutating; every DDL operation is **introspection-guarded** (`hasColumn`/`hasIndex` per operation) so a partially-applied failed migration re-runs cleanly (Codex 65). Operator ritual stays `composer update` + `larabill:install` + `migrate` under documented maintenance.

### 7.1 `invoices`

- **`proforma_status`** — nullable tinyint, `ProformaStatus` cast; `serie = PROFORMA ⇔ proforma_status IS NOT NULL` (CHECK + guard).
- **Cross-axis coherence, CHECK-backed and BIDIRECTIONAL (Codex 66):** the document axis is NULL on proformas, NOT NULL on fiscal rows; `fiscal_submission_status` is set at issuance ONLY — `ISSUED ⇔ fiscal_submission_status NOT NULL`, NULL on DRAFT/PREPARED/CANCELLED rows (before issuance there is no submission intent nor positive decision to record, Codex 78); `ISSUED ⇔ fiscal_number/series_number/fiscal_year/invoice_date/issued_at NOT NULL` (both directions — a numbered non-ISSUED row is impossible post-migration; the backfill classifies every numbered legacy row as ISSUED so no legacy exception is needed); CANCELLED never carries a number.
- **Backfill precedence:** (1) valid conversion link → `CONVERTED`; (2) cancelled → `CANCELLED`; (3) DRAFT+mutable → `DRAFT`; (4) immutable/non-editable legacy → **`LEGACY`** (adopt() promotes); (5) inconsistencies → preflight fail + report.
- **Preflight detections (before any v7 mutation):** CONVERTED without linked invoice; `converted_invoice_id` → non-fiscal row; two proformas → one invoice; contradictory inverse links; **multiple invoices sharing one `proforma_id`**; historical line incoherence `taxable_amount ≠ round(qty × unit_price)` (report + line-total precedence, §4.3); **operator attestation that historical amounts are EUR** (report item, constitution 15).
- **`proforma_id` canonical** (backfill from mirror, never overwriting) + **active-link uniqueness**: MySQL 8 stored generated column (`proforma_id` when document status ≠ CANCELLED, else NULL) + UNIQUE; SQLite partial unique index. Technique contractual; literals are plan deliverables. FK → `restrict`.
- **`converted_invoice_id`** — active-conversion mirror, dual-write v7, removed v8. **`supersedes_proforma_id`** — unique self-FK, restrict.
- **`invoice_document_status`** — legacy backfill: all numbered fiscal invoices → `ISSUED`; "numbered drafts" flagged in the report.
- **`fiscal_submission_status`** — verifactu-state mapping where present; else `LEGACY_UNKNOWN`; `NOT_REQUIRED` only as an audited positive decision going forward.
- **`currency`** — char(3) ISO-4217, backfill `EUR` (attested).
- **Nullable fiscal fields for PREPARED** (`fiscal_number`, `series_number`, `fiscal_year`, `invoice_date`, `issued_at`); `prefix` NOT NULL (selected target series).

### 7.2 `invoice_items`

- **`contract_unit_price`**, **`contract_line_total`**, **`price_tax_mode`** (backfills per §4.3), **`unit_price_base_adjustment`** (signed, default 0).
- **`operation_nature`** — nullable on LEGACY lines (supplied by `adopt()`); documentary approximation from `item_type` on already-issued lines (flagged); required at freeze for new lines.
- **Frozen fiscal classification:** frozen `tax_group_id`, jurisdiction, exemption/reverse-charge flags, fiscal profile reference — stamped at `freeze()`; the resolver reads ONLY these.
- **`tax_determination_id`** — nullable FK, unique (single consumption).
- **Immutability guards** on `saving`/`deleting` (header state driven); bulk/query and external SQL documented outside the contract.

### 7.3 New tables (names indicative; DDL literals = plan deliverables)

- **`billing_economic_facts`** — append-only: owner = proforma FK XOR invoice FK (CHECK-enforced single owner, Codex 71), type (payment/delivery/completion), date, amount+currency, **`source_event_key` (unique, Codex 73)**, optional line scope (schema-ready, document-scoped in v7, Codex 72), actor, source, `supersedes_fact_id` (unique), metadata.
- **`billing_fiscal_obligations`** — per §5.8: fact/schedule references, idempotency key (unique), amount+currency, accrual date, `issuance_due_at`, determination FK, state, audit.
- **Successive-tract schedules** — frozen chargeability schedule rows per line.
- **`tax_determinations`** (+ components) — per §5.3/§5.4, incl. the explicit rounding-adjustment field, epoch revision+hash, resolver version, `supersedes_determination_id` (unique), active-unique per line/obligation.
- **`tax_catalog_epochs`** — per §5.2, single-active enforced.
- **`fiscal_submission_outbox`** — invoice FK, operation type, `unique(invoice_id, operation_type)`, immutable payload reference/hash, idempotency key (unique), attempts, last typed outcome, next retry.

## 8. Public surface and SemVer

- **v7.0.0 major**; qualified imperative = §1.
- **`convertProformaToInvoice()` rewritten in place** with typed command/result; no deprecation of defective semantics; every missing precondition fails with a specific typed exception; `UPGRADE-7.0.md` + bold CHANGELOG document old vs new.
- **`createInvoice()`**: shape kept, reimplemented (two-phase, §6.1), documented NOT behavior-compatible.
- **New `@api` operations:** `freezeProforma()`, `adopt()`, `supersedeProforma()`, `cancelProforma()`, `cancelPreparedInvoice()`, fact registration, audited override, audited re-determination, `issue()`, `convertAndIssue()`, submission resubmission/reconciliation ops, `LEGACY_UNKNOWN` reconciliation.
- **New enums:** `ProformaStatus`, `InvoiceDocumentStatus`, `FiscalSubmissionStatus` (6 states, §6.1), obligation state + health projection enums, `OperationNature` (closed), `PriceTaxMode`.
- **New boundary interface** (`FiscalSubmissionHandler`, §6.7) with composer version constraint on `lara-verifactu`.
- **Contract gates extended:** model snapshots + **a public-signature snapshot for services, commands/results, interfaces and exceptions**; `@api`/`@internal` taxonomy on every new class.

## 9. Delivery phases (v7 epic)

Constitution binds the release boundary; each PR names its transitional exceptions.

1. **PR-1 — Schema foundations + transitional coherence:** preflight + write-gate lock; all new enums/columns (`LEGACY` incl.), currency, contract columns + adjustment, frozen classification, links + active-unique, nullable fiscal fields, obligations/facts/schedules/epochs/outbox tables, backfills, immutability guards. Transitional wiring: creating hooks stamp new state columns; old conversion gains `lockForUpdate()` + `proforma_id` write. `UPGRADE-7.0.md` born.
2. **PR-2 — Lifecycle + tax truth:** freeze/adopt/supersede/cancel + guards; facts (+corrections, coverage classification, partial blocking, currency); obligations engine (schedule evaluation, idempotent materialization, deadlines, projection); epochs + resolver + determinations (+re-determination); override; identity matrix + detector extension. **Named transitional exception:** old conversion and `createInvoice()` still ride `TaxCalculationService` until PR-3.
3. **PR-3 — Conversion + issuance + boundary:** rewritten conversion, `issue()`, `cancelPreparedInvoice()`, `convertAndIssue()`, submission axis + outbox + `FiscalSubmissionHandler` + handler-bound check, rectificative handoff wired+tested, `createInvoice()` reimplementation, `service_date` mapping, stalled-preparation report, concurrency hardening. Live catalog reads end here.
4. **PR-4 — Release closure:** CHANGELOG promotion, manifest re-stamp, `UPGRADE-7.0.md` consolidation, tag.

Documentation travels in every PR; PR-4 only consolidates.

## 10. Testing contract

- **State machines:** all transitions/guards for `ProformaStatus` (LEGACY→adopt→FROZEN incl. fact-import and attested-none paths; CONVERTING→FROZEN with determination reuse), `InvoiceDocumentStatus`, `FiscalSubmissionStatus` (per failure class: transient stays PENDING; ACTION_REQUIRED cause-fix → PENDING same payload; CONTENT_REJECTED → rectificative handoff, never resubmission), obligation states (BLOCKED_RESOLUTION → DETERMINED via override; FULFILLED stamped by issue; supersession voids with audit and lifts protection when no effective facts remain).
- **Obligations:** idempotent creation from facts and schedule (catch-up run creates no duplicates); every obligation born with `issuance_due_at` (blocked ones included); 31-Dec proportional day-count; projection exposes per-state counts/amounts; LEGACY projects UNKNOWN.
- **Coverage classification:** full/partial/overpayment sequence; rate-rise + old-provisional-gross paid (`tax_exclusive`) → partial + blocked + recorded commercial difference; `tax_inclusive` full at contract gross.
- **Backfills (MySQL upgrade-path):** all rev.-3 cases + line-incoherence detection with line-total precedence; EUR attestation surfaced; numbered legacy → ISSUED with bidirectional CHECK satisfied; chain resumability (kill mid-chain incl. mid-DDL, re-run); write-gate: package write op refused while the upgrade lock is held.
- **Conversion/issuance:** contractual-copy exactness (incl. `contract_line_total`, adjustment); resolver double throws if invoked; idempotency; per-operation series; `service_date` mapping; differing accrual dates rejected; single-obligation precondition; identity matrix at issue; handler-missing fails BEFORE issuing; midnight `fiscal_year == year(invoice_date)`; stalled CONVERTING+PREPARED report.
- **Price semantics:** exclusive preserves contractual net; inclusive preserves gross over the closed additive algebra (VAT+recargo dataset) honoring invariants with the explicit adjustment fields; property test: full equation `round(qty×unit_price) + unit_price_base_adjustment == taxable_amount` AND base + Σ quotas (+declared adjustment ≤ 1 cent) == gross; unsupported compositions fail loud.
- **Resolver/epochs:** in/out-of-epoch; override audit; governed closure transactional; single-active-epoch enforced + recovery op; compromised path (lock release/exclusive retry, no upgrade deadlock — fork test); shared-lock serialization fork test.
- **Concurrency (fork, `RUN_CONCURRENCY_IT=1` + MySQL):** two conversions → one link; two `issue()` → one number; lock-order compliance across convert/issue/cancel/facts/mutation interleavings; sensitivity check = one-off mutation test, not CI gate.
- **Boundary:** typed outcomes drive the machine; crash-after-external-success reconciles by key; `unique(invoice_id, operation_type)`; fiscal number never reverted/reused.
- **Rev.-5 additions:** fact idempotency by `source_event_key` (retried webhook → same fact, same obligation); direct-invoice facts owned by the DRAFT invoice survive an atomic-phase failure; direct invoice with multi-date facts rejected; overpayment → conversion proceeds + surplus recorded/alarmed; same-date multi-row obligation set converts as one aggregate and all rows stamp FULFILLED; multi-reached-period tract conversion blocked typed; guarded supersession transfers facts+obligations with audit while plain supersede/cancel stay forbidden post-fact; post-obligation fact correction voids/recomputes under the aggregate lock; PREPARED rows carry NULL submission status (bidirectional CHECK); catch-up materialization fires on conversion/projection/report read paths without a scheduler.
- **Immutability limit:** no public operation mutates issued headers/lines (incl. `save()` bypass); bulk/external SQL documented outside the guarantee.

## 11. Out of scope — follow-up tickets

- **Partial advances (1:N)** — **HIGH; prerequisite for any consumer accepting partial payments.** v7 records facts + deadline-bearing blocked obligations; the ticket delivers advance invoices, allocation and regularization. Designed expansion point with a named surgery list (active-unique, mirror removal, CONVERTED semantics, coverage guard).
- **Full temporal catalog versioning** (ascends to prerequisite on override metrics).
- **Real multi-currency** (persisted currency, EUR-only resolver until then).
- **Per-line accrual representation** (multi-date invoices; per-line fact scope).
- **Multi-period successive-tract invoicing from proformas** (recurring-path integration; v7 converts a single reached same-date set only).
- **Full proforma/accrual-aware rectificative workflow** (minimal handoff ships in v7).
- **Unsupported tax compositions** (withholdings, cascading, different bases).
- **Separating delivery/collection axes** out of `InvoiceStatus`; **DB-level immutability enforcement**.

## 12. Consumer impact and migration (the only section where consumers appear)

- Consumers orchestrating conversion locally (e.g. Vía 1 in `clientes`) are blocked from extending the duplication and migrate to v7 primitives, then delete their local domain. Consumers that can accept partial payments treat the 1:N ticket as prerequisite.
- Existing consumers of `convertProformaToInvoice()`/`createInvoice()` face new required inputs; `UPGRADE-7.0.md` maps them mechanically (freeze; facts; nature; typed exceptions; behavior changes of `createInvoice()`).
- Ritual: `composer update` + `larabill:install` + `migrate` under documented maintenance (workers stopped; the package-side advisory lock enforces the write gate), with the global preflight aborting loudly before any v7 mutation.

## 13. AID-444 disposition

Closed as **superseded by design**: its premise (greenfield conversion) is false (§1); its boolean models an issuer convenience, not a fiscal fact, and can write a false date (RD 1619/2012 art. 6.1.i; art. 75 LIVA — the documentary date represents the accrual, never creates or displaces it); its UX warning is dropped. `service_date` derives from the materialized accrual (§6.6): the advance-paid-June-30-converted-July-2 case yields `2026-06-30` — the truth the boolean would have falsified. New tickets are cut from this spec; AID-442's presentation layer becomes correct automatically.
