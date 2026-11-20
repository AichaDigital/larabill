# AID-2065 — VeriFactu breakdown: negative untaxed base and multi-entry lines

- **Ticket:** AID-2065 (Linear, team Development)
- **Package:** `aichadigital/larabill`, line `9.x`, base `origin/main` 6334f95 (9.6.0 manifest)
- **Classification:** `patch` — correction of a defect in an `@internal` class (`VerifactuAdapter`); no public API, schema, persisted semantics or install change
- **Status:** draft for the adversarial gate

## 1. Defects

Both live in `VerifactuAdapter::toVerifactuBreakdowns()` (`src/Services/Adapters/VerifactuAdapter.php`, generic path after the N2/OSS/reverse-charge guards).

- **D1 — negative untaxed base dropped.** Lines with `taxes_applied === []` add their `taxable_amount` to `$exemptBase`, and the exempt row is emitted only `if ($exemptBase > 0)`. A net negative untaxed base (untaxed discount, promotion, credit line) disappears: header base 90,00 € vs breakdown 100,00 € in the ticket probe.
- **D2 — base repeated per tax entry.** The inner loop adds the WHOLE line `taxable_amount` to the group of EVERY entry in `taxes_applied`. A line with two entries (e.g. 21 % + 5,2 %) declares its base twice: 233,10 vs 133,10 in the AID-2061 gate probe.

## 2. Can larabill emit a line with two `taxes_applied` entries today? Yes — two paths

Evidence from the code at 6334f95:

- **Calculated path.** `InvoiceService::createInvoiceItem()` (non-frozen branch) calls `TaxCalculationService::calculateForInvoiceItem()` with the line's `tax_group_id`. The default strategy `VatCalculationStrategy::calculate()` iterates `$taxGroup->taxRates` and appends ONE entry per rate. `TaxGroup::taxRates()` is a `belongsToMany` over the published pivot `tax_group_tax_rate` with a `priority` column, i.e. a group with N rates is a supported, documented shape (`TaxGroup` docblock: "MA state tax + Boston city surcharge"). Any consumer that attaches two rates to one group (IVA + recargo de equivalencia being the Spanish case) gets two entries per line.
- **Frozen path.** When the item data carries `total_amount`, `createInvoiceItem()` persists `taxes_applied` verbatim (proforma conversion AID-556/557, advance deductions AID-1778, and imports such as the Castris WHMCS import AID-2061). Whatever the caller freezes — including two entries — reaches the adapter.

larabill itself seeds no multi-rate group (`database/seeders/TaxRatesSeeder.php` creates single rates only), so a fresh install does not produce one unless the consumer builds the group. The shape is reachable; it is not hypothetical.

## 3. What lara-verifactu expects (read-only, installed v1.6.0)

- One `DetalleDesglose` per breakdown row. Recargo de equivalencia is NOT a separate VAT rate: it travels inside the SAME row as `TipoRecargoEquivalencia` / `CuotaRecargoEquivalencia` (`surcharge_rate` / `surcharge_amount` on `InvoiceBreakdown`), sharing the VAT base (`XmlBuilder` around lines 596–640, allowed pairs per `TipoImpositivo`).
- Amounts are `ImporteSgn12.2Type` — signed; negative bases are representable (`formatAmount`).
- `assertTotalsCoherent()` checks `CuotaTotal` and `ImporteTotal` against the breakdown sums with a ±10,00 EUR tolerance; `assertLineCuotaCoherent()` checks each S1 row's cuota against base × rate (same sign, AEAT 1143; ±10,00 EUR, AEAT 1142) — except for an R1 rectification by differences, where the whole per-row check is skipped (`XmlBuilder.php` around line 816). Inside the tolerance a wrong breakdown is NOT caught.
- An exempt row requires an explicit `exemption_reason` (E1–E4, E6); `null` is rejected (`exemptionCode()`), despite the stale adapter comment "XmlBuilder defaults to E1".

## 4. Decisions

### D1 — keep the untaxed base whatever its sign (exempt row emitted when net ≠ 0)

Options considered:

- **Negative exempt row (chosen).** The adapter already classifies untaxed lines as one exempt row. The sign of the net amount does not change what those lines are, so the classification must not change either. The row now carries the net untaxed base, negative included, and the breakdown base sum equals the header `taxable_amount`. Net zero still emits no row: it contributes nothing to any sum.
- **Netting into a taxed group (rejected).** An untaxed discount netted into the 21 % group lowers that group's base without lowering its cuota. Under art. 78–79 LIVA a discount that reduces the taxable base reduces the tax too; declaring a lower base with the same cuota misstates the operation, and for small amounts it passes the ±10 € coherence tolerance silently. Choosing which taxed group absorbs it is also arbitrary with several rates.
- **Refusal at issuance (rejected).** Issuance is not this ticket's frontier: an untaxed negative line is a valid larabill document (rectifications and frozen imports already carry negative lines). Refusing in `InvoiceService` would narrow an accepted flow (constitution §9) and collide with W2's parallel work on `InvoiceService`.

Consequence, stated honestly: the exempt row still carries `exemption_reason => null`, so lara-verifactu 1.6.0 refuses any invoice that EMITS an exempt row (untaxed lines with a non-zero net) at XML build. That is pre-existing for positive nets and is now also true for negative nets. Untaxed lines that net to zero emit no row and are not refused; a line with a single 0 % tax entry is not untaxed at all — it follows the S1 path at 0 % and never meets the exemption-cause check — a loud refusal replaces a silently wrong record. Supplying an exemption cause is a feature (new input), not this patch; it is filed as a follow-up.

### D2 — refuse a line with more than one tax entry

Options considered:

- **Split the base across entries (rejected).** IVA and recargo share ONE base; dividing it declares two half-bases that exist nowhere in the invoice.
- **Base once on the first entry, extra entries as base-0 rows (rejected).** Produces a 5,2 % "VAT rate" row with base 0 and non-zero cuota — RE declared as a VAT rate, which it is not; it can slip inside the cuota tolerance.
- **Map the second entry to `surcharge_rate`/`surcharge_amount` (correct target, not this patch).** The frozen snapshot holds only `source_rate_id`, `name`, `rate`, `amount` — no tax kind. Telling VAT from recargo would need either reading the live `TaxRate` (breaks the immutable-snapshot rule, ADR-001) or a new snapshot field (a feature with persisted-semantics impact). Filed as a follow-up.
- **Refuse (chosen).** `toVerifactuBreakdowns()` throws `ValidationException` (key `verifactu`) when any line carries more than one entry, before anything is persisted — the same fail-loud pattern as the N2/OSS/reverse-charge guards, and `InvoiceVerifactuService::registerInvoice()` already builds the breakdown before its transaction.

What the refusal removes (§9), stated exactly: for a multi-entry line with a non-zero base, no correct VeriFactu record was ever produced — above 10 € of difference the doubled sum is rejected by lara-verifactu's totals guard, below it a wrong record is accepted. **One correct case is removed:** a multi-entry line with zero base and zero tax used to yield coherent `(0, 0)` rows (gate round 1, finding 2, measured against 6334f95). Keeping it would mean refusing only non-zero bases; the refusal stays total because such a line has no fiscal content to declare, the consumer that would be affected (clientes) has no multi-rate groups, and an unconditional rule is simpler to reason about than one that depends on the base. Larabill issuance, PDF and settlement of multi-entry lines are untouched.

### SemVer impact (for the release decision)

- **D1** changes only the content of the breakdown for invoices whose untaxed lines net negative: a row that was missing is now present. No refusal is added by larabill. Since the row has no exemption cause, lara-verifactu 1.6.0 now refuses those invoices at XML build, as it already refused positive nets (AID-2068).
- **D2 adds a new refusal on an existing path.** `InvoiceVerifactuService::registerInvoice()` (`@api`) and the `@internal` adapter now throw `ValidationException` (key `verifactu`) for an invoice with any multi-entry line, where 9.6.0 returned a breakdown and persisted the VeriFactu rows. Under constitution §8–§9 this is a narrowing of an accepted call, justified as a defect correction: the rows it used to persist misdeclared the base whenever the line had one; the only correct case removed is a zero-base, zero-tax multi-entry line (§4 D2). The same method already throws `ValidationException` for its other guards, so the failure type is not new to callers.
- **Classification proposed: `patch` (9.6.x)**, with the D2 refusal stated in bold in the CHANGELOG. If the owner reads the D2 refusal as a narrowing that needs the §9 record (as AID-1884 did for 9.5.0), the release becomes a minor with that record; the code does not change.
- **Consumer check:** clientes confirmed on 2026-11-20 that it has no recargo de equivalencia and only single-rate groups (WHMCS `tax2` used in 0 of 2602 documents), so D2 does not change any of its flows.

## 5. Tests (red first)

In `tests/Feature/Verifactu/BreakdownTotalsTest.php`, issued through `InvoiceService::createInvoice()` with frozen lines:

- **T1 (D1):** taxed 100,00 @ 21 % + untaxed −10,00 → Σ `base_amount` = header `taxable_amount` (90,00); Σ non-exempt `tax_amount` = header `total_tax_amount` (21,00); one exempt row of −10,00.
- **T2 (D2):** one line, base 110,00, with two entries (21 % + 5,2 %) and with three entries → `ValidationException` whose error bag has key `verifactu`; no row is emitted.
- **T3 (D2, calculated path):** a `TaxGroup` with two attached `TaxRate`s, line created through the tax calculation (no frozen keys) → the item has two entries (proves §2) and the adapter refuses.
- **T4 (regression):** untaxed lines netting to exactly zero → no exempt row, sums still match the header.
- **T5 (regression):** positive untaxed net still emits one exempt row with that base (behaviour preserved).

## 6. Known limitations — not regressions, not fixed here

The fix makes the breakdown match the header for the two defects. It does not make the totals a universal invariant; these pre-existing cases remain (gate round 1, findings 1 and 3, measured):

- **Frozen entries are trusted.** A frozen line whose `taxes_applied` entry lacks `amount` contributes 0 tax (`$tax['amount'] ?? 0`) while the header uses the line's `total_tax_amount`; the record can pass XML build and XSD when the difference stays within the ±10 € coherence tolerance (beyond it lara-verifactu refuses with AEAT 1216/2006). `createInvoiceItem()` does not reconcile the two on the frozen path.
- **Per-line rounding can make a rate group unemittable** although the totals match: small mixed-sign lines can give a group a positive base and negative cuota (AEAT 1143), and thousands of rounded-up lines can drift the group cuota beyond the ±10 € of base × rate (AEAT 1142). lara-verifactu refuses both; nothing is declared wrong silently.

## 7. Gate adjudication

Round 1 (codex, isolated read-only, session 01a1f7ba, on 3566a5f). Verdict: approach real; not a universal totals invariant.

| # | Finding | Measured | Decision | Landed in |
|---|---|---|---|---|
| 1 | Missing `amount` in a frozen entry breaks tax equality | true, pre-existing (`?? 0` in the adapter) | document; claim narrowed | §6, CHANGELOG |
| 2 | Zero-base two-entry line was a correct case, now refused | true | keep the total refusal; rationale corrected | §4 D2, SemVer |
| 3 | Rounding / mixed signs can still fail per-row checks; R1-by-differences skip omitted | true, pre-existing | document | §3, §6 |
| 4 | Tests miss `> 1` vs `=== 2` and the error key | true (both mutations survived) | T2 now a dataset with 3 entries; refusal asserted on key `verifactu`; both mutations now red | tests |
| 5 | «ANY invoice with untaxed lines» too broad; 0 % entry follows S1 | true | wording narrowed | §4 D1, CHANGELOG, AID-2068 |
| 6 | Reachability and no-write-before-throw hold | confirmed | — | — |

Round 2 (session 01a1f7d0, on 9e4c9a1). Verdict: round-1 corrections real; both mutations now red (re-executed by the reviewer).

| # | Finding | Measured | Decision | Landed in |
|---|---|---|---|---|
| 1 | The zero-base refusal has no test: `&& base !== 0` survives | true | T2 dataset case with base 0, tax 0 | tests |
| 2 | §6 «XML and XSD both pass» too broad for a missing `amount` | true (beyond ±10 € the totals guard refuses) | qualified | §6 |
| 3 | §8 «makes any untaxed-line invoice registrable» too broad | true (D2 still applies) | narrowed | §8 |
| S1 | clientes' «Exento de IVA» group may be a 0 % rate (S1 at 0 %), not an empty group | measured by clientes from its seeders (2026-11-20): one `TaxRate` «Exento de IVA», rate 0, `TaxType::VAT`, no exemption flag; same seeders in staging and the Castris fork | confirmed real; NOT widened into this patch (needs rate data, STD-005 schema change) | AID-2068 |

## 8. Out of scope / follow-ups

- Exemption cause for untaxed and 0 % exempt lines (removes the missing-exemption-cause refusal and the silent S1-at-0 % record; other guards, D2 included, still apply) — **AID-2068** (Backlog, High; blocks clientes in production with VeriFactu: its «Exento de IVA» group is one 0 % rate, so its exempt lines are declared S1 at 0 % today).
- Recargo de equivalencia support (snapshot kind + surcharge mapping) — **AID-2069** (Backlog, Medium; no consumer needs it today).
- Stale comment "XmlBuilder defaults to E1" — corrected in this patch (documentation only).
- `InvoiceService` is not touched (coordination with W2).
