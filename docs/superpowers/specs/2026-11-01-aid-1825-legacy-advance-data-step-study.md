# AID-1825 — study: an automatic migration step for legacy advances instead of a manual SQL (PROPOSAL, not approved)

- **Status:** study written under the operator's authorization of 2026-11-01 (00:41 CET), limited to *studying* the replacement of the manual SQL by an automatic migration step, as an exception to the operator's earlier preference (shared notes line 103: «schema-only migration plus documented SQL, not an automatic backfill»). **Nothing here is approved.** No gate, no migration run, no code, no Linear change.
- **Base documents (unchanged by this study):** rev 6.11, sha256 `65807c4db6d35355235f150c176dbb8e945fd2e6321ff70eda4ab633ffb40c02` (462 lines); shared notes, sha256 `dcd8519aa86bdafb5c110051f622d61de133eae0308ef98f3e89dfa69601b741` (114 lines).
- **Measured on:** `2785375` (main), files cited with line numbers. Tags: **[V]** verified in the tree today, **[P]** proposed, **[T]** pending proof, **[Q]** open question for the operator.

## 0. A defect of rev 6.11 this study corrects

Rev 6.11 §5.11(b) already moved the PAID stamp into migration `'055'` as an automatic data step, and §7c V7 presents that as settled. That contradicted the operator's recorded preference (shared notes line 103) **before** any authorization to depart from it existed. It was an overreach of the draft, not a decision. This study replaces §5.11(b) as the material to decide on; if the operator approves it, a rev 6.12 integrates it and rewrites §5.11(b), §7b A#8, §7c V7 and §8 row 6 accordingly. If the operator rejects it, rev 6.12 restores the documented-SQL route and must then answer the conflict with `STABILITY.md` rule 2 recorded in §6 below.

## 1. What the step fixes, and why it exists at all

- **[V] An issued advance stays at collection DRAFT.** `AdvanceInvoiceBuilder::forCreditEntry()` creates the advance with `'status' => 'draft'` (`src/Services/AdvanceInvoiceBuilder.php`, the `createInvoice()` call at the end of the method) and the documented sequence is build → `linkAdvanceInvoice()` → `issue()`; nothing in that sequence touches the collection status.
- **[V] At collection DRAFT the advance is closed to every correction path:** `SettlementService::assertRectifiable()` refuses DRAFT (`src/Services/SettlementService.php:506-507`), and so do `assertAnnullable()` (`:476-477`) and `assertSettleable()` (`:382-383`). The refund of advance-backed balance (AID-1778 §5.1, partial rectificative of the advance) therefore cannot be documented for those advances.
- **[V] Nothing repairs it later:** `Invoice::recomputeCollectionState()` returns early on DRAFT and, for any other status, also returns early when the invoice has no allocation rows (`src/Models/Invoice.php:815-836`). An advance has no allocations — its money arrived as a credit entry — so the DRAFT is permanent.
- **The money was collected:** an advance is, by construction, an invoice that documents an entry whose money had already been received (`credit_movements.grouped_payment_id`, NOT NULL, migration `2026_10_24_000001:250-253`). The PAID stamp states something true; the open question is only which rows can be shown to be such advances without guessing.

## 2. Population: which rows exist, by origin

| Origin | How the link was written | Shape |
|---|---|---|
| 8.3.x | `linkAdvanceInvoice()` before its 9.0 narrowing **[V]** (`STABILITY.md` rule 3, qualified exception 2026-10-26): it accepted a proforma, a non-draft invoice, and more than one entry per invoice (the column has no UNIQUE, `2026_10_24_000001:264-268`) | heterogeneous; may not be an advance at all |
| 9.0 / 9.1 | builder + narrowed link: unnumbered DRAFT of kind `INVOICE`, one-to-one in both directions | homogeneous; exactly the shape the step targets |

There is no column that tells the two origins apart, so the criterion must be **structural**, not historical.

## 3. Proposed criterion — rows the step WOULD modify [P]

An invoice is stamped only when **every** condition holds, evaluated per invoice under lock (§5):

1. **A1 — it is an issued fiscal invoice still at collection DRAFT:** `serie = 1` (`InvoiceSerieType::INVOICE`), `invoice_document_status = 2` (ISSUED), `status = 0` (`InvoiceStatus::DRAFT`), `rectifies_invoice_id IS NULL`, `paid_at IS NULL`.
2. **A2 — exactly one entry documents it:** exactly one `credit_movements` row has `advance_invoice_id = invoice.id`; that row is an entry (`kind` ∈ {0 SURPLUS, 1 TOP_UP}) and `reversed_at IS NULL`.
3. **A3 — the entry and the invoice are the same money:** `entry.amount = invoice.total_amount` to the cent, no tolerance, **and** the same currency. [V] `invoices` has **no** `currency` column (`2024_12_01_000003_create_invoices_table.php`); the invoice's currency lives in its frozen fiscal snapshot (`src/Models/Invoice.php:1451`). Which persisted field the migration reads for it is [T]; if no reliable persisted source exists for every row, a row whose currency cannot be read counts as ambiguous, never as a match. (The builder checks currency against the active config at build time for 9.x rows; 8.3.x links had no such check.)
4. **A4 — the money is still received:** the entry's `grouped_payments` row has `status = 0` (POSTED), `reversed_at IS NULL`, `paid_at IS NOT NULL`.
5. **A5 — no allocation owns its collection state:** no row in `grouped_payment_invoice` references the invoice (any status), so stamping cannot contradict an allocation-derived state.

**Write (one row, nothing else):** `status = 2` (PAID), `paid_at = grouped_payments.paid_at` of the entry's payment. **Not** `COALESCE(paid_at, invoice_date)` as rev 6.11 wrote: the invoice date is not the collection date (the money arrived before the advance was issued), and the operator's rule L8 forbids presenting a reconstructed value as a historical datum. The payment's `paid_at` is the recorded collection date, not a reconstruction. `updated_at` is also set (ordinary bookkeeping) — [Q1] whether the operator prefers it untouched.

Every enum integer above is pinned against the enums at build time by a test, as the 8.0 CHECK precedent does (`2026_10_13_000003:118-120`).

## 4. Rows the step would NOT modify, and why

| Case | Why it is left alone | What the consumer sees |
|---|---|---|
| Advance already PAID, SENT, OVERDUE or PARTIALLY_PAID (stamped by the consumer) | the consumer's stamp is respected; SENT is already rectifiable (`assertRectifiable()` refuses DRAFT only) and rev 6.11 §5.2 step 5 already takes backing invoices out of debt and payability | unchanged |
| Advance still an unissued DRAFT document | nothing to correct yet; rev 5 D8 (kept in rev 6.11 §8 row 6) stamps PAID at issue for new advances | unchanged |
| Linked proforma or linked rectificative (8.3.x) | fails A1: not an advance | unchanged, **reported** |
| Two or more entries linked to one invoice (8.3.x) | fails A2: which entry funded it is not determinable | unchanged, **reported** |
| Linked entry reversed, or its payment reversed | fails A2/A4: the money is no longer held; PAID would be false | unchanged, **reported** |
| Amount or currency differ between entry and invoice, or the invoice's currency cannot be read | fails A3: possibly an ordinary invoice linked by mistake in 8.3.x | unchanged, **reported** |
| `paid_at` already set while status DRAFT | fails A1: a consumer value of unknown meaning; never overwritten | unchanged, **reported** |
| Allocations exist on the invoice | fails A5: the collection state belongs to the allocation engine | unchanged, **reported** |
| Every other invoice, proforma, entry, payment, allocation | outside the predicate | byte-identical |

## 5. Avoiding ambiguous changes, interruption and repetition [P]

- **Conservative by construction (precedent 9.0 `band_start`, `2026_10_26_000001:31-61`):** a row qualifies only on positive evidence of every condition; any doubt leaves it untouched. No heuristic, no tie-break, no «most likely» entry.
- **What happens to the ambiguous rows — two options, [Q2]:**
  - **(a) proposed — skip and report:** the migration leaves them as they are today (no regression: they stay exactly as unrectifiable as in 9.1) and prints a count per reason; a read-only command (`larabill:diagnose-legacy-advances`, precedent `larabill:diagnose-price-overlaps` of 6.8.0) lists them by id and reason for a person to resolve. The upgrade never blocks.
  - **(b) loud abort (precedent 7.0.0 preflights):** any ambiguous row aborts the migration with the table untouched until the consumer remediates. Safer against silence, but blocks the upgrade of every installation carrying 8.3.x links it cannot interpret.
- **Per-invoice transaction:** each candidate is re-read and re-checked against A1–A5 inside its own `DB::transaction` with `lockForUpdate()` on the invoice, its entry and its payment, then written. A stop leaves a prefix stamped and the rest untouched (MySQL/MariaDB DDL is not transactional, same reasoning as `2026_10_26_000001:65-73`). Ordering: the schema pieces of `'055'` first (guarded by introspection), the data step after, so a stop between them leaves the new columns at defaults and no row stamped.
- **Repetition:** the predicate requires `status = DRAFT`, so a stamped row never re-qualifies: a second `migrate` changes zero rows. An ambiguous row is re-evaluated on each run with the same deterministic result unless a person has repaired it in between, in which case it is stamped then — the intended effect.
- **Query builder, not models:** the step uses `DB::table()` and so fires no model events (no Verifactu or notification side effect); the collection status is not fiscal content (`SettlementService.php:197` precedent), and the axis-coherence CHECK does not constrain `status` [V] (`2026_10_13_000003:122-137`). That bypass must be documented as deliberate.
- **`down()` — [Q3]:** a reversal cannot tell a row stamped by the migration from a row stamped legitimately afterwards. Proposed: `down()` drops the schema pieces and **does not** revert the data step, stated in the CHANGELOG (precedent 8.2.0/8.3.0: the honest rollback of data is the previous version's backup). Alternative: record the stamped ids in a dedicated table so `down()` can revert exactly — more machinery for a rollback nobody has asked for.

## 6. Effects on existing data and on behaviour

- **On the stamped rows:** `status` DRAFT → PAID and `paid_at` NULL → the payment date. Nothing else.
- **Capabilities that open on them:** rectify, settle and annul become reachable. Rectify is the purpose. **Annul is a side effect to weigh [Q4]:** after the stamp, `assertAnnullable()` would let a consumer annul an advance whose entry still holds advance-backed balance (it refuses only allocations and rectificatives, `SettlementService.php:470-493`), leaving balance whose VAT document was withdrawn. The same state is already reachable today for any advance a consumer stamped PAID or SENT by hand, so the step widens an existing gap rather than creating it; whether rev 6.12 should add a guard (refuse annulling an invoice still linked from an entry with live balance) is a design question for the operator, outside this study.
- **Reads:** the stamped invoices stop being «DRAFT» to any consumer report or query that filters on collection status. `outstanding()` and payability already exclude backing invoices under rev 6.11 §5.2 step 5, whatever the status.
- **Known exposure:** `clientes` `origin/main` created no advances when measured on 2026-10-30; no other consumer is known to. That measurement is dated and must be repeated before any release decision; it is an input to the classification, not the classification.

## 7. Contrast with `STABILITY.md`

- **Rule 2 — the upgrade mechanism [V] (`STABILITY.md:9-22`):** the ONE mechanism is `composer update` + `larabill:install` + `migrate`, and every data-touching release ships «a migration that handles existing data». A consumer-run SQL is a second mechanism and so does not fit rule 2; the automatic step does. This is the strongest argument for the replacement, independent of the version question.
- **Rule 2 — the minor boundary [V] (`STABILITY.md:24`):** «Minor releases may ship schema changes only when they are additive or provably data-safe […]; anything that can lose data, reject existing rows or change persisted semantics is a major.» The step loses no data and rejects no row; whether rewriting a persisted `status` value counts as «changing persisted semantics» is exactly the interpretive point (§8).
- **Rule 5 — fixes [V] (`STABILITY.md:38`):** a fix that changes observable behaviour goes under **Fixed** with old and new behaviour stated. The step would be documented that way in any classification.
- **Rule 1 — imperative:** a major needs a qualified usage imperative. Here the candidate imperative would be a data-integrity / fiscal one (an advance that cannot be rectified cannot document a refund of advance money); its strength depends on the exposure, measured as zero in the known consumer.

## 8. Version classification — analysis, NOT a decision

| Reading | Argument | Precedent in this package [V] |
|---|---|---|
| **Minor (Fixed)** | the step restores a true state (the money was collected), loses nothing, rejects nothing, is conservative and idempotent; the package already shipped data steps that rewrite existing values in minors | 8.3.0 (minor) rewrote `grouped_payment_invoice.status` to REVERSED on existing rows, as a data-integrity fix (CHANGELOG 8.3.0 header); 6.3.0 (minor) a guarded one-way data cleanup; 4.1.0 (minor) the series-control sentinel backfill |
| **Major** | the literal text of rule 2 reserves for a major any change of persisted semantics; a consumer that read DRAFT on those advances as meaningful sees it flipped; the step also widens the annul gap (§6) | 9.0.0 shipped the `band_start` reconstruction inside a major (but as a new nullable column, so it is not a counter-example for a minor either); 7.0.0 data mappings inside a major |
| **Neither forced by the step** | if the rest of `'055'` (or the narrowings of rev 6.11 §8 rows 4, 10, 11) already forces a major, the step adds nothing to the decision; if the release is otherwise a minor, the step is the item to judge | rev 6.11 §8 leaves every row unclassified |

The release process under `STABILITY.md` decides, with the repeated exposure measurement as input. Nothing in this study chooses.

## 9. How the upgrade would be proven on MySQL 8.4 and MariaDB 11.4 [T]

- **Harness:** `tests/Integration/UpgradePath/` on `MysqlIntegrationTestCase`, run with `LARABILL_TEST_MYSQL_DRIVER=mysql` (Herd MySQL 8.4, port 3384) and `=mariadb` (MariaDB 11.4, port 3307), the same pair as the CI `db-integration` jobs; engine freeze respected (no MySQL 9.x, no MariaDB 11.8+). A green run with skips proves nothing: the skip count is asserted zero.
- **Seed (state after migration `'054'`, the 9.1 schema):** one row per line of §3 and §4 — a 9.1 advance meeting A1–A5; the same with the consumer's SENT and PAID stamps; an unissued advance DRAFT; an 8.3.x-style linked proforma; two entries linked to one invoice; a reversed entry; a reversed payment; amount mismatch; currency mismatch; `paid_at` preset; an advance with an allocation; plus ordinary invoices, proformas and entries untouched. The fixture's base is stamped with the manifest base in the same unit (lesson 2026-10-17: stamp and fixture travel together).
- **Assertions after `migrate`:** each qualifying row PAID with `paid_at` equal to its payment's `paid_at` **read back through the application connection** (the source is `dateTime`, the target `timestamp`, `2026_06_27_000001:22` vs `2024_12_01_000003:34`: a session time-zone conversion must be shown not to shift the instant on either engine); every non-qualifying row byte-identical (full-row comparison before/after, not a field list); the report counts per reason equal to the seed.
- **Repetition:** a second `migrate` changes zero rows (row checksums equal).
- **Interruption:** the per-invoice routine is run on a subset, then the full migration; the result equals one clean run.
- **Sensitivity (mutation, RED required):** dropping each of A2, A3, A4, A5 in turn makes at least one seeded ambiguous row turn PAID and the test fails; writing `invoice_date` instead of the payment date fails.
- **Model-level follow-up:** after the upgrade, a stamped advance accepts the partial rectificative of AID-1778 §5.1 (the purpose), and an ambiguous one still refuses it.
- **Pieces of the shipped migration:** unchanged from rev 6.11 §5.11 inventory (`.php` + `.php.stub`, `$migrationOrder`, manifest after the CHANGELOG promotion, install counts); the migration is never edited after release (`ShippedMigrationImmutabilityTest`).

## 10. Alternative not proposed, recorded for comparison

An **opt-in artisan command** (precedent 8.3.0 `larabill:backfill-allocation-status`) that applies the same criterion only when the consumer runs it. It keeps the upgrade ritual intact and leaves the decision per installation, but an installation that never runs it keeps advances it cannot rectify, and rule 2 asks the migration itself to handle existing data. Its only advantage over the proposed step is that it avoids the version question for consumers who never run it.

## 11. Open questions for the operator

- **Q1:** touch `updated_at` on stamped rows, or leave it?
- **Q2:** ambiguous rows — skip and report (proposed) or loud abort?
- **Q3:** `down()` without data reversal (proposed) or with a stamped-ids record?
- **Q4:** add a guard against annulling an advance still linked from an entry with live balance, in rev 6.12?
- **Q5:** is the step approved at all, replacing the documented SQL — after the `clientes` reading of this study.
