# Work note — fiscal/accounting retention contract for the privacy layer

- **Status:** **implemented (T3, 2026-06-20)** — `Invoice` and `UserTaxProfile`
  now implement `LegallyRetainable`.
- **Date:** 2026-06-19 (design); realigned 2026-06-20 to the contract as shipped.
- **Origin:** `lara-privacy` fit analysis (`fit-analysis.md`); contract shape
  settled by `lara-privacy` ADR-003 (universal core, jurisdictional presets).

## Reframing (the core decision)

The contract is a **fiscal/accounting retention obligation owned by larabill**, not a
GDPR-erasure decision owned by the privacy layer. larabill **exposes** how long a record
must be kept; `lara-privacy` only **respects** it — block/restrict during the hold,
anonymise/erase only after it lapses.

This inverts the original framing: the privacy layer does not decide the life of an
invoice; the fiscal domain does. RGPD/LOPDGDD do not require destroying invoices while a
legal duty to keep them exists — LOPDGDD art. 32 mandates *blocking/restriction* before
destruction where applicable.

`lara-privacy` keeps the **mechanism** (the read-only gate today, the prune later) and
reads only the **one** signal this contract exposes: `retainedUntil()`. The duration and
the anchor live in larabill; "is it held right now" is **derived** by
`lara-privacy-core`'s `CheckLegalHold`, not exposed by larabill.

## The contract (as shipped)

A PHP interface owned by **`lara-privacy-core`**
(`AichaDigital\LaraPrivacyCore\Contracts\LegallyRetainable`), implemented by `Invoice` and
`UserTaxProfile`. `lara-privacy` type-hints the interface, never the concrete models.

```
retainedUntil(): ?DateTimeInterface   // the instant the hold lapses; null = no hold
```

One method. ADR-003 collapsed the earlier four-method sketch
(`retentionBasis`/`isUnderRetention`/`legalHold`):

- **`isUnderRetention()` / `legalHold()` are NOT on the contract** — they derive in
  `CheckLegalHold` (`retainedUntil > now`). The domain answers "until when"; the core
  answers "held now?".
- **`RetentionBasis` is gone.** The *why* (legal source) and the *how long* (duration) are
  no longer a contract type: the duration is larabill config (and, optionally, a
  `lara-privacy` jurisdictional preset), and the anchor is larabill's own fiscal logic.
- The hold is the **ordinary statutory** retention hold, **NOT** a litigation/inspection
  hold — that remains a separate, future concept.
- Type is `?DateTimeInterface` (not `CarbonInterface`); the Eloquent Carbon accessors
  satisfy it directly.

## Per-entity rules (as implemented)

- **`Invoice`** — fiscal types only, via `serie->isFiscal()` (`INVOICE`, `SIMPLIFIED`,
  `RECTIFICATIVE`). Anchor = **end of the fiscal year of `invoice_date`** (`->endOfYear()`,
  calendar year = the ES default), plus `config('larabill.retention.fiscal_years', 6)`
  years (**decision A**). `PROFORMA` → not fiscal → `null`. **Decision B:** a fiscal
  invoice with no `invoice_date` yet has no valid legal anchor → `null` (never invent a
  date from `now()`).
- **`UserTaxProfile`** — **no flat retention of its own.** `retainedUntil()` =
  `MAX(retainedUntil())` over its related `invoices()`, nulls filtered. A recent
  rectificative pointing at an old snapshot re-extends the hold. **The MAX does NOT use
  `withTrashed()`** — `Invoice` is not soft-deletable; only `UserTaxProfile` is. Because the
  hold derives from the invoices, a **soft-deleted profile** that still backs a live fiscal
  invoice stays held (the hold ignores the profile's `deleted_at`). **Decision C:** an
  orphan profile that never backed an invoice → `MAX` is null → no hold (no self-anchored
  `valid_from` mini-hold in v1.0).

## Key design decisions

- **Centralise the duration AND the anchor — but not in a `RetentionBasis` enum (dropped).**
  The duration is one config key (`larabill.retention.fiscal_years`). The anchor is computed
  per entity in the model, because it differs:
  - `Invoice` → fiscal-year-end of `invoice_date`.
  - `RoiQuery` → query event (`created_at`), already materialised as `legal_retention_until`,
    **7 years** (`RoiQuery.php:79`). Valid precedent, but its naive `created_at + N` anchor
    was **wrong to copy** for invoices — hence the fiscal-year-end anchor.
  - `UserTaxProfile` → derived MAX over invoices.
  - The two distinct in-domain periods (7y ROI logs, 6y invoices) stay an **explicit,
    justified** split, not each model inventing a number.
- **Compute, don't materialise (decision D).** Implemented as computed accessors; no
  `retained_until` column. If SQL-scale pruning is ever needed, materialise **only**
  `Invoice.retained_until` (immutable row → column fixed at `creating`, never stale).
  **Never** materialise `UserTaxProfile.retained_until` — it is derived from invoices that
  can be added later, so it would go stale.

## Status of the two pieces

1. **The retention contract** (this note) — **DONE in T3.** `LegallyRetainable` (defined in
   `lara-privacy-core`) implemented on `Invoice` + `UserTaxProfile`, computed, config-driven
   duration, TDD-covered. No `RetentionBasis` enum.
2. **`withTrashed()` fix — handled separately by AID-222, not by T3.** The fix
   (`Invoice::userTaxProfile()->withTrashed()`) lives on branch
   `abdelkarim/aid-222-invoice-usertaxprofile-withtrashed` (pending merge to `main`). T3
   branches off `main`, which does **not** yet carry it, and T3 deliberately does not touch
   that relation — `UserTaxProfile::retainedUntil()` walks `invoices()` the other way and
   needs no `withTrashed()`. The handoff's "T11 — latent withTrashed bug, separate PR" item
   is therefore **obsolete**: it is already done on AID-222.

This separation is itself the proof that fiscal `SoftDeletes` and GDPR erasure must never
be conflated.

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
