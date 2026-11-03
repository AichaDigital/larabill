# AID-1825 — targeted credit exits, entry states and the advance's collection state (rev 5, PROPOSAL)

- **Status:** rev 5 — **proposal, not approved**. Written on 2026-10-31 under an operator authorization limited to drafting (12:02 CET): no gate, no code, no Linear change. The pause of 2026-10-30 stays in force for everything else; `clientes` is paused until it reads this document; the operator decides afterwards what to approve. Nothing here lifts the pause.
- **Ticket:** AID-1825 (blocks `clientes` AID-962 MR A). Rev 4 (`2026-10-30-aid-1825-targeted-credit-exits-design.md`, sha256 `dfa43e3f…`) is preserved unmodified as the record of the gate round 1 input; this file replaces it as the working proposal.
- **Base:** `main` @ `2785375` (v9.1.0 published, `[Unreleased]` empty). Every `file:line` below was re-read on that tree on 2026-10-31.
- **Inputs, in order of authority:** (1) the shared working notes `2026-10-31-credit-and-invoicing-shared-working-notes.md` (sha256 `fc830870…`, 73 lines, the common reference both workstreams accepted); (2) the refined ticket description (a)–(m) as it stood on 2026-10-30 21:08 UTC, a **shared proposal**; (3) the gate round 1 verdict `.gate/aid-1825/spec-r1/out.md` (FAIL, 6 P1 + 3 P2 + 3 suspected); (4) `clientes` design rev15 §5, §6.2–§6.9 (working tree of `clientes`, not on its `origin/main`, unversioned); (5) `STABILITY.md` rules 1–5.
- **Related:** AID-1778 (advance invoices, 8.4/9.0), AID-1817 (`breakdown()`, 9.1.0), AID-1815 (deduction on consumption — separate ticket, NOT in scope), AID-1826 (generic data-migration mechanism, Backlog), AID-962 (`clientes`).

## 0. How to read this document

- **[V]** verified on `2785375`: a fact of the code or the schema, with its locator.
- **[P]** proposed: a design choice of this revision. Every [P] that the operator must approve is also listed in §5, with its rationale and consequences.
- **[T]** to be proven: a claim this document does not establish; §8 lists the proofs and the engines they run on.
- The split between **package guarantee** and **consumer policy** (§4) is **a proposal and is not closed**; the version (minor vs major) is **not decided** (§9 lists the observable changes and the rule each one would have to satisfy, nothing more).

## 1. Intent and the cases it serves

The shared working notes (§3) fix the business cases. Three of them drive this design; the other three are consequences.

- **Case A — issued invoice, partially collected.** One fiscal document; the collection state moves with the allocations. [V] Already served by 9.1: `recomputeCollectionState()` gives PARTIALLY_PAID for 0 < paid < total and PAID at paid ≥ total (`src/Models/Invoice.php:845-851`); `GroupedPaymentService::register()` allocates exactly `amountOutstanding()` under the lock (`src/Services/GroupedPaymentService.php:188-214`). **Nothing in this design changes case A.**
- **Case B — proforma, money received before the operation (an intentional advance).** [V] A proforma never receives allocations: `Ledger::assertPayable()` refuses the PROFORMA serie (`src/Services/Payments/Ledger.php:106-107`), so the receipt lands as a credit entry (`recordRemainder()`, `src/Services/PaymentService.php:600-616`). The money is held for a VAT-subject supply, so an advance invoice must document it (art. 75.Dos LIVA); the later final invoice carries a deduction line for the advance's exact base and tax (`AdvanceInvoiceBuilder::deductionLine()`, `src/Services/AdvanceInvoiceBuilder.php:234-248`; persisted verbatim by `InvoiceService::createInvoiceItem()`, `src/Services/InvoiceService.php:1135`). The end-to-end deduction on a consumption invoice is AID-1815, not this ticket.
- **Case C — top-up proforma (balance recharge).** The whole receipt is the advance; there is no pending operation; consumption happens later (AID-1815). This is the main prepaid flow of `clientes`. It adds one question of its own: how the top-up proforma is closed once the advance is issued (§5, D6).
- **Cases D–F** (money received in error, receipt with unresolved purpose, entry with prior exits, advance later refunded) are the states the entry model of §6.1 must represent without losing money or declaring VAT twice.

The package owns the ledger and its locks, so the protection must live here: `clientes` cannot enforce a rule on a plan it does not compute. The package offers states, transitions and guarantees; **when** to hold, **when** to build an advance and **who** may release are the consumer's decisions (§4).

## 2. Diagnosis, measured on `2785375` [V]

- **D1 — untargeted plans.** `CustomerCredit::plan()` (`src/Services/CustomerCredit.php:466-511`) selects active entries by customer, currency and locked payments; it never reads `advance_invoice_id`. Shared by `apply()` (`:244`) and `refund()` (`:315`). `inferCurrency()` sums every remainder. `PaymentService::allocate()` funds from the payment's own entries through `consumeEntries()` with no filter either — a third untargeted exit path.
- **D2 — two-step surplus.** `recordRemainder()` creates the entry with the payment (`PaymentService.php:600-616`); any hold or link is a later write in another transaction.
- **D3 — refunds without a key.** `refund()` takes no idempotency key; one consumed entry may yield several REFUNDED rows (one per band gap, AID-1778).
- **D4 — the advance stays DRAFT on the collection axis.** `issue()` leaves `status` at DRAFT for every directly issued invoice (`InvoiceService.php:443-448`); `assertRectifiable()` refuses a DRAFT original (`SettlementService.php:506-508`). An issued advance cannot be rectified today.
- **D5 — the advance and its rectificative are counted as debt.** `outstanding()` counts every ISSUED non-proforma invoice in a payable status by `amountOutstanding()` (`CustomerCredit.php:127-144`), with rectificatives netting by sign (`:115-119`); `rectify()` writes the rectificative SENT on the create path (`SettlementService.php:168`) and on the resume path (`:357-363`). So a non-PAID advance (any consumer-stamped payable status) counts as debt and is itself payable, and a refund's rectificative nets the debt: `position()` does not move (gate #5).
- **D6 — link without the payment lock.** `linkAdvanceInvoice()` locks only the advance invoice row (`src/Models/CreditMovement.php:236-239`), checks only the advance side (`:259-281`) and never the entry's prior exits.
- **D7 — issue without the payment lock and without a provenance check.** `issue()` locks proforma → invoice (`InvoiceService.php:330-335`), returns an ISSUED invoice unchanged (`:339-341`) and knows nothing about linkage. The draft the builder creates is an ordinary DRAFT (`AdvanceInvoiceBuilder.php:189-193`), built on the entry's full `amount` (`:152`) after validating only kind, reversal, link, currency and tax group (`:101-129`).
- **D8 — reversal ignores links.** `reverse()` refuses only while an entry has an active exit (`PaymentService.php:351-361`); a linked or reserved entry with no exit is reversed silently.
- **D9 — replay compares the credit kind only when an entry exists.** `replayMatches()` reads the payment's own surplus entry and compares `kind` only if it found one (`PaymentService.php:241-250`); a payment with no remainder has nothing to compare.
- **D10 — no "fulfilled" state for a proforma.** `InvoiceStatus` has CANCELLED and CONVERTED as the only terminal collection states a proforma reaches (`src/Enums/InvoiceStatus.php:20-28`); no code path closes a proforma as satisfied by another document.

## 3. Goals, non-goals, anti-goals

- **G1 — Entry states.** An entry carries one stored state — plain, held, advance-pending — plus its permanent advance link; transitions are single writes under the entry's payment lock.
- **G2 — Intent at birth.** `receive()` and `reverseAllocation()` write the surplus entry's intent in the same transaction that creates it; the intent is immutable and part of the replay payload.
- **G3 — Protected money is consumed only by a call that names it.** No untargeted plan — `apply()`, `inferCurrency()`, `allocate()` and the existing `refund()` — ever touches a held, advance-pending or advance-backed entry. No reversal deactivates one silently.
- **G4 — Targeted refund** over named entries, with an operation key, with a held precondition on request.
- **G5 — The advance is born collected** (PAID with `paid_at`) inside the issue transaction, repaired on re-issue, never issued unlinked.
- **G6 — Neither the advance nor its rectificatives are debt** for `outstanding()` or for payability, whatever collection status a consumer stamped.
- **G7 — `breakdown()` partitions the balance** into held, advance-pending, advance-backed and consumable.
- **G8 — Reads for the consumer:** per-entry state, intent, link and last transition timestamp, plus bounded enumerations.

Non-goals: consuming advance-backed balance as a deduction on a consumption invoice (AID-1815); any policy on when to hold or advance; a history table of transitions; moving money; changing `rectify()`'s artifact, numbering or VeriFactu mapping; any per-consumer configuration key in the package (`clientes` asks for none; the general question for other consumers stays open in the shared notes §4).

Anti-goals (a failure even if it works): held, advance-pending or advance-backed money consumed or deactivated by a call that did not name it — **including the untargeted `refund()` and `reverse()`** (gate #1, #4); VAT declared twice; two refund operations for one key, or one key adopting a different payload; a refund that leaves `position()` unchanged; an advance issued for money that is no longer held or no longer whole; an observable change on a 9.1-reachable ledger state that §9 does not list.

## 4. Boundary — proposed split, NOT closed

The operator decides this split. The table is the proposal; "motivating case" ties each row to §1 or to the shared notes §3.

| Item | Proposed class | Motivating case | Note |
|---|---|---|---|
| (a) hold / release / advance-pending under the payment lock, whole-entry rule | package guarantee | B, C, D, "entry 100 with 80 refunded" | the ledger owns the lock |
| (b) immutable creation intent in the replay payload | package guarantee | D, E; gate #3 | the package owns `replayMatches()` |
| (c) untargeted plans never touch protected money | package guarantee | anti-goal; gate #1 | includes the legacy `refund()` (D4 in §5) |
| (d) targeted refund, `requireHeld` option, domain refusal for an inactive entry | package capability | B, F; gate #2 | the consumer decides when to call it |
| (e) refund operation key | package guarantee | D3 | one operation, one record |
| (f) advance PAID at issue, repaired on re-issue | package guarantee | D4; gate #4 | rectifiability depends on it |
| (g) advance and its rectificatives out of debt and payability | package guarantee | D5; gate #5 | by provenance, not by status (D2 in §5) |
| (h) four-part breakdown | package capability (read) | consumer display | semver consequence in §9 |
| (i) link takes the payment lock first, refuses held, re-checks whole-entry | package guarantee | D6; gate #6 | |
| (j) reversal guard, explicit `abandonHold` | package guarantee | D8; gate #4 | |
| (k) advance-pending → held escape, unlinking a never-issued draft | package capability | §6.9 of `clientes` | |
| (l) provenance marker on the advance draft; `issue()` refuses an unlinked advance draft; lock order payment → proforma → invoice | package guarantee | D7; gate #4, #6 | |
| (m) per-entry reads and enumerations | package capability (read) | consumer sweep | timestamp semantics in §6.10 |
| Closing a top-up proforma once the advance is issued | **open** | C | D6 in §5: package mechanism vs consumer cancellation |
| Staff messages, retry counts, SEPA order, refund method, when to hold or advance | consumer policy | all | never in the package |
| Partial-proforma payment policy (any amount for a top-up; whole-or-nothing for an ordinary proforma; a transfer that arrived is recorded unallocated) | consumer policy (`clientes`, recorded in the shared notes §4) | B, C | the package keeps the 9.1 behaviour it relies on: an unallocated receipt becomes a credit entry |

## 5. Decisions — proposal, rationale, consequences, approval

Each decision states what the operator approves. "Needs approval: yes" means the design does not proceed on that point without an explicit word.

### D1 — The split of §4

- **Proposal:** adopt the table of §4 as the working split.
- **Rationale:** every row marked guarantee is something the consumer cannot enforce because it does not hold the lock or compute the plan; every row marked policy is a decision that differs between consumers.
- **Consequences, larabill:** the guarantees are tested in the package and become `@api` contract; the capabilities are optional calls.
- **Consequences, `clientes`:** its design §6.2–§6.9 maps onto the guarantees as written; its policies stay in its own code and configuration.
- **Needs approval:** yes, row by row where the operator disagrees.

### D2 — An advance is excluded from debt and payability by provenance, not by its collection status

- **Proposal:** an invoice is an **advance** when it carries the provenance marker (`invoices.advance_for_movement_id`, §7) **or** some entry links it through `advance_invoice_id` (legacy advances from 8.4–9.1 carry no marker). `outstanding()` excludes every advance and every rectificative of an advance, whatever their collection status; `Ledger::assertPayable()` refuses an advance (it cannot receive a payment or a credit application). The PAID stamp of (f) is still written: it is what makes the advance rectifiable (D4) and what the consumer displays. A status a consumer stamped is never overwritten by the package, and it no longer matters for debt.
- **Rationale:** gate #5. The money behind an advance is in the ledger; counting the document as debt double-counts it. Excluding by status would depend on a column the consumer may write.
- **Consequences, larabill:** one NULL-safe predicate (`NOT EXISTS`, never `NOT IN` over a nullable column — gate suspected #2), shared by `outstanding()`, a new `Invoice::isAdvance()`, `Invoice::isAdvanceRectificative()` and query scopes; `assertPayable()` gains a refusal. Observable change on 9.1 states (§9, rows 4 and 5).
- **Consequences, `clientes`:** its debt list (§8 of its design) can apply the scope instead of re-deriving; its "issued, not PAID" case 3b stays a repair of (f), not a debt.
- **Needs approval:** yes (it changes what `outstanding()` returns for a legacy advance a consumer stamped SENT).

### D3 — 9.1 data that violates a new invariant

- **Proposal:** no backfill in the migration (operator decision 2026-10-30, kept). Every existing entry is plain with intent unknown (`creation_intent` NULL); every existing advance keeps its collection status until the consumer runs the documented SQL of §7.3, which stamps PAID on issued advances linked to an active entry and copies the provenance marker from the link. The package tolerates both legacy shapes: debt exclusion works by link (D2) before the SQL runs; rectifiability needs the SQL. An entry whose linked advance was issued before this release and that already has exits (the gate #6 shape) is **not** repaired: it is listed by a new diagnostic command and left to the consumer (precedent: `larabill:diagnose-price-overlaps`).
- **Rationale:** `STABILITY.md` rule 2 for a minor; AID-1826 is the generic mechanism and is not ready.
- **Consequences, larabill:** the exact SQL is in this document (§7.3) and is exercised twice on both engines by the upgrade-path test (gate #8); a diagnostic command is added.
- **Consequences, `clientes`:** `origin/main` creates no advances (measured on 2026-10-30), so it has no rows to fix; the SQL is a documented step of its adoption anyway.
- **Needs approval:** yes, on the "list, do not repair" choice for entries with prior exits.

### D4 — The untargeted `refund()` is narrowed to plain entries, not deprecated

- **Proposal:** the existing `refund()` plans **only plain entries** (not held, not advance-pending, not advance-backed), like `apply()`. It is kept, not deprecated.
- **Rationale:** gate #1: a deprecation keeps the hole open for a full major. A refund that does not name its entries cannot tell a surplus from an advance, and only the latter needs a rectificative; letting it eat an advance silently is the anti-goal itself.
- **Consequences, larabill:** observable change on a 9.1 ledger that holds advance-backed entries (§9 row 2); `InsufficientCreditException::forBalance()` reports the plain balance.
- **Consequences, `clientes`:** its panel refund is rerouted through holds and the targeted call anyway (its §6.2); the untargeted call stays available for consumers with plain balance only.
- **Needs approval:** yes (it is the one place where this revision contradicts rev 4's call 2).

### D5 — A stated intent with no surplus entry is inert and outside the replay comparison

- **Proposal:** when the payment leaves no remainder, the intent is not stored and the replay compares nothing about it: a later call with the same key and another intent is a replay, not a conflict. The package documents that the only durable record of an intent is the entry it was written on. The alternative — storing the intent on the payment row — is rejected: it would make the payment row carry a fact about an entry that does not exist.
- **Rationale:** `replayMatches()` compares the credit kind only when an entry exists (`PaymentService.php:241-250`); the consumer's own form check already treats "no surplus entry" as "left as balance" (its §6.2).
- **Consequences, larabill:** one documented rule, one test (same key, remainder zero, two intents → replay).
- **Consequences, `clientes`:** none beyond what its §6.2 already states.
- **Needs approval:** yes (the alternative changes the schema of `grouped_payments`).

### D6 — Closing a top-up proforma once the advance is issued

- **Option A (package mechanism):** a new terminal collection status for proformas, `FULFILLED`, and a method `InvoiceService::fulfilProforma(Invoice $proforma, Invoice $advance)` that, under the proforma lock, requires an ISSUED advance of the same customer and currency and moves the proforma DRAFT/SENT → FULFILLED storing `fulfilled_by_invoice_id`. Conversion of a FULFILLED proforma is refused. The proforma is never converted.
- **Option B (consumer solution):** `clientes` cancels the proforma with a reason and keeps the link to the advance on its side; the package adds nothing.
- **My proposal:** A. The proforma is a package document; a satisfied proforma recorded as CANCELLED misstates it in every report built on the package's statuses, and other consumers with a prepaid flow will meet the same need.
- **Consequences, larabill (A):** an enum case (additive), a nullable column, one method, a refusal in `convertProformaToInvoice()`, snapshots regenerated. **(B):** nothing.
- **Consequences, `clientes` (A):** its §6.3 "closed as fulfilled" gets a package call. **(B):** a reason code and a local link column.
- **Needs approval:** yes. The operator asked not to decide this yet; both options stay in the document until decided.

### D7 — Whole-entry rule (option A of 2026-10-30), restated

- **Proposal:** the transition to advance-pending (from held, or at creation) is refused unless the entry has no active exit (`remaining() == amount` over active exits), evaluated under the payment lock in the same write; a reversed exit does not count, a REFUNDED exit blocks for good. `AdvanceInvoiceBuilder::forCreditEntry()` and `linkAdvanceInvoice()` re-check it (builder: before building; link: under the payment lock). An entry whose linked advance is ISSUED never returns to advance-pending.
- **Rationale:** gate #6; the alternative (a remainder with its own identity) was rejected by the operator as a redesign of the ledger.
- **Consequences, larabill:** the builder keeps building on the full `amount` (`AdvanceInvoiceBuilder.php:152`), now justified because the entry is whole; three new refusals.
- **Consequences, `clientes`:** its staff message «devuelve el resto o emite primero el anticipo por el total» stands.
- **Needs approval:** confirmation only (decided on 2026-10-30).

### D8 — Lock order payment → proforma → invoice; `issue()` takes the payment lock for an advance draft

- **Proposal:** `issue()` reads the provenance marker unlocked; when present, it reads the origin entry's `grouped_payment_id` (immutable), opens the transaction with the payment row X-lock, then proforma → invoice as today, re-checks under the locks that the entry is ACTIVE, linked to this invoice and that its payment is POSTED, then issues and in the same transaction stamps PAID with `paid_at` and clears advance-pending. On the repair path (already ISSUED) the same re-check applies, the stamp is written if missing, and only advance-pending → advance-backed is cleared (a hold is kept). `reverse()` takes the payment lock first as today, so issue and reversal serialize on the payment.
- **Rationale:** gate #4. The rev 4 "accepted race" had no valid recovery (`annul()` refuses a DRAFT, the raced advance would be PAID).
- **Consequences, larabill:** `issue()` gains a conditional first lock; an ordinary invoice (no marker) is unchanged; `linkAdvanceInvoice()` moves the payment lock before the invoice lock (D6 of §2); test R27 is rewritten to assert serialization.
- **Consequences, `clientes`:** its §6.9 reconcile relies on this order; nothing to add.
- **Needs approval:** yes, together with the proof list of §8 — closed on paper is not closed.

### D9 — The read side (m) lives in the package, and the transition timestamp has fixed semantics

- **Proposal:** `CreditMovement` exposes `state`, `creation_intent`, `advance_invoice_id`, `state_changed_at`; `CustomerCredit` gains `heldEntries()` and `advancePendingEntries()` (bound, ordered by `occurred_at` then `id`, cursor-paginated by `id`). `state_changed_at` is written by the database clock, `datetime(6)` (microseconds on MySQL 8.4 and MariaDB 11.4 [T]), set at creation and on hold, release, advance-pending and the issue's clear; a link does not move it.
- **Rationale:** the consumer's stale-draft comparison (its §6.9 case 1) depends on a strict timestamp comparison.
- **Consequences, larabill:** additive surface; one test per transition asserting whether the timestamp moved.
- **Consequences, `clientes`:** its port wraps these reads.
- **Needs approval:** on the timestamp semantics only.

### D10 — `reverse()` refuses protected entries; `abandonHold` is explicit

- **Proposal:** `reverse()` refuses while any entry of the payment is held, advance-pending, or advance-backed and not fully refunded with its rectificatives (a new domain exception). With `abandonHold: true`, a payment whose non-plain entries are all held **and unlinked** (eligibility by `advance_invoice_id` NULL, never by `breakdown()`) is reversed and its held entries are deactivated with it.
- **Rationale:** gate #4; `clientes` §6.2 "anular cobro erróneo".
- **Consequences, larabill:** observable change on a 9.1 ledger with linked entries (§9 row 6).
- **Consequences, `clientes`:** `reversePayment()` catches the new exception (today only `InsufficientCreditException`).
- **Needs approval:** yes.

### D11 — Version

- **Proposal:** none. §9 lists every observable change on a 9.1-reachable state with the `STABILITY.md` rule it would have to satisfy; the classification is decided after the behaviour is approved.
- **Needs approval:** not yet.

## 6. Design (proposed) [P unless marked]

### 6.1 Entry model

An entry (`credit_movements`, kinds SURPLUS and TOP_UP) carries:

- `state` — stored, `CreditEntryState` enum: `Plain = 0`, `Held = 1`, `AdvancePending = 2`. Exits always `Plain`.
- `creation_intent` — stored, immutable, `CreditEntryIntent` enum: `Hold`, `AdvancePending`; NULL = none stated (every 9.1 entry).
- `advance_invoice_id` — existing permanent link (survives reversal).
- `state_changed_at` `datetime(6)`, `state_changed_by` (nullable `userIdColumn`).

Derived classification, decided in this order: **held** (state Held, linked or not) ⊎ **advance-pending** (state AdvancePending; includes linked-unissued) ⊎ **advance-backed** (Plain ∧ linked ∧ advance ISSUED) ⊎ **consumable** (the rest). Pending is decided before backed, so an ISSUED-not-PAID entry waiting for the repair is pending.

Invariants, each checked under the entry's payment lock in the write that could break it:

- **I1 whole entry:** AdvancePending is entered only when `remaining() == amount` over active exits (D7).
- **I2 no exit from pending:** an AdvancePending entry admits no exit of any kind.
- **I3 link ⇒ state:** a plain hold on a linked unissued entry is refused; the only hold of such an entry is the escape (k), which unlinks in the same write. Held ∧ linked ⇒ the advance is ISSUED. AdvancePending → Held is refused while the linked advance is ISSUED but not PAID (it waits for the repair).
- **I4 issued is final:** an entry whose linked advance is ISSUED never returns to AdvancePending from any state; releasing its hold restores advance-backed.
- **I5 idempotent transitions:** a transition to the state the entry already has is a no-op that leaves `state_changed_at` and `state_changed_by` untouched.

### 6.2 Transitions (single writes under the payment lock)

New `@api` methods on `CustomerCredit` (unbound, `OutermostTransaction::assert()` first; the entry's `grouped_payment_id` is read before the transaction; the transaction opens with the payment row X-lock; the entry is re-read under it; one conditional UPDATE):

| Method | From | To | Refused when |
|---|---|---|---|
| `hold($entry, $by)` | Plain, AdvancePending | Held | exit; reversed; Plain ∧ linked ∧ advance unissued (I3); AdvancePending ∧ linked ∧ advance ISSUED-not-PAID (I3); AdvancePending ∧ linked-unissued unless called as the escape (k) |
| `release($entry, $by)` | Held | Plain (advance-backed if linked) | exit; reversed |
| `markAdvancePending($entry, $by)` | Plain, Held | AdvancePending | exit; reversed; linked (any); active exit present (I1); advance ever ISSUED (I4) |
| `escapeAdvancePending($entry, $by)` (k) | AdvancePending | Held | exit; reversed; linked to an ISSUED advance (never unlinked); otherwise unlinks a never-issued draft in the same write |

Every refusal is a named constructor of `InvalidCreditMovementException` (or a new `CreditEntryTransitionException`), so the consumer can classify it. `markAdvancePending()` on an entry already AdvancePending, and `escapeAdvancePending()` on an entry already Held, are no-ops (I5): the consumer's died-halfway re-runs rely on it.

### 6.3 Intent at birth (b)

- `PaymentService::receive()` and `reverseAllocation()` gain a trailing `?CreditEntryIntent $creditIntent = null`, carried through `ReceiveRequest`. `recordRemainder()` writes `creation_intent` and the matching initial `state` on the one entry it creates; with intent AdvancePending the whole-entry rule holds trivially (a new entry has no exits). In the credit-funded branch of `reverseAllocation()` no entry is created and the argument is inert, documented.
- **Replay:** `replayMatches()` compares `creation_intent` **when the payment's own surplus entry exists** (the `allocation_id` NULL entry it already reads, `PaymentService.php:241-250`), against the recorded creation intent, never the current state; a different intent is a conflict. With no entry, nothing is compared (D5). `ReplayPolicy::Legacy` (card path) does not take an intent.
- The creation intent is exposed read-only on the entry (D9).

### 6.4 Plans (c) — what each call may touch

| Call | consumable | held | advance-pending | advance-backed |
|---|---|---|---|---|
| `apply()`, `inferCurrency()` | yes | no | no | no |
| `allocate()` via `consumeEntries()`; `GroupedPayment::spendableCredit()` (new; `availableCredit()` keeps its 9.1 meaning) | yes | no | no | no |
| `refund()` (existing, untargeted) — **D4** | yes | no | no | **no** |
| `refundEntries()` (new, targeted) | named | named | **refused** (I2) | named |

One private predicate, evaluated under the payment locks; `sourcePaymentIds()` may pre-filter, the plan filters again under the locks.

### 6.5 Targeted refund (d) and the operation key (e)

```php
public function refundEntries(
    string $billableUserId,
    array $entryIds,                 // non-empty list<string>, no duplicates
    FixedDecimal $amount,
    string $currency,
    DateTimeInterface $refundedAt,
    PaymentMethod $method,
    string $operationKey,            // names ONE refund operation
    bool $requireHeld = false,       // (d): every named entry must still be Held, checked under the lock
    ?string $reference = null,
    ?string $recordedBy = null,
): Collection                        // every REFUNDED exit of the operation, tape order
```

1. Validate amount, key, list; sort the ids; read the named entries' `grouped_payment_id`s outside the transaction (an unknown id is refused with zero writes).
2. Transaction; first statements lock those payments by id ascending.
3. **Adoption after the locks:** `credit_refund_operations` by key; same payload (customer, currency, amount, sorted ids, `refunded_at` to the second UTC, method, reference, `require_held`) → return every exit carrying that `refund_operation_id`; any difference → `IdempotencyConflictException::forKey()`.
4. Validate each named entry under the locks: same customer and currency; an active ENTRY of a POSTED payment — an inactive entry (payment reversed) is a dedicated domain refusal, `CreditEntryInactiveException`, never a generic error; not AdvancePending (I2); with `requireHeld`, state Held. Zero writes on any violation.
5. Plan FIFO over the named entries only, band placement unchanged; insufficient → `InsufficientCreditException::forBalance()`.
6. Insert the operation row, then the exits with `refund_operation_id`.
7. **Race backstop [T]:** two same-key calls over entries of different payments do not serialize on the locks; the UNIQUE on `operation_key` makes the loser fail (SQLSTATE 23000); caught outside the transaction, the winner is re-read and compared. Exception classification, collation of the key and the disjoint-payment case are proven on both engines before this is claimed (gate suspected #1).

No per-entry amounts (one amount over a named set, FIFO). A caller that wants "X from A and Y from B" makes two operations.

### 6.6 The advance: provenance (l), PAID at issue (f), repair, refusals

- `AdvanceInvoiceBuilder::forCreditEntry()` writes `advance_for_movement_id = entry.id` on the draft and refuses an entry with an active exit (I1) or in state Held (the consumer marks pending first).
- `issue()` on a draft with the marker follows D8: payment lock → proforma → invoice; under the locks it requires the origin entry ACTIVE, `advance_invoice_id = this invoice`, its payment POSTED; otherwise `InvoiceNotIssuableException::advanceUnlinked()` / `::advanceEntryReversed()`, zero writes. Then it issues, stamps `status = PAID`, `paid_at = issue date` (ticket (f); rev 4 said `entry.occurred_at` — the ticket's wording is kept, the operator may prefer the receipt date) and clears AdvancePending → Plain in the same transaction. `recomputeCollectionState()` never touches it afterwards (no allocation rows, guard at `Invoice.php:830-836`).
- **Repair path:** an already ISSUED advance returns through the same re-check; the stamp is written if missing; only AdvancePending → Plain is cleared; a Held entry keeps its hold.
- A consumer-stamped collection status on an advance is never overwritten and never matters for debt (D2).
- `SettlementService::rectify()` of an advance: unchanged artifact; the rectificative is excluded from debt by D2 on the create path and on the resume path (`SettlementService.php:168`, `:357-363` both stamp SENT and stay so).

### 6.7 Debt and payability (g, D2)

- `Invoice::isAdvance()`: marker set **or** linked by some entry. `Invoice::isAdvanceRectificative()`: `rectifies_invoice_id` names an advance. Scopes `excludingAdvances()`, `excludingAdvanceRectificatives()`, built on `NOT EXISTS` over `credit_movements.advance_invoice_id` and `invoices.advance_for_movement_id` (NULL-safe).
- `outstanding()` applies both scopes. `Ledger::assertPayable()` refuses an advance with a new `GroupedPaymentValidationException::advanceNotPayable()`.
- Result: a refund of R of an advance-backed entry plus its partial rectificative lowers `position()` by exactly R.

### 6.8 Breakdown (h)

`CreditBreakdown` gains `held` and `advancePending` (trailing, zero defaults); `advanceBacked` becomes "linked AND issued"; `consumable` is what `apply()` may plan. `total()` = four parts = `balance()`. `CreditEntryRemainder` gains `state` and `creationIntent`. On a 9.1 ledger with no reservation and no unissued link, every 9.1 value is unchanged; on a 9.1 ledger with an entry linked to an **unissued** draft, `advanceBacked` moves to `advancePending` — an observable change (§9 row 7).

### 6.9 Link (i)

`linkAdvanceInvoice()` reads the entry's `grouped_payment_id` before the transaction, opens with the payment row X-lock, then the advance invoice lock (keeps it before the conflict check and the write, the consumer's draft deletions serialize on it); refuses a Held entry and an entry with an active exit (I1); links a Plain or AdvancePending entry without changing its state (the issue clears pending, not the link). Caller contract of 9.0 kept; the docblock states that a wrapper already holding an invoice lock inverts the order.

### 6.10 Reads (m, D9)

Per entry: `state`, `creation_intent`, `advance_invoice_id`, `isActive()`, `state_changed_at`. Enumerations: `CustomerCredit::heldEntries(?string $afterId = null, int $limit = 100)` and `advancePendingEntries(...)`. `state_changed_at` moves on creation, hold, release, advance-pending and the issue's clear; not on link, not on issue when nothing clears.

## 7. Schema and upgrade

### 7.1 Migration `add_credit_entry_states_and_refund_operations` — five pieces in one MR

`.php` + `.php.stub` (bootstrap by copy) + `$migrationOrder` entry `'055'` + manifest entry via `bin/sync-upgrade-manifest` (run **after** the CHANGELOG promotion, because the script derives the release from the newest promoted heading, `bin/sync-upgrade-manifest:36-45` — gate #8) + bumped install counts. Every DDL step guarded by introspection.

- `credit_movements`: `state` unsignedTinyInteger NOT NULL default 0; `creation_intent` unsignedTinyInteger nullable; `state_changed_at` datetime(6) nullable (NULL on legacy rows = "unknown", documented; new rows always set); `state_changed_by` nullable `userIdColumn`; `refund_operation_id` uuid nullable FK → `credit_refund_operations.id`, indexed; index `(billable_user_id, currency, state)`.
- `credit_refund_operations`: `id` uuid v7; `operation_key` string(191) UNIQUE; `billable_user_id` (`userIdColumn`); `currency` char(3); `amount` bigint positive with the money-domain CHECK; `entry_ids` json; `require_held` boolean; `refunded_at` datetime; `method` tinyint; `reference` nullable; `recorded_by` nullable; timestamps.
- `invoices`: `advance_for_movement_id` uuid nullable FK → `credit_movements.id` (`restrictOnDelete`), indexed; and, if D6 option A is approved, `fulfilled_by_invoice_id` uuid nullable FK → `invoices.id` plus the `FULFILLED` enum case.
- No data changes (D3).

### 7.2 What legacy rows mean

A 9.1 entry: `state = 0`, `creation_intent = NULL`, `state_changed_at = NULL`. A 9.1 advance: no marker; recognised as an advance by its link (D2). A 9.1 issued advance at collection DRAFT: not rectifiable until §7.3 runs.

### 7.3 Documented upgrade SQL (consumer-run, portable, idempotent) [T: executed twice on MySQL 8.4 and MariaDB 11.4 by the upgrade-path test]

```sql
-- 1. Inspect first.
SELECT i.id, i.fiscal_number, i.status, m.id AS entry_id, m.occurred_at
FROM invoices i
JOIN credit_movements m ON m.advance_invoice_id = i.id AND m.reversed_at IS NULL
WHERE i.invoice_document_status = 2            -- ISSUED
  AND (i.advance_for_movement_id IS NULL OR i.status = 0 OR i.paid_at IS NULL);

-- 2. Copy the provenance marker from the link (no-op when already set).
UPDATE invoices
SET advance_for_movement_id = (
    SELECT m.id FROM credit_movements m
    WHERE m.advance_invoice_id = invoices.id AND m.reversed_at IS NULL
    ORDER BY m.occurred_at, m.id LIMIT 1)
WHERE advance_for_movement_id IS NULL
  AND EXISTS (SELECT 1 FROM credit_movements m
              WHERE m.advance_invoice_id = invoices.id AND m.reversed_at IS NULL);

-- 3. Stamp PAID on issued advances still at collection DRAFT.
UPDATE invoices
SET status = 2, paid_at = COALESCE(paid_at, invoice_date)   -- 2 = PAID; the issue date, as (f) states
WHERE invoice_document_status = 2
  AND status = 0
  AND EXISTS (SELECT 1 FROM credit_movements m
              WHERE m.advance_invoice_id = invoices.id AND m.reversed_at IS NULL);
```

The enum values (`invoice_document_status` ISSUED = 2, `status` DRAFT = 0 / PAID = 2) are confirmed against the enums before the SQL is published; a second run changes zero rows. The SQL does not touch an advance whose entry has prior exits (D3): `larabill:diagnose-advance-entries` lists them.

## 8. Concurrency: what is closed on paper and what must be proven [T]

Every write that changes what can be spent — `hold()`, `release()`, `markAdvancePending()`, `escapeAdvancePending()`, `linkAdvanceInvoice()`, `apply()`, `allocate()`, `refund()`, `refundEntries()`, `reverse()`, `reverseAllocation()`, and `issue()` of an advance draft — takes the payment row lock of every entry it reads or writes as its first statements, payments by id ascending, before any proforma or invoice lock. Proof list, on MySQL 8.4 and MariaDB 11.4, in `tests/Concurrency` with the anti-skip guard and a sensitivity proof per case (`ad-concurrency-gate`):

1. `issue()` vs `reverse()` on the same payment: exactly one of {advance PAID over an active entry, reversal refused} or {reversed, issue refused}; never a PAID advance over a reversed entry.
2. `issue()` vs `escapeAdvancePending()`: never an ISSUED advance unlinked from its entry.
3. `linkAdvanceInvoice()` vs `hold()`: never a linked Held unissued entry (I3).
4. `linkAdvanceInvoice()` vs `apply()` (R27 rewritten): serialization, no 40001.
5. `markAdvancePending()` vs `refundEntries()` on the same entry: never an AdvancePending entry with an exit (I2).
6. Two same-key `refundEntries()` over overlapping and over disjoint payment sets; different payload; key case variants (collation).
7. `reverse(abandonHold: true)` vs `linkAdvanceInvoice()`: never a reversed payment with a linked entry.

## 9. Observable changes on 9.1-reachable states — classification NOT decided

| # | Change | Old (9.1) | Rule it would need |
|---|---|---|---|
| 1 | `apply()`, `inferCurrency()`, `allocate()` skip advance-backed entries | consumed FIFO, VAT twice | rule 5 Fixed (data-integrity defect) |
| 2 | untargeted `refund()` skips advance-backed entries (D4) | consumed FIFO | rule 5 Fixed, or rule 1 if read as a narrowing |
| 3 | issuing a linked advance stamps PAID; re-issue repairs | collection DRAFT, unrectifiable | rule 5 Fixed |
| 4 | advances and their rectificatives out of `outstanding()` (D2) | counted / netted | rule 5 Fixed |
| 5 | an advance is not payable (D2) | payable if in a payable status | narrowing — rule 1 candidate |
| 6 | `issue()` refuses an unlinked or reversed-entry advance draft; `reverse()` refuses protected entries (D8, D10) | both allowed | narrowing — rule 1 candidate |
| 7 | `breakdown()` identity: four parts; linked-unissued counts as pending | `consumable + advanceBacked == balance()` | additive if the two new parts are zero on every 9.1 state **except** the linked-unissued case |
| 8 | `linkAdvanceInvoice()` refuses an entry with an active exit; lock order changes | allowed | narrowing — rule 1 candidate; 9.0 already narrowed this method by qualified exception |

Exposure measured: advances exist since 8.4 (2026-10-26); `clientes` `origin/main` creates none; no other consumer is known to create them. That measurement is an input to the version decision, not the decision.

## 10. Tests (TDD, RED first) — including the gate's counterexamples (#9)

- **States and transitions:** every row of §6.2, every refusal, every no-op (timestamp untouched), I1–I5 each with a RED test.
- **Intent:** replay with another intent → conflict when an entry exists; → replay when none (D5); `reverseAllocation()` credit-funded branch inert.
- **Plans:** mixed ledger of the four kinds; a `refund()` that skips held but consumes an unnamed advance-backed entry **fails** (gate #9a); `InsufficientCreditException` reports the plain balance; `spendableCredit()` vs `availableCredit()`.
- **Targeted refund:** named only; FIFO; split per band; refusals (pending, foreign, exit, inactive → `CreditEntryInactiveException`, unknown id, `requireHeld` violated); adoption returns all exits; conflict on different payload; adoption after the locks.
- **Advance:** marker written by the builder; builder refuses active exit and Held; `issue()` → PAID + `paid_at` + pending cleared, in one transaction (a failure injected after the ISSUED write rolls everything back); repair path; refusals unlinked / reversed; an `issue()` that stamps PAID while a concurrent `reverse()` reverses the entry **fails** (gate #9b).
- **Debt:** `outstanding()` excludes a SENT-stamped legacy advance (gate #9c) and its rectificatives on both paths; `assertPayable()` refuses an advance; `position()` drops by exactly R.
- **Breakdown:** partition sums to `balance()`; 9.1 regression on a ledger without reservations; the linked-unissued case on each side of the issue.
- **Reverse:** refusals; `abandonHold` eligibility by link; a Held advance-backed entry blocks.
- **Reads:** timestamp moves / does not move per transition; enumerations bounded and ordered.
- **Migration:** upgrade-path test seeding a 9.1 ledger (plain entries; an issued advance at collection DRAFT; an advance stamped SENT by the consumer; an entry linked to an unissued draft): after migrate every row is unchanged except the new columns at their defaults; after §7.3 once, PAID with `paid_at`, marker copied; after §7.3 twice, zero changes; `InstallMysql` counts; both engines.
- **Contract:** snapshots via `bin/sync-contract-snapshots`; `SurfaceTaxonomyTest`; `docs/api-surface.md`.

## 11. Gate round 1 — adjudication

| # | Finding | Decision | Landed in |
|---|---|---|---|
| 1 | P1 untargeted `refund()` consumes advances | accepted | D4, §6.4 |
| 2 | P1 consumer refund workflow needs hold on advance-backed + held precondition | accepted | §6.2 (`hold()` on advance-backed), §6.5 `requireHeld`, §6.8 overlap rule |
| 3 | P1 replay loses creation intent; no-remainder case undefined | accepted | §6.1 `creation_intent`, §6.3, D5 |
| 4 | P1 issue/reversal race has no valid recovery | accepted | D8, D10, §8 cases 1–2 |
| 5 | P1 SENT advance counted by `outstanding()` | accepted | D2, §6.7 |
| 6 | P1 consumed entry linked and issued for its original gross | accepted | D7, I1, §6.6, §6.9 |
| 7 | P2 a 9.1-reachable refusal missing from the classification | accepted | §9 (all eight rows listed; classification deferred) |
| 8 | P2 migration/SQL evidence insufficient; manifest sequencing | accepted | §7.1 sequencing, §7.3 exact SQL, §10 twice on both engines |
| 9 | P2 tests leave consequential states unspecified | accepted | §10 (three counterexamples as RED tests) |
| S1 | refund-key race needs engine proof | accepted | §6.5 step 7 [T], §8 case 6 |
| S2 | `NOT IN` over a nullable column | accepted | §6.7 `NOT EXISTS` |
| S3 | indirect consumers of exits not exhaustively audited | open | the builder audits every reader of `consumes_movement_id` and `advance_invoice_id` before the first MR; listed in §12 |

## 12. Left to the builder

- Names of private helpers, exception constructors and migration constants; whether `sourcePaymentIds()` pre-filters.
- The exact shape of the `NOT EXISTS` predicates, within "portable, NULL-safe, no `UPDATE … JOIN`".
- The audit of every reader of `consumes_movement_id`, `advance_invoice_id` and `reversed_at` in `src/` (gate S3), recorded in the plan.
- Task split, each task landing with its RED test first.

## 13. What this revision does not do

- It does not approve anything: every [P] waits for the operator; D1–D11 name what each approval covers.
- It does not run the gate: the next gate round reads this file after the operator's decisions are written into it.
- It does not decide the version (D11, §9).
- It does not touch rev 4, the ticket, Linear or code.
