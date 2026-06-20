# Work note — fiscal/accounting retention contract for the privacy layer

- **Status:** design converged — pending implementation
- **Date:** 2026-06-19
- **Origin:** `lara-privacy` fit analysis (`fit-analysis.md`)

## Reframing (the core decision)

The contract is a **fiscal/accounting retention obligation owned by larabill**, not a
GDPR-erasure decision owned by the privacy layer. larabill **exposes** how long a record
must be kept and why; `lara-privacy` only **respects** it — block/restrict during the
hold, anonymise/erase only after it lapses.

This inverts the original framing: the privacy layer does not decide the life of an
invoice; the fiscal domain does. RGPD/LOPDGDD do not require destroying invoices while a
legal duty to keep them exists — LOPDGDD art. 32 mandates *blocking/restriction* before
destruction where applicable.

`lara-privacy` keeps the **mechanism** (`RetentionPolicy`, scheduled prune, gate); it
takes the durations, the anchor, and the hold signal from this contract.

## The contract

A PHP interface in `src/Contracts/` (working name `LegallyRetainable`), implemented by
`Invoice` and `UserTaxProfile`. `lara-privacy` type-hints the interface, never the
concrete larabill models.

```
retainedUntil(): ?CarbonInterface   // null = not under fiscal retention (e.g. proforma)
retentionBasis(): RetentionBasis    // why + how it is computed (duration + anchor)
isUnderRetention(): bool            // gate for lara-privacy block/restrict
legalHold(): bool                   // alias of isUnderRetention() for lara-privacy compat
```

- **`legalHold()` is documented as "active legal retention hold"** (ordinary statutory
  retention), **NOT** a litigation hold. A future exceptional hold (inspection,
  litigation, indefinite block) is a *separate* concept/field — not this method.

## Per-entity rules

- **`Invoice`** — fiscal types only, via the existing predicate `serie->isFiscal()`
  (`INVOICE`, `SIMPLIFIED`, `RECTIFICATIVE`). Anchor = **end of the fiscal year of
  `invoice_date`** (conservative: the exercise closes after the legal date), **+ 6 years**.
  `PROFORMA` → not fiscal → `retainedUntil()` is `null`.
- **`UserTaxProfile`** — **no flat retention of its own.** `retainedUntil()` =
  `MAX(retainedUntil())` over its related `invoices()` (`UserTaxProfile.php:180`). A
  profile is retained while any live fiscal invoice references it — a recent rectificative
  pointing at an old snapshot re-extends its hold. Evaluated **even when the profile row is
  soft-deleted**; the hold ignores `deleted_at`.
  - Edge: orphan profile never invoiced → no invoices → `MAX` is null. Fallback to a
    self-anchored mini-hold on `valid_from`. Decide at implementation.

## Key design decisions

- **Centralise the duration AND the anchor.** "6 vs 7 years" is only half the problem;
  "*from when*" is the other half. `RetentionBasis` must carry **both** (duration +
  computation anchor), because the anchor differs per entity:
  - `Invoice` → fiscal/accounting anchor (fiscal-year-end of `invoice_date`).
  - `RoiQuery` → query event (`created_at`), already materialised as
    `legal_retention_until`, **7 years** (`RoiQuery.php:79`). Valid precedent, but its
    naive `created_at + N` anchor is **wrong to copy** for invoices.
  - `UserTaxProfile` → derived max over invoices.
  - Duration alone never suffices — the enum must make the anchor explicit. Two distinct
    in-domain periods (7y ROI logs, 6y invoices) must be an **explicit, justified**
    decision, not each model inventing its own number.
- **Compute, don't materialise (first step).** Cleaner, no drift, the legal rule lives in
  code. If SQL-scale pruning is ever needed, materialise **only** `Invoice.retained_until`
  (immutable row → column fixed at `creating`, never stale). **Never** materialise
  `UserTaxProfile.retained_until` — it is derived from invoices that can be added later, so
  it would go stale; compute it as a `MAX(...)` JOIN instead.

## Two separate pieces (related, delivered apart)

1. **The retention contract** (this note): interface + `Invoice`/`UserTaxProfile` impl +
   `RetentionBasis` enum, computed.
2. **`withTrashed()` fix — independent latent bug.** `UserTaxProfile` uses `SoftDeletes`
   (`UserTaxProfile.php:58`) but `Invoice::userTaxProfile()` is a `belongsTo` **without**
   `withTrashed()` (`Invoice.php:359`). Soft-deleting a referenced profile makes
   `$invoice->userTaxProfile` null, which breaks
   `InvoiceVerifactuService::validateForVerifactu()` ("Invoice must have a tax profile",
   `InvoiceVerifactuService.php:126`) on an already-issued invoice. Pre-existing, anterior
   to this contract; the contract makes it urgent. Fix = `->withTrashed()` on the relation.
   **Do NOT fold it into the contract PR.**

This bug is also the proof that fiscal `SoftDeletes` and GDPR erasure must never be
conflated.

## Legal basis

- Accounting/commercial: **6 years** — Código de Comercio art. 30.
- Invoicing: Reglamento de facturación (RD 1619/2012) art. 19 refers invoice retention to
  the LGT period.
- Tax: **4 years** prescription — LGT art. 66 y ss.
- Privacy: block/restrict before destruction — LOPDGDD art. 32.

Conservative rule: the 6-year commercial period dominates the 4-year tax period in
practice. Retaining longer never breaches; retaining less does.

## Out of scope here

The anonymisation pipeline and the audit live in `lara-privacy`. larabill only exposes the
retention contract and the hold signal.
